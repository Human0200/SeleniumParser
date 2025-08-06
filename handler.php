<?php
use SeleniumFunctions\SeleniumManager;

$selenium = new SeleniumManager();

if (isset($_GET['action'])) {
    $RequestParams = $_REQUEST;
    switch ($_GET['action']) {
        case 'start':
            $response = $selenium->startSession();
            echo json_encode($response, JSON_UNESCAPED_UNICODE);
            break;
        case 'sessions':
            $response = $selenium->getSessionsInfo();
            echo json_encode($response, JSON_UNESCAPED_UNICODE);
            break;
        default:
            $html = $selenium->executeMainScenario($_REQUEST);
            echo $html;
    }
}