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

require_once __DIR__ . '/../config.php';

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

function fail($code, $message) {
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
        $newId    = next_project_id($pdo);

        $notes = trim($data['notes'] ?? '');
        $stmt = $pdo->prepare("INSERT INTO projects (id, name, status, progress, owner, priority, start_date, end_date, budget, description, notes)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$newId, $name, $status, $progress, $owner, $priority, $start, $end, $budget, $desc, $notes ?: null]);

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

        $notes = array_key_exists('notes', $data) ? trim($data['notes']) : $existing['notes'];
        $stmt = $pdo->prepare("UPDATE projects SET name=?, status=?, progress=?, owner=?, priority=?, start_date=?, end_date=?, budget=?, description=?, notes=? WHERE id=?");
        $stmt->execute([$name, $status, $progress, $owner, $priority, $start, $end, $budget, $desc, $notes ?: null, $id]);

        // upsert today's history point so the trend chart reflects the latest edit
        $h = $pdo->prepare("INSERT INTO progress_history (project_id, entry_date, progress, notes) VALUES (?, ?, ?, NULL)
                             ON DUPLICATE KEY UPDATE progress = VALUES(progress), notes = COALESCE(notes, VALUES(notes))");
        $h->execute([$id, today(), $progress]);

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
