<?php
/**
 * ITPMS — Projects API
 * A small REST-style endpoint consumed by assets/js/dashboard.js via fetch()/AJAX.
 *
 *   GET    projects.php            -> list all projects (no history, for tables/cards)
 *   GET    projects.php?id=PRJ-1   -> single project + full progress history (for the view modal)
 *   GET    projects.php?export=1&format=pptx -> export the fixed 10-slide MANCOM report
 *   POST   projects.php            -> create a project        (JSON body)
 *   PUT    projects.php?id=PRJ-1   -> update a project         (JSON body)
 *   DELETE projects.php?id=PRJ-1   -> delete a project
 */

// locate config.php in a few likely places (this repo can be used from different working folders)
$configCandidates = [
    __DIR__ . '/config.php',
    __DIR__ . '/../config.php',
    __DIR__ . '/../../ITPMS/config.php',
    __DIR__ . '/../ITPMS/config.php',
    __DIR__ . '/../../config.php',
];
$configFound = null;
foreach ($configCandidates as $c) {
    if (file_exists($c)) { $configFound = $c; break; }
}
if (!$configFound) {
    http_response_code(500);
    echo 'Configuration file config.php not found. Searched: ' . implode(', ', $configCandidates);
    exit;
}
require_once $configFound;

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$id     = isset($_GET['id']) ? trim($_GET['id']) : null;

$VALID_STATUSES  = ['Completed', 'Ongoing', 'Onhold', 'Cancelled', 'Not Started'];
$VALID_PRIORITIES = ['Low', 'Medium', 'High', 'Critical'];

function read_json_body() {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function today() {
    return date('Y-m-d');
}

function next_project_id(PDO $pdo) {
    $stmt = $pdo->query("SELECT id FROM projects");
    $max = 999;
    foreach ($stmt->fetchAll() as $row) {
        if (preg_match('/PRJ-(\d+)/', $row['id'], $m)) {
            $max = max($max, (int) $m[1]);
        }
    }
    return 'PRJ-' . ($max + 1);
}

function fail(int $code, string $message): void {
    http_response_code($code);
    echo json_encode(['error' => $message]);
    exit;
}

/**
 * Locate the fixed MANCOM report template (a static 10-slide .pptx).
 * Looks for the known filename first, then falls back to any .pptx in the templates dir.
 */
function find_mancom_template(string $templatesDir): ?string {
    $candidates = [
        $templatesDir . '/IT_DEPARTMENT_MANCOM_REPORT.pptx',
        $templatesDir . '/IT_DEPARTMENT_MANCOM_REPORT_2_0.pptx',
    ];
    foreach ($candidates as $c) {
        if (file_exists($c)) return $c;
    }

    $matches = glob($templatesDir . '/IT DEPARTMENT MANCOM REPORT*.pptx');
    if ($matches && count($matches)) {
        usort($matches, function ($a, $b) { return filemtime($b) - filemtime($a); });
        return $matches[0];
    }

    $all = glob($templatesDir . '/*.pptx');
    if ($all && count($all)) {
        usort($all, function ($a, $b) { return filemtime($b) - filemtime($a); });
        return $all[0];
    }

    return null;
}

/**
 * Slide 4 style: label and number share ONE run, e.g. "<a:t>Completed Projects: 6</a:t>".
 * Replaces the trailing number after "$label: " while leaving the label text untouched.
 */
function pptx_replace_inline_count(string $xml, string $label, $value): string {
    $pattern = '/(<a:t>' . preg_quote($label, '/') . ':\s*)\d+(<\/a:t>)/';
    $replaced = preg_replace($pattern, '${1}' . (int) $value . '${2}', $xml, 1);
    return $replaced !== null ? $replaced : $xml;
}

/**
 * Slide 6 style: a table cell holding the status label, immediately followed
 * (with no other <a:t> in between) by the cell holding its count.
 */
function pptx_replace_table_count(string $xml, string $label, $value): string {
    $pattern = '/(<a:t>' . preg_quote($label, '/') . '<\/a:t>.*?<a:t>)\d+(<\/a:t>)/s';
    $replaced = preg_replace($pattern, '${1}' . (int) $value . '${2}', $xml, 1);
    return $replaced !== null ? $replaced : $xml;
}

switch ($method) {

    /* ---------------------------------------------------------- GET */
    case 'GET':
        if ($id) {
            $stmt = $pdo->prepare("SELECT * FROM projects WHERE id = ?");
            $stmt->execute([$id]);
            $project = $stmt->fetch();
            if (!$project) fail(404, 'Project not found.');

            $hist = $pdo->prepare("SELECT entry_date AS date, progress, notes FROM progress_history WHERE project_id = ? ORDER BY entry_date ASC");
            $hist->execute([$id]);
            $project['history'] = $hist->fetchAll();
            $project['budget']  = (float) $project['budget'];
            $project['progress'] = (int) $project['progress'];

            echo json_encode($project);
        } else {
            $rows = $pdo->query("SELECT * FROM projects ORDER BY created_at ASC")->fetchAll();
            foreach ($rows as &$r) {
                $r['budget']   = (float) $r['budget'];
                $r['progress'] = (int) $r['progress'];
            }
            unset($r);

            // Combined PPT export: projects.php?export=1&format=pptx
            // Produces the fixed 10-slide MANCOM report from a static template.
            // Only two slides carry live data (project status counts); every other
            // slide (Objectives, Org Chart, Monthly Highlights, Department
            // Contribution, Issues/Concerns, Action Plans) is copied byte-for-byte
            // from the template, so nothing about its design/branding can drift.
            if (isset($_GET['export']) && isset($_GET['format']) && $_GET['format'] === 'pptx') {
                if (!class_exists('ZipArchive')) {
                    http_response_code(500);
                    echo "The PHP zip extension is required to export the report. Please enable it on your server.";
                    exit;
                }

                $templatesDir = __DIR__ . '/assets/templates';
                $template = find_mancom_template($templatesDir);

                if (!$template) {
                    http_response_code(500);
                    echo "MANCOM report template (.pptx) not found in: {$templatesDir}. Please place it there.";
                    exit;
                }

                // Tally live counts per status for the two data-driven slides:
                //   Slide 4 — "Current Workload Summary"
                //   Slide 6 — "IT Project Performance Dashboard" table
                $statusCounts = ['Completed' => 0, 'Ongoing' => 0, 'Onhold' => 0, 'Not Started' => 0, 'Cancelled' => 0];
                foreach ($rows as $p) {
                    $s = $p['status'] ?? '';
                    if (isset($statusCounts[$s])) $statusCounts[$s]++;
                }
                $completedCount  = $statusCounts['Completed'];
                $ongoingCount    = $statusCounts['Ongoing'];
                $onHoldCount     = $statusCounts['Onhold'];
                $notStartedCount = $statusCounts['Not Started'];

                $tmpFile = tempnam(sys_get_temp_dir(), 'mancom_') . '.pptx';
                if (!copy($template, $tmpFile)) {
                    http_response_code(500);
                    echo "Could not prepare the report file for export.";
                    exit;
                }

                try {
                    $zip = new ZipArchive();
                    if ($zip->open($tmpFile) !== true) {
                        throw new RuntimeException('Could not open the report template.');
                    }

                    // Slide 4: lines like "Completed Projects: 6" — label + number in one run.
                    $slide4 = $zip->getFromName('ppt/slides/slide4.xml');
                    if ($slide4 !== false) {
                        $slide4 = pptx_replace_inline_count($slide4, 'Completed Projects', $completedCount);
                        $slide4 = pptx_replace_inline_count($slide4, 'Ongoing Projects', $ongoingCount);
                        $slide4 = pptx_replace_inline_count($slide4, 'On Hold Projects', $onHoldCount);
                        $slide4 = pptx_replace_inline_count($slide4, 'Not Started Projects', $notStartedCount);
                        $zip->addFromString('ppt/slides/slide4.xml', $slide4);
                    }

                    // Slide 6: dashboard table — status-label cell, followed by its count cell.
                    $slide6 = $zip->getFromName('ppt/slides/slide6.xml');
                    if ($slide6 !== false) {
                        $slide6 = pptx_replace_table_count($slide6, 'Completed', $completedCount);
                        $slide6 = pptx_replace_table_count($slide6, 'Ongoing', $ongoingCount);
                        $slide6 = pptx_replace_table_count($slide6, 'On Hold', $onHoldCount);
                        $slide6 = pptx_replace_table_count($slide6, 'Not Started', $notStartedCount);
                        $zip->addFromString('ppt/slides/slide6.xml', $slide6);
                    }

                    $zip->close();

                    header('Content-Type: application/vnd.openxmlformats-officedocument.presentationml.presentation');
                    header('Content-Disposition: attachment; filename="IT_MANCOM_Report_' . date('Ymd') . '.pptx"');
                    header('Content-Length: ' . filesize($tmpFile));
                    readfile($tmpFile);
                    unlink($tmpFile);
                    exit;
                } catch (Throwable $e) {
                    if (file_exists($tmpFile)) unlink($tmpFile);
                    http_response_code(500);
                    echo 'Export error: ' . $e->getMessage();
                    exit;
                }
            }

            echo json_encode($rows);
        }
        break;

    /* --------------------------------------------------------- POST */
    case 'POST':
        $data = read_json_body();
        $name = trim($data['name'] ?? '');
        if ($name === '') fail(422, 'Project name is required.');

        $status   = in_array($data['status'] ?? '', $VALID_STATUSES) ? $data['status'] : 'Not Started';
        $priority = in_array($data['priority'] ?? '', $VALID_PRIORITIES) ? $data['priority'] : 'Medium';
        $progress = max(0, min(100, (int) ($data['progress'] ?? 0)));
        $owner    = trim($data['owner'] ?? '');
        $start    = !empty($data['start']) ? $data['start'] : null;
        $end      = !empty($data['end']) ? $data['end'] : null;
        $budget   = (float) ($data['budget'] ?? 0);
        $desc     = trim($data['description'] ?? '');
        $fileLink = trim($data['file_link'] ?? '');
        $newId    = next_project_id($pdo);

        $notes = trim($data['notes'] ?? '');
        $stmt = $pdo->prepare("INSERT INTO projects (id, name, status, progress, owner, priority, start_date, end_date, budget, description, file_link, notes)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$newId, $name, $status, $progress, $owner, $priority, $start, $end, $budget, $desc, $fileLink ?: null, $notes ?: null]);

        // If client supplied an explicit history array, upsert each entry. Otherwise insert today's point as before.
        if (array_key_exists('history', $data) && is_array($data['history'])) {
            $h2 = $pdo->prepare("INSERT INTO progress_history (project_id, entry_date, progress, notes) VALUES (?, ?, ?, ?)
                                 ON DUPLICATE KEY UPDATE progress = VALUES(progress), notes = VALUES(notes)");
            foreach ($data['history'] as $entry) {
                if (!is_array($entry)) continue;
                $entryDate = $entry['date'] ?? null;
                $entryProg = isset($entry['progress']) ? (int) $entry['progress'] : null;
                $entryNotes = isset($entry['notes']) ? trim($entry['notes']) : null;
                if (!$entryDate || $entryProg === null) continue;
                $entryProg = max(0, min(100, $entryProg));
                try { $h2->execute([$newId, $entryDate, $entryProg, $entryNotes]); } catch (Throwable $e) { /* ignore invalid rows */ }
            }
        } else {
            $h = $pdo->prepare("INSERT INTO progress_history (project_id, entry_date, progress, notes) VALUES (?, ?, ?, NULL)");
            $h->execute([$newId, today(), $progress]);
        }

        $stmt = $pdo->prepare("SELECT * FROM projects WHERE id = ?");
        $stmt->execute([$newId]);
        $project = $stmt->fetch();
        $project['budget'] = (float) $project['budget'];
        $project['progress'] = (int) $project['progress'];

        $hist = $pdo->prepare("SELECT entry_date AS date, progress, notes FROM progress_history WHERE project_id = ? ORDER BY entry_date ASC");
        $hist->execute([$newId]);
        $project['history'] = $hist->fetchAll();

        http_response_code(201);
        echo json_encode($project);
        break;

    /* ---------------------------------------------------------- PUT */
    case 'PUT':
        if (!$id) fail(400, 'Missing project id.');
        $data = read_json_body();

        $stmt = $pdo->prepare("SELECT * FROM projects WHERE id = ?");
        $stmt->execute([$id]);
        $existing = $stmt->fetch();
        if (!$existing) fail(404, 'Project not found.');

        $name     = trim($data['name'] ?? $existing['name']);
        $status   = in_array($data['status'] ?? '', $VALID_STATUSES) ? $data['status'] : $existing['status'];
        $priority = in_array($data['priority'] ?? '', $VALID_PRIORITIES) ? $data['priority'] : $existing['priority'];
        $progress = isset($data['progress']) ? max(0, min(100, (int) $data['progress'])) : (int) $existing['progress'];
        $owner    = trim($data['owner'] ?? $existing['owner']);
        $start    = array_key_exists('start', $data) ? (!empty($data['start']) ? $data['start'] : null) : $existing['start_date'];
        $end      = array_key_exists('end', $data) ? (!empty($data['end']) ? $data['end'] : null) : $existing['end_date'];
        $budget   = isset($data['budget']) ? (float) $data['budget'] : (float) $existing['budget'];
        $desc     = trim($data['description'] ?? $existing['description']);
        $fileLink = array_key_exists('file_link', $data) ? trim($data['file_link']) : $existing['file_link'];

        $notes = array_key_exists('notes', $data) ? trim($data['notes']) : $existing['notes'];
        $stmt = $pdo->prepare("UPDATE projects SET name=?, status=?, progress=?, owner=?, priority=?, start_date=?, end_date=?, budget=?, description=?, file_link=?, notes=? WHERE id=?");
        $stmt->execute([$name, $status, $progress, $owner, $priority, $start, $end, $budget, $desc, $fileLink ?: null, $notes ?: null, $id]);

        // upsert today's history point so the trend chart reflects the latest edit
        $h = $pdo->prepare("INSERT INTO progress_history (project_id, entry_date, progress, notes) VALUES (?, ?, ?, NULL)
                             ON DUPLICATE KEY UPDATE progress = VALUES(progress), notes = COALESCE(notes, VALUES(notes))");
        $h->execute([$id, today(), $progress]);

        // If the client included a 'history' array, upsert each provided entry (date + progress + notes).
        if (array_key_exists('history', $data) && is_array($data['history'])) {
            $h2 = $pdo->prepare("INSERT INTO progress_history (project_id, entry_date, progress, notes) VALUES (?, ?, ?, ?)
                                 ON DUPLICATE KEY UPDATE progress = VALUES(progress), notes = VALUES(notes)");
            foreach ($data['history'] as $entry) {
                if (!is_array($entry)) continue;
                $entryDate = $entry['date'] ?? null;
                $entryProg = isset($entry['progress']) ? (int) $entry['progress'] : null;
                $entryNotes = isset($entry['notes']) ? trim($entry['notes']) : null;
                if (!$entryDate || $entryProg === null) continue;
                $entryProg = max(0, min(100, $entryProg));
                try { $h2->execute([$id, $entryDate, $entryProg, $entryNotes]); } catch (Throwable $e) { /* ignore invalid rows */ }
            }
        }

        $stmt = $pdo->prepare("SELECT * FROM projects WHERE id = ?");
        $stmt->execute([$id]);
        $project = $stmt->fetch();
        $project['budget'] = (float) $project['budget'];
        $project['progress'] = (int) $project['progress'];

        $hist = $pdo->prepare("SELECT entry_date AS date, progress, notes FROM progress_history WHERE project_id = ? ORDER BY entry_date ASC");
        $hist->execute([$id]);
        $project['history'] = $hist->fetchAll();

        echo json_encode($project);
        break;

    /* ------------------------------------------------------- DELETE */
    case 'DELETE':
        if (!$id) fail(400, 'Missing project id.');
        $stmt = $pdo->prepare("DELETE FROM projects WHERE id = ?");
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 0) fail(404, 'Project not found.');
        echo json_encode(['success' => true]);
        break;

    default:
        fail(405, 'Method not allowed.');
}