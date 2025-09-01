<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

try {
    $request = array_merge($_GET, $_POST);
    
    if (!isset($request['ACTION'])) {
        throw new Exception("ACTION parameter is required");
    }

    $action = strtoupper($request['ACTION']);
    $response = null;

    switch ($action) {
        case 'PARSE_COMPANY_DIRECT':
            if (empty($request['COMPANY_ID'])) {
                throw new Exception("COMPANY_ID is required");
            }
            
            $response = parseCompanyDirect($request['COMPANY_ID']);
            break;
            
        case 'PARSE_REVIEWS_DIRECT':
            if (empty($request['COMPANY_ID'])) {
                throw new Exception("COMPANY_ID is required");
            }
            
            $response = parseReviewsDirect($request['COMPANY_ID']);
            break;
            
        case 'FULL_PARSE_DIRECT':
            if (empty($request['COMPANY_ID'])) {
                throw new Exception("COMPANY_ID is required");
            }
            
            $companyData = parseCompanyDirect($request['COMPANY_ID']);
            $reviewsData = parseReviewsDirect($request['COMPANY_ID']);
            
            $response = [
                'status' => 'success',
                'data' => [
                    'company' => $companyData['data'] ?? [],
                    'reviews' => $reviewsData['data'] ?? []
                ],
                'method' => 'direct_http_v2',
                'timestamp' => date('Y-m-d H:i:s')
            ];
            break;
            
        default:
            throw new Exception("Unknown action: $action");
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}

/**
 * Генерирует правильные заголовки как в рабочем коде
 */
function generateRandomHeaders() {
    $oses = [
        'Windows NT 10.0; Win64; x64',
        'Windows NT 10.0; WOW64',
        'Windows NT 6.1; Win64; x64',
        'Macintosh; Intel Mac OS X 10_15',
        'Macintosh; Intel Mac OS X 11_0',
        'X11; Linux x86_64',
    ];

    $majorVersions = range(115, 137);
    $rv = (string) $majorVersions[array_rand($majorVersions)];
    $version = $rv . '.0';
    $os = $oses[array_rand($oses)];

    $randomFireFoxDesktopUserAgent = sprintf(
        'Mozilla/5.0 (%s; rv:%s) Gecko/20100101 Firefox/%s',
        $os,
        $rv,
        $version
    );

    return [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language: ru-RU,ru;q=0.8,en-US;q=0.5,en;q=0.3',
        'Connection: keep-alive',
        'Host: yandex.ru',
        'Priority: u=0, i',
        'Sec-Fetch-Dest: document',
        'Sec-Fetch-Mode: navigate',
        'Sec-Fetch-Site: none',
        'Sec-Fetch-User: ?1',
        'TE: trailers',
        'Upgrade-Insecure-Requests: 1',
        'User-Agent: ' . $randomFireFoxDesktopUserAgent,
    ];
}

/**
 * HTTP запрос с правильными заголовками
 */
function makeHttpRequest($url, $timeout = 10) {
    $headers = generateRandomHeaders();
    
    // Добавляем случайную задержку
    usleep(rand(500000, 2000000)); // 0.5-2 секунды
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_ENCODING => 'gzip,deflate,br',
        CURLOPT_COOKIEFILE => '/tmp/yandex_cookies.txt',
        CURLOPT_COOKIEJAR => '/tmp/yandex_cookies.txt',
        CURLOPT_REFERER => 'https://yandex.ru/'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($response === false || !empty($error)) {
        throw new Exception("HTTP request failed: $error");
    }
    
    if ($httpCode !== 200) {
        throw new Exception("HTTP error code: $httpCode");
    }
    
    return $response;
}

/**
 * Парсинг информации о компании
 */
function parseCompanyDirect($companyId) {
    try {
        // Сначала пробуем API (из второго скрипта)
        $apiUrl = 'https://yandex.ru/ugcpub/digest?' . http_build_query([
            'offset' => 0,
            'objectId' => "/sprav/$companyId",
            'otype' => 'Org',
            'limit' => 1
        ]);
        
        $apiResponse = makeHttpRequest($apiUrl, 8);
        $apiData = json_decode($apiResponse, true);
        
        if ($apiData && json_last_error() === JSON_ERROR_NONE) {
            // Извлекаем информацию из API
            $info = extractCompanyFromApi($apiData);
            if (!empty($info['name'])) {
                return [
                    'status' => 'success',
                    'data' => $info,
                    'source' => 'api',
                    'timestamp' => date('Y-m-d H:i:s')
                ];
            }
        }
        
        // Если API не дал результат, пробуем HTML страницу (из первого скрипта)
        $pageUrl = "https://yandex.ru/maps/org/$companyId/reviews/";
        $html = makeHttpRequest($pageUrl, 10);
        
        // Проверка на капчу
        if (strpos($html, 'SmartCaptcha') !== false || 
            strpos($html, 'confirm you are not a robot') !== false) {
            throw new Exception("Captcha detected on company page");
        }
        
        $info = extractCompanyFromHtml($html);
        
        return [
            'status' => 'success',
            'data' => $info,
            'source' => 'html',
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
    } catch (Exception $e) {
        return [
            'status' => 'error',
            'message' => 'Company parsing failed: ' . $e->getMessage()
        ];
    }
}

/**
 * Парсинг отзывов с новой структурой API
 */
function parseReviewsDirect($companyId) {
    try {
        $apiUrl = 'https://yandex.ru/ugcpub/digest?' . http_build_query([
            'offset' => 0,
            'objectId' => "/sprav/$companyId",
            'addComments' => 'true',
            'otype' => 'Org',
            'appId' => '1org-viewer',
            'limit' => 50,
        ]);
        
        $response = makeHttpRequest($apiUrl, 10);
        
        if (strlen($response) < 10) {
            throw new Exception("Empty or invalid API response");
        }
        
        // Сохраняем для отладки
        file_put_contents('/tmp/api_debug_response.json', $response);
        
        $data = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid JSON response: " . json_last_error_msg());
        }
        
        $reviews = extractReviewsFromApiV2($data);
        
        return [
            'status' => 'success',
            'data' => $reviews,
            'count' => count($reviews),
            'source' => 'api_v2',
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
    } catch (Exception $e) {
        return [
            'status' => 'error',
            'message' => 'Reviews parsing failed: ' . $e->getMessage(),
            'debug_file' => '/tmp/api_debug_response.json'
        ];
    }
}

/**
 * Извлечение информации о компании из API (из второго скрипта)
 */
function extractCompanyFromApi($data) {
    $info = [
        'name' => '',
        'rating' => '0',
        'reviews_count' => '0'
    ];
    
    // Рекурсивный поиск в структуре данных
    $extractValue = function($data, $path) use (&$extractValue) {
        if (!is_array($data) || empty($path)) return null;
        
        $key = array_shift($path);
        if (!isset($data[$key])) return null;
        
        if (empty($path)) {
            return $data[$key];
        }
        
        return $extractValue($data[$key], $path);
    };
    
    // Пытаемся найти название
    foreach ([
        ['businessCard', 'title'],
        ['view', 'businessCard', 'title'],
        ['data', 'title'],
        ['title'],
        ['name']
    ] as $path) {
        $value = $extractValue($data, $path);
        if ($value && is_string($value) && !empty(trim($value))) {
            $info['name'] = trim($value);
            break;
        }
    }
    
    // Пытаемся найти рейтинг
    foreach ([
        ['businessCard', 'rating', 'value'],
        ['view', 'businessCard', 'rating', 'value'],
        ['rating', 'value'],
        ['rating']
    ] as $path) {
        $value = $extractValue($data, $path);
        if ($value && (is_numeric($value) || is_string($value))) {
            $info['rating'] = (string)$value;
            break;
        }
    }
    
    // Пытаемся найти количество отзывов
    foreach ([
        ['businessCard', 'rating', 'count'],
        ['view', 'businessCard', 'rating', 'count'],
        ['rating', 'count'],
        ['reviewsCount'],
        ['reviews_count']
    ] as $path) {
        $value = $extractValue($data, $path);
        if ($value && (is_numeric($value) || is_string($value))) {
            $info['reviews_count'] = (string)$value;
            break;
        }
    }
    
    return $info;
}

/**
 * Извлечение информации о компании из HTML (улучшенная версия из первого скрипта)
 */
function extractCompanyFromHtml($html) {
    $info = [
        'name' => 'Не найдено',
        'rating' => '5.0',
        'reviews_count' => '0'
    ];
    
    // Создаем DOMDocument
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    
    // ЗАМЕНА УСТАРЕВШЕЙ ФУНКЦИИ - полное исправление для PHP 8.2+
    // Преобразуем HTML в правильную кодировку без deprecated функций
    $html = htmlspecialchars_decode(htmlentities($html, ENT_QUOTES, 'UTF-8'));
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    
    libxml_clear_errors();
    
    $xpath = new DOMXPath($dom);

    // Поиск названия компании (множественные селекторы)
    $nameSelectors = [
        '//h1[contains(@class, "orgpage-header-view__header")]',
        '//h1[contains(@class, "card-title-view__title")]', 
        '//*[@data-org-name]',
        '//*[contains(@class, "business-card-title-view__title")]',
        '//h1'
    ];
    
    foreach ($nameSelectors as $selector) {
        try {
            $nodes = $xpath->query($selector);
            if ($nodes->length > 0) {
                $name = trim($nodes->item(0)->textContent);
                if ($name && !empty($name)) {
                    $info['name'] = $name;
                    break;
                }
            }
        } catch (Exception $e) {
            continue;
        }
    }

    // Поиск рейтинга
    $ratingSelectors = [
        '//*[contains(@class, "business-summary-rating-badge-view__rating")]',
        '//*[contains(@class, "business-rating-badge-view__rating-text")]',
        '//*[contains(@class, "business-rating-view__rating")]'
    ];
    
    foreach ($ratingSelectors as $selector) {
        try {
            $nodes = $xpath->query($selector);
            if ($nodes->length > 0) {
                $ratingText = trim($nodes->item(0)->textContent);
                $rating = preg_replace('/[^0-9,\.]+/', '', $ratingText);
                if ($rating) {
                    $info['rating'] = str_replace(',', '.', $rating);
                    break;
                }
            }
        } catch (Exception $e) {
            continue;
        }
    }

    // Поиск количества отзывов
    if (preg_match('/(\d+)\s*отзыв/ui', $html, $matches)) {
        $info['reviews_count'] = $matches[1];
    }
    
    // Дополнительный поиск в JSON данных на странице
    if (preg_match('/reviewsCount["\']:\s*(\d+)/i', $html, $matches)) {
        $info['reviews_count'] = $matches[1];
    }

    return $info;
}

/**
 * Извлечение отзывов из API V2 (новая структура из первого скрипта)
 */
function extractReviewsFromApiV2($data) {
    $reviews = [];
    
    // НОВАЯ структура: view->views
    if (isset($data['view']['views'])) {
        $views = $data['view']['views'];
        
        // Фильтруем только отзывы (type = "/ugc/review")
        $reviewsData = array_filter($views, function ($item) {
            return isset($item['type']) && $item['type'] === '/ugc/review';
        });
        
        $reviewsData = array_values($reviewsData);
    }
    // СТАРАЯ структура: reviews (для обратной совместимости)
    elseif (isset($data['reviews'])) {
        $reviewsData = array_slice($data['reviews'], 1, -1);
    }
    else {
        return $reviews; // Пустой массив если структура неизвестна
    }
    
    foreach ($reviewsData as $review) {
        if (!is_array($review)) continue;
        
        $userName = isset($review['author']['name']) ? $review['author']['name'] : 'Пользователь Яндекса';
        $avatarUrl = '';
        if (isset($review['author']['pic']) && !empty($review['author']['pic'])) {
            $avatarUrl = 'https://avatars.mds.yandex.net/get-yapic/' . $review['author']['pic'] . '/islands-68';
        }
        
        $reviews[] = [
            'name' => $userName,
            'image' => $avatarUrl,
            'rating' => $review['rating']['val'] ?? 5,
            'timestamp' => isset($review['time']) ? intval($review['time'] / 1000) : time(),
            'date' => getRussianDate(intval(($review['time'] ?? time() * 1000) / 1000)),
            'text' => encodeEmojisForDatabase($review['text'] ?? ''),
        ];
    }
    
    return $reviews;
}

function getRussianDate($timestamp) {
    $months = [
        1 => 'января', 2 => 'февраля', 3 => 'марта', 4 => 'апреля',
        5 => 'мая', 6 => 'июня', 7 => 'июля', 8 => 'августа',
        9 => 'сентября', 10 => 'октября', 11 => 'ноября', 12 => 'декабря'
    ];
    
    $day = date('j', $timestamp);
    $monthNum = date('n', $timestamp);
    
    return $day . ' ' . $months[$monthNum];
}

/**
 * Кодирование эмодзи для базы данных (из рабочего кода)
 */
function encodeEmojisForDatabase($text) {
    // Кодируем только эмодзи в HTML-сущности, оставляя обычный текст как есть
    $text = preg_replace_callback('/[\x{1F600}-\x{1F64F}]/u', function($matches) {
        return '&#' . mb_ord($matches[0], 'UTF-8') . ';';
    }, $text);
    
    $text = preg_replace_callback('/[\x{1F300}-\x{1F5FF}]/u', function($matches) {
        return '&#' . mb_ord($matches[0], 'UTF-8') . ';';
    }, $text);
    
    $text = preg_replace_callback('/[\x{1F680}-\x{1F6FF}]/u', function($matches) {
        return '&#' . mb_ord($matches[0], 'UTF-8') . ';';
    }, $text);
    
    $text = preg_replace_callback('/[\x{1F900}-\x{1F9FF}]/u', function($matches) {
        return '&#' . mb_ord($matches[0], 'UTF-8') . ';';
    }, $text);
    
    // Дополнительные диапазоны эмодзи
    $text = preg_replace_callback('/[\x{2600}-\x{26FF}]/u', function($matches) {
        return '&#' . mb_ord($matches[0], 'UTF-8') . ';';
    }, $text);
    
    // Очистка от проблемных символов
    $text = str_replace(["\0", "\x00"], '', $text);
    $text = trim($text);
    
    return $text;
}