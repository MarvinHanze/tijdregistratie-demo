<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
requireAuth();
setupDatabase();

header('Content-Type: application/json');

$project = trim($_GET['project'] ?? '');
if ($project === '') {
    echo json_encode(['gemiddelde_minuten' => 0, 'basis' => 'geen_invoer', 'vergelijkbaar_project' => null]);
    exit;
}

echo json_encode(estimateProjectDurationMinutes($project));
