<?php
/**
 * ITPMS — Projects API
 * A small REST-style endpoint consumed by assets/js/dashboard.js via fetch()/AJAX.
 *
 *   GET    projects.php            -> list all projects (no history, for tables/cards)
 *   GET    projects.php?id=PRJ-1   -> single project + full progress history (for the view modal)
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

            // Combined PPT export: projects.php?export=1&format=pptx
            if (isset($_GET['export']) && (isset($_GET['format']) && $_GET['format'] === 'pptx')) {
                $templatesDir = __DIR__ . '/assets/templates';
                $exact = $templatesDir . '/IT_DEPARTMENT_MANCOM_REPORT.pptx';
                $template = null;

                if (file_exists($exact)) {
                    $template = $exact;
                } else {
                    $matches = glob($templatesDir . '/IT DEPARTMENT MANCOM REPORT*.pptx');
                    if ($matches && count($matches)) {
                        usort($matches, function($a, $b) { return filemtime($b) - filemtime($a); });
                        $template = $matches[0];
                    } else {
                        $all = glob($templatesDir . '/*.pptx');
                        if ($all && count($all)) {
                            usort($all, function($a, $b) { return filemtime($b) - filemtime($a); });
                            $template = $all[0];
                        }
                    }
                }

                if (!file_exists($template)) {
                    http_response_code(500);
                    echo "PPTX template not found in: {$templatesDir}. Please place a template PPTX there.";
                    exit;
                }

                $autoload = __DIR__ . '/vendor/autoload.php';
                if (!file_exists($autoload)) {
                    http_response_code(500);
                    echo "PHPPresentation not installed. Run in your project root: composer require phpoffice/phppresentation";
                    exit;
                }
                require_once $autoload;

                try {
                    // Load template (keeps title/branding slides)
                    $pptTemplate = \PhpOffice\PhpPresentation\IOFactory::load($template);

                    // Find a slide in the template that contains the project-placeholder {{PROJECT_NAME}}
                    $templateProjectSlide = null;
                    foreach ($pptTemplate->getAllSlides() as $s) {
                        foreach ($s->getShapeCollection() as $shape) {
                            if ($shape instanceof \PhpOffice\PhpPresentation\Shape\RichText) {
                                foreach ($shape->getParagraphs() as $pgr) {
                                    foreach ($pgr->getRichTextElements() as $rte) {
                                        if ($rte instanceof \PhpOffice\PhpPresentation\Shape\RichText\TextElement) {
                                            if (strpos($rte->getText(), '{{PROJECT_NAME}}') !== false) { $templateProjectSlide = $s; break 3; }
                                        }
                                    }
                                }
                            } else {
                                if (method_exists($shape, 'getText')) {
                                    try { if (strpos($shape->getText(), '{{PROJECT_NAME}}') !== false) { $templateProjectSlide = $s; break 3; } } catch (Throwable $e) { }
                                }
                            }
                        }
                    }

                    // Create a fresh presentation to assemble title slides + project slides
                    $out = new \PhpOffice\PhpPresentation\PhpPresentation();

                    // Copy all template slides except the placeholder slide into $out
                    foreach ($pptTemplate->getAllSlides() as $slide) {
                        if ($templateProjectSlide !== null && $slide === $templateProjectSlide) continue;
                        $out->addSlide(clone $slide);
                    }

                    if ($templateProjectSlide !== null) {
                        // For each project: clone the template project slide, replace placeholders, and add to output
                        foreach ($rows as $p) {
                            $newSlide = clone $templateProjectSlide;
                            $placeholders = [
                                '{{PROJECT_NAME}}' => $p['name'] ?? '',
                                '{{STATUS}}' => $p['status'] ?? '',
                                '{{PROGRESS}}' => (string)((int)($p['progress'] ?? 0)) . '%',
                                '{{OWNER}}' => $p['owner'] ?? '',
                                '{{START_DATE}}' => $p['start_date'] ?? '',
                                '{{END_DATE}}' => $p['end_date'] ?? '',
                                '{{DESCRIPTION}}' => trim($p['description'] ?? ''),
                            ];

                            foreach ($newSlide->getShapeCollection() as $shape) {
                                if ($shape instanceof \PhpOffice\PhpPresentation\Shape\RichText) {
                                    foreach ($shape->getParagraphs() as $pgr) {
                                        foreach ($pgr->getRichTextElements() as $rte) {
                                            if ($rte instanceof \PhpOffice\PhpPresentation\Shape\RichText\TextElement) {
                                                $text = $rte->getText();
                                                $new = strtr($text, $placeholders);
                                                if ($new !== $text) { $rte->setText($new); }
                                            }
                                        }
                                    }
                                } else {
                                    if (method_exists($shape, 'getText') && method_exists($shape, 'setText')) {
                                        try {
                                            $text = $shape->getText();
                                            $new = strtr($text, $placeholders);
                                            if ($new !== $text) { $shape->setText($new); }
                                        } catch (Throwable $e) { /* ignore shapes we can't read */ }
                                    }
                                }
                            }

                            $out->addSlide($newSlide);
                        }
                    } else {
                        // Fallback: create simple project-detail slides if template placeholder not found
                        foreach ($rows as $p) {
                            $slide = $out->createSlide();

                            $titleShape = $slide->createRichTextShape();
                            $titleShape->setHeight(80)->setWidth(860)->setOffsetX(50)->setOffsetY(40);
                            $titleRun = $titleShape->createTextRun($p['name'] ?? 'Untitled Project');
                            $titleRun->getFont()->setBold(true)->setSize(36)->setName('Space Grotesk')->setColor(new \PhpOffice\PhpPresentation\Style\Color('000080'));

                            $metaShape = $slide->createRichTextShape();
                            $metaShape->setHeight(60)->setWidth(860)->setOffsetX(50)->setOffsetY(120);
                            $metaText = sprintf("Status: %s    Progress: %d%%    Owner: %s    Start: %s    End: %s",
                                $p['status'] ?? 'Unknown', (int)($p['progress'] ?? 0), $p['owner'] ?? '-', $p['start_date'] ?? '-', $p['end_date'] ?? '-');
                            $metaRun = $metaShape->createTextRun($metaText);
                            $metaRun->getFont()->setSize(14)->setName('Inter')->setColor(new \PhpOffice\PhpPresentation\Style\Color('000000'));

                            $descShape = $slide->createRichTextShape();
                            $descShape->setHeight(300)->setWidth(860)->setOffsetX(50)->setOffsetY(180);
                            $desc = trim($p['description'] ?? '');
                            if (strlen($desc) > 800) { $desc = substr($desc, 0, 800) . '...'; }
                            $descRun = $descShape->createTextRun($desc ?: '-');
                            $descRun->getFont()->setSize(16)->setName('Inter')->setColor(new \PhpOffice\PhpPresentation\Style\Color('000000'));
                        }
                    }

                    header('Content-Type: application/vnd.openxmlformats-officedocument.presentationml.presentation');
                    header('Content-Disposition: attachment; filename="Projects_Report_' . date('Ymd') . '.pptx"');

                    $writer = \PhpOffice\PhpPresentation\IOFactory::createWriter($out, 'PowerPoint2007');
                    $writer->save('php://output');
                    exit;
                } catch (Throwable $e) {
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
