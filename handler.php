<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/vendor/autoload.php';

use SeleniumFunctions\SeleniumManager;

// Устанавливаем JSON заголовок для всех ответов
header('Content-Type: application/json; charset=utf-8');

try {
    $selenium = new SeleniumManager();
    $request = array_merge($_GET, $_POST);

    if (!isset($request['ACTION'])) {
        throw new Exception("ACTION parameter is required");
    }

    $action = strtoupper($request['ACTION']);
    $response = null;

    switch ($action) {
        case 'START':
            $response = $selenium->startSession();
            break;

        case 'PARSE':
            if (empty($request['PAGE_URL'])) {
                throw new Exception("PAGE_URL is required for PARSE action");
            }

            $result = $selenium->executeMainScenario([
                'PAGE_URL' => $request['PAGE_URL'],
                'SCROLL' => !empty($request['SCROLL'])
            ]);

            // Return raw HTML directly
            header('Content-Type: text/html; charset=utf-8');
            echo $result;
            exit;

        default:
            throw new Exception("Unknown action: $action");
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
