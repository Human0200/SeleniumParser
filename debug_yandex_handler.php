<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/vendor/autoload.php';
require_once 'defines.php';

use SeleniumFunctions\SeleniumManager;

header('Content-Type: application/json; charset=utf-8');

try {
    $request = array_merge($_GET, $_POST);
    
    if (!isset($request['ACTION'])) {
        throw new Exception("ACTION parameter is required");
    }

    $action = strtoupper($request['ACTION']);
    $selenium = new SeleniumManager();
    $response = null;

    switch ($action) {
        case 'DEBUG_PAGE':
            if (empty($request['COMPANY_ID'])) {
                throw new Exception("COMPANY_ID is required");
            }
            
            $driver = $selenium->initializeWebDriver();
            $companyUrl = 'https://yandex.ru/maps/org/' . $request['COMPANY_ID'] . '/reviews/';
            
            // Получаем HTML разными способами
            $methods = [];
            
            // Способ 1: executeMainScenario
            try {
                $html1 = $selenium->executeMainScenario([
                    'PAGE_URL' => $companyUrl,
                    'SCROLL' => false
                ]);
                $methods['executeMainScenario'] = [
                    'length' => strlen($html1),
                    'preview' => substr($html1, 0, 200),
                    'has_html_tags' => strpos($html1, '<html') !== false,
                    'has_body' => strpos($html1, '<body') !== false,
                    'has_captcha' => strpos($html1, 'captcha') !== false,
                    'title_tag' => preg_match('/<title>(.*?)<\/title>/i', $html1, $matches) ? $matches[1] : 'no title'
                ];
            } catch (Exception $e) {
                $methods['executeMainScenario'] = ['error' => $e->getMessage()];
            }
            
            // Способ 2: getPageHtmlSafe
            try {
                $html2 = $selenium->getPageHtmlSafe($companyUrl);
                $methods['getPageHtmlSafe'] = [
                    'length' => strlen($html2),
                    'preview' => substr($html2, 0, 200),
                    'has_html_tags' => strpos($html2, '<html') !== false,
                    'has_body' => strpos($html2, '<body') !== false,
                    'has_captcha' => strpos($html2, 'captcha') !== false,
                    'title_tag' => preg_match('/<title>(.*?)<\/title>/i', $html2, $matches) ? $matches[1] : 'no title'
                ];
            } catch (Exception $e) {
                $methods['getPageHtmlSafe'] = ['error' => $e->getMessage()];
            }
            
            // Способ 3: Сначала на главную яндекс.ру
            try {
                $selenium->executeMainScenario([
                    'PAGE_URL' => 'https://yandex.ru',
                    'SCROLL' => false
                ]);
                sleep(2);
                
                $html3 = $selenium->executeMainScenario([
                    'PAGE_URL' => $companyUrl,
                    'SCROLL' => false
                ]);
                $methods['via_yandex_main'] = [
                    'length' => strlen($html3),
                    'preview' => substr($html3, 0, 200),
                    'has_html_tags' => strpos($html3, '<html') !== false,
                    'has_body' => strpos($html3, '<body') !== false,
                    'has_captcha' => strpos($html3, 'captcha') !== false,
                    'title_tag' => preg_match('/<title>(.*?)<\/title>/i', $html3, $matches) ? $matches[1] : 'no title'
                ];
            } catch (Exception $e) {
                $methods['via_yandex_main'] = ['error' => $e->getMessage()];
            }
            
            $response = [
                'status' => 'success',
                'data' => [
                    'url' => $companyUrl,
                    'methods' => $methods,
                    'timestamp' => date('Y-m-d H:i:s')
                ]
            ];
            break;
            
        case 'DEBUG_API':
            if (empty($request['COMPANY_ID'])) {
                throw new Exception("COMPANY_ID is required");
            }
            
            $driver = $selenium->initializeWebDriver();
            
            // Разные URL для API
            $apis = [];
            
            $baseParams = [
                'offset' => 0,
                'objectId' => "/sprav/" . $request['COMPANY_ID'],
                'addComments' => 'true',
                'otype' => 'Org',
                'appId' => '1org-viewer',
                'limit' => 10,
            ];
            
            // API 1: Стандартный
            $url1 = 'https://yandex.ru/ugcpub/digest?' . http_build_query($baseParams);
            try {
                $html1 = $selenium->executeMainScenario([
                    'PAGE_URL' => $url1,
                    'SCROLL' => false
                ]);
                $json1 = json_decode($html1, true);
                $apis['standard_api'] = [
                    'url' => $url1,
                    'length' => strlen($html1),
                    'preview' => substr($html1, 0, 200),
                    'is_json' => json_last_error() === JSON_ERROR_NONE,
                    'json_keys' => $json1 ? array_keys($json1) : null
                ];
            } catch (Exception $e) {
                $apis['standard_api'] = ['error' => $e->getMessage()];
            }
            
            // API 2: Через maps.yandex.ru
            $url2 = 'https://maps.yandex.ru/ugcpub/digest?' . http_build_query($baseParams);
            try {
                $html2 = $selenium->executeMainScenario([
                    'PAGE_URL' => $url2,
                    'SCROLL' => false
                ]);
                $json2 = json_decode($html2, true);
                $apis['maps_api'] = [
                    'url' => $url2,
                    'length' => strlen($html2),
                    'preview' => substr($html2, 0, 200),
                    'is_json' => json_last_error() === JSON_ERROR_NONE,
                    'json_keys' => $json2 ? array_keys($json2) : null
                ];
            } catch (Exception $e) {
                $apis['maps_api'] = ['error' => $e->getMessage()];
            }
            
            $response = [
                'status' => 'success',
                'data' => [
                    'company_id' => $request['COMPANY_ID'],
                    'apis' => $apis,
                    'timestamp' => date('Y-m-d H:i:s')
                ]
            ];
            break;
            
        case 'GET_CURRENT_URL':
            $driver = $selenium->initializeWebDriver();
            
            // Получаем текущий URL и заголовок страницы
            try {
                $currentUrl = $driver->getCurrentURL();
                $title = $driver->getTitle();
                $pageSource = $driver->getPageSource();
                
                $response = [
                    'status' => 'success',
                    'data' => [
                        'current_url' => $currentUrl,
                        'title' => $title,
                        'page_length' => strlen($pageSource),
                        'page_preview' => substr($pageSource, 0, 500),
                        'has_captcha' => strpos($pageSource, 'captcha') !== false
                    ]
                ];
            } catch (Exception $e) {
                throw new Exception('Error getting current page: ' . $e->getMessage());
            }
            break;
            
        default:
            // Остальные действия из основного handler'а
            $originalHandler = file_get_contents(__DIR__ . '/yandex_handler.php');
            throw new Exception("Use original handler for action: $action");
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}