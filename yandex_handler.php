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
        case 'GET_SESSIONS':
            $response = $selenium->getSessionsInfo();
            break;
            
        case 'START_SESSION':
            $response = $selenium->startSession();
            break;
            
        case 'PARSE_COMPANY':
            if (empty($request['COMPANY_ID'])) {
                throw new Exception("COMPANY_ID is required");
            }
            
            $driver = $selenium->initializeWebDriver();
            $companyUrl = 'https://yandex.ru/maps/org/' . $request['COMPANY_ID'] . '/reviews/';
            
            // Добавляем случайную задержку
            sleep(rand(2, 5));
            
            // Используем executeMainScenario для получения HTML с правильными заголовками
            $html = $selenium->executeMainScenario([
                'PAGE_URL' => $companyUrl,
                'SCROLL' => false
            ]);
            
            if (strpos($html, 'Error:') === 0) {
                throw new Exception($html);
            }
            
            $htmlLength = strlen($html);
            $hasCapcha = strpos($html, 'SmartCaptcha') !== false || 
                        strpos($html, 'captcha') !== false ||
                        strpos($html, 'подтвердите') !== false;
            
            if ($htmlLength < 1000) {
                throw new Exception("HTML too short ($htmlLength chars), possible error page");
            }
            
            if ($hasCapcha) {
                // Попробуем подождать и повторить
                sleep(10);
                $html = $selenium->executeMainScenario([
                    'PAGE_URL' => $companyUrl,
                    'SCROLL' => false
                ]);
                
                if (strpos($html, 'SmartCaptcha') !== false || strpos($html, 'captcha') !== false) {
                    throw new Exception("Captcha detected after retry. Try again later.");
                }
            }
            
            $response = [
                'status' => 'success',
                'data' => parseCompanyInfo($html),
                'debug' => [
                    'html_length' => $htmlLength,
                    'url' => $companyUrl,
                    'has_captcha' => $hasCapcha
                ],
                'timestamp' => date('Y-m-d H:i:s')
            ];
            break;
            
        case 'PARSE_REVIEWS':
            if (empty($request['COMPANY_ID'])) {
                throw new Exception("COMPANY_ID is required");
            }
            
            $driver = $selenium->initializeWebDriver();
            
            // Сначала идем на главную страницу компании для установки cookies
            $companyUrl = 'https://yandex.ru/maps/org/' . $request['COMPANY_ID'] . '/';
            $selenium->executeMainScenario([
                'PAGE_URL' => $companyUrl,
                'SCROLL' => false
            ]);
            
            sleep(rand(3, 7));
            
            // Теперь идем за отзывами
            $reviewsUrl = 'https://yandex.ru/ugcpub/digest?' . http_build_query([
                'offset' => 0,
                'objectId' => "/sprav/" . $request['COMPANY_ID'],
                'addComments' => 'true',
                'otype' => 'Org',
                'appId' => '1org-viewer',
                'limit' => 50,
            ]);
            
            $html = $selenium->executeMainScenario([
                'PAGE_URL' => $reviewsUrl,
                'SCROLL' => false
            ]);
            
            if (strpos($html, 'Error:') === 0) {
                throw new Exception($html);
            }
            
            $htmlLength = strlen($html);
            
            // Проверяем, что это JSON
            $jsonData = json_decode($html, true);
            $isValidJson = (json_last_error() === JSON_ERROR_NONE);
            
            if (!$isValidJson) {
                // Сохраняем для отладки
                file_put_contents(__DIR__ . '/debug_reviews.html', $html);
                
                // Проверяем на капчу или редирект
                if (strpos($html, 'SmartCaptcha') !== false || 
                    strpos($html, 'captcha') !== false ||
                    strpos($html, '<html') !== false) {
                    throw new Exception("Got HTML instead of JSON. Possible captcha or redirect. Length: $htmlLength");
                }
                
                throw new Exception("Response is not valid JSON. Length: $htmlLength. Error: " . json_last_error_msg());
            }
            
            $response = [
                'status' => 'success',
                'data' => parseReviews($html),
                'debug' => [
                    'html_length' => $htmlLength,
                    'url' => $reviewsUrl,
                    'is_json' => $isValidJson,
                    'json_structure' => array_keys($jsonData)
                ],
                'timestamp' => date('Y-m-d H:i:s')
            ];
            break;
            
        case 'FULL_PARSE':
            if (empty($request['COMPANY_ID'])) {
                throw new Exception("COMPANY_ID is required");
            }
            
            $selenium->initializeWebDriver();
            
            // Парсим компанию
            $companyUrl = 'https://yandex.ru/maps/org/' . $request['COMPANY_ID'] . '/reviews/';
            $companyHtml = $selenium->getPageHtmlSafe($companyUrl);
            if (strpos($companyHtml, 'Error:') === 0) {
                throw new Exception($companyHtml);
            }
            
            // Парсим отзывы
            $reviewsUrl = 'https://yandex.ru/ugcpub/digest?' . http_build_query([
                'offset' => 0,
                'objectId' => "/sprav/" . $request['COMPANY_ID'],
                'addComments' => 'true',
                'otype' => 'Org',
                'appId' => '1org-viewer',
                'limit' => 50,
            ]);
            
            $reviewsHtml = $selenium->getPageHtmlSafe($reviewsUrl);
            if (strpos($reviewsHtml, 'Error:') === 0) {
                throw new Exception($reviewsHtml);
            }
            
            $response = [
                'status' => 'success',
                'data' => [
                    'company' => parseCompanyInfo($companyHtml),
                    'reviews' => parseReviews($reviewsHtml)
                ],
                'timestamp' => date('Y-m-d H:i:s')
            ];
            break;
            
        default:
            throw new Exception("Unknown action: $action");
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * Парсит информацию о компании из HTML (без Symfony DomCrawler)
 */
function parseCompanyInfo($html) {
    // Сохраняем HTML для отладки
    file_put_contents(__DIR__ . '/debug_company.html', $html);
    
    $info = [
        'name' => '',
        'rating' => '5.0',
        'full_stars' => 0,
        'half_stars' => 0,
        'empty_stars' => 0,
        'count_reviews' => '0',
        'count_marks' => '0'
    ];

    // Создаем DOMDocument
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
    libxml_clear_errors();
    
    $xpath = new DOMXPath($dom);

    // Название компании
    $nameSelectors = [
        '//h1[contains(@class, "orgpage-header-view__header")]',
        '//h1[contains(@class, "card-title-view__title")]', 
        '//h1',
        '//*[@data-org-name]',
        '//*[contains(@class, "orgpage-header-view__header")]'
    ];
    
    foreach ($nameSelectors as $selector) {
        $nodes = $xpath->query($selector);
        if ($nodes->length > 0) {
            $name = trim($nodes->item(0)->textContent);
            if ($name) {
                $info['name'] = $name;
                break;
            }
        }
    }

    // Рейтинг
    $ratingSelectors = [
        '//*[contains(@class, "business-summary-rating-badge-view__rating")]',
        '//*[contains(@class, "business-rating-badge-view__rating-text")]',
        '//*[contains(@class, "rating-value")]',
        '//*[contains(@class, "business-rating-view__rating")]'
    ];
    
    foreach ($ratingSelectors as $selector) {
        $nodes = $xpath->query($selector);
        if ($nodes->length > 0) {
            $ratingText = trim($nodes->item(0)->textContent);
            $rating = preg_replace('/[^0-9,\.]+/', '', $ratingText);
            if ($rating) {
                $info['rating'] = str_replace(',', '.', $rating);
                break;
            }
        }
    }

    // Звезды
    $fullStars = $xpath->query('//*[contains(@class, "business-rating-badge-view__star") and contains(@class, "_full")]');
    $halfStars = $xpath->query('//*[contains(@class, "business-rating-badge-view__star") and contains(@class, "_half")]');
    $emptyStars = $xpath->query('//*[contains(@class, "business-rating-badge-view__star") and contains(@class, "_empty")]');
    
    $info['full_stars'] = $fullStars->length;
    $info['half_stars'] = $halfStars->length;
    $info['empty_stars'] = $emptyStars->length;

    // Количество отзывов и оценок - ищем числа в тексте
    preg_match_all('/(\d+)\s*отзыв/ui', $html, $reviewMatches);
    if (!empty($reviewMatches[1])) {
        $info['count_reviews'] = $reviewMatches[1][0];
    }
    
    preg_match_all('/(\d+)\s*оценк/ui', $html, $markMatches);
    if (!empty($markMatches[1])) {
        $info['count_marks'] = $markMatches[1][0];
    }
    
    // Дополнительный поиск в тексте страницы
    if (preg_match('/reviewsCount["\']:\s*(\d+)/i', $html, $matches)) {
        $info['count_reviews'] = $matches[1];
    }
    
    if (preg_match('/ratingsCount["\']:\s*(\d+)/i', $html, $matches)) {
        $info['count_marks'] = $matches[1];
    }

    return $info;
}

/**
 * Парсит отзывы из JSON
 */
function parseReviews($html) {
    $jsonData = json_decode($html, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON response');
    }

    $reviews = [];
    $reviewsData = [];

    // Новая структура
    if (isset($jsonData['view']['views'])) {
        $views = $jsonData['view']['views'];
        $reviewsData = array_filter($views, function ($item) {
            return isset($item['type']) && $item['type'] === '/ugc/review';
        });
    }
    // Старая структура
    elseif (isset($jsonData['reviews'])) {
        $reviewsData = array_slice($jsonData['reviews'], 1, -1);
    }

    foreach ($reviewsData as $review) {
        if (!is_array($review)) continue;

        $reviews[] = [
            'name' => $review['author']['name'] ?? 'Пользователь Яндекса',
            'image' => isset($review['author']['pic']) ? 
                'https://avatars.mds.yandex.net/get-yapic/' . $review['author']['pic'] . '/islands-68' : '',
            'rating' => $review['rating']['val'] ?? 5,
            'timestamp' => isset($review['time']) ? intval($review['time'] / 1000) : time(),
            'readable_date' => date('j F', intval(($review['time'] ?? time() * 1000) / 1000)),
            'description' => encodeEmojis($review['text'] ?? ''),
        ];
    }

    return $reviews;
}

/**
 * Кодирует эмодзи в HTML сущности
 */
function encodeEmojis($text) {
    $ranges = [
        '/[\x{1F600}-\x{1F64F}]/u',
        '/[\x{1F300}-\x{1F5FF}]/u',
        '/[\x{1F680}-\x{1F6FF}]/u',
        '/[\x{1F900}-\x{1F9FF}]/u',
        '/[\x{2600}-\x{26FF}]/u',
    ];

    foreach ($ranges as $range) {
        $text = preg_replace_callback($range, function($matches) {
            return '&#' . mb_ord($matches[0], 'UTF-8') . ';';
        }, $text);
    }

    return trim(str_replace(["\0", "\x00"], '', $text));
}
?>