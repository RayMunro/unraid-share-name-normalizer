<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/scripts/share-name-normalizer.php';

function snrRespond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $normalizer = new ShareNameNormalizer();
    $action = (string)($_REQUEST['action'] ?? 'status');

    if ($action === 'status' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        snrRespond(['ok' => true, 'data' => $normalizer->status()]);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        snrRespond(['ok' => false, 'error' => 'Unsupported request method.'], 405);
    }
    if ($action === 'prepare') {
        snrRespond(['ok' => true] + $normalizer->prepare());
    }
    if ($action === 'locate') {
        $names = json_decode((string)($_POST['names'] ?? '[]'), true);
        if (!is_array($names)) {
            throw new RuntimeException('Invalid share-name check.');
        }
        snrRespond(['ok' => true, 'found' => $normalizer->locate($names)]);
    }
    snrRespond(['ok' => false, 'error' => 'Unknown action.'], 400);
} catch (Throwable $error) {
    error_log('share-name-normalizer: ' . $error->getMessage());
    snrRespond(['ok' => false, 'error' => $error->getMessage()], 500);
}
