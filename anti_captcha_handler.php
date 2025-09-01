<?php
set_time_limit(120);
ini_set('display_errors', '1');

$baseUrl = 'http://217.114.4.16/seleniumParser';
$companyId = '45616405414';

echo "🚀 ФИНАЛЬНЫЙ ТЕСТ С ОБХОДОМ КАПЧИ\n";
echo "🎯 Компания: $companyId\n";
echo str_repeat('=', 50) . "\n";

function testMethod($url, $timeout, $methodName) {
    echo "\n🔄 $methodName (макс. {$timeout}с)...\n";
    flush();
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_NOSIGNAL => 1
    ]);
    
    $start = microtime(true);
    $response = curl_exec($ch);
    $time = round(microtime(true) - $start, 1);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($response === false) {
        echo "❌ ОШИБКА за {$time}с: $error\n";
        return false;
    }
    
    $size = strlen($response);
    echo "✅ Ответ получен за {$time}с | $size байт | HTTP $httpCode\n";
    
    // Анализируем ответ
    $jsonData = json_decode($response, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        echo "📄 Формат: JSON\n";
        
        if (isset($jsonData['status'])) {
            $status = $jsonData['status'];
            echo "📊 Статус: $status\n";
            
            if ($status === 'success' && isset($jsonData['data'])) {
                $data = $jsonData['data'];
                
                // Информация о компании
                if (isset($data['name'])) {
                    echo "🏢 Название: {$data['name']}\n";
                }
                if (isset($data['rating'])) {
                    echo "⭐ Рейтинг: {$data['rating']}\n";
                }
                if (isset($data['reviews_count'])) {
                    echo "📝 Отзывов: {$data['reviews_count']}\n";
                }
                
                // Отзывы (если это массив)
                if (is_array($data) && count($data) > 0 && isset($data[0]['name'])) {
                    echo "📝 Получено отзывов: " . count($data) . "\n";
                    echo "👤 Первый автор: {$data[0]['name']}\n";
                }
                
                echo "✨ УСПЕХ! Данные получены.\n";
                return true;
                
            } elseif ($status === 'error') {
                echo "⚠️  Ошибка: " . ($jsonData['message'] ?? 'не указана') . "\n";
            }
        }
    } else {
        echo "📄 Формат: HTML/Text\n";
        if (strpos($response, 'Fatal error') !== false) {
            echo "💥 PHP ошибка в коде!\n";
            echo "🔍 " . substr($response, 0, 200) . "...\n";
        }
    }
    
    return false;
}

// Сохраните anti_captcha_handler.php на сервер и протестируйте разные методы

$methods = [
    [
        'name' => '1. Обычный парсинг (для сравнения)',
        'url' => $baseUrl . '/yandex_handler.php?ACTION=PARSE_COMPANY&COMPANY_ID=' . $companyId,
        'timeout' => 15
    ],
    [
        'name' => '2. Стелс-режим (имитация человека)',
        'url' => $baseUrl . '/anti_captcha_handler.php?ACTION=STEALTH_PARSE_COMPANY&COMPANY_ID=' . $companyId,
        'timeout' => 25
    ],
    [
        'name' => '3. Прямой API (без браузера)',
        'url' => $baseUrl . '/anti_captcha_handler.php?ACTION=PARSE_REVIEWS_API&COMPANY_ID=' . $companyId,
        'timeout' => 10
    ],
    [
        'name' => '4. Debug метод',
        'url' => $baseUrl . '/debug_yandex_handler.php?ACTION=DEBUG_PAGE&COMPANY_ID=' . $companyId,
        'timeout' => 20
    ]
];

$successCount = 0;
$totalMethods = count($methods);

foreach ($methods as $method) {
    if (testMethod($method['url'], $method['timeout'], $method['name'])) {
        $successCount++;
    }
    
    // Пауза между методами
    if ($method !== end($methods)) {
        echo "⏳ Пауза 3 сек...\n";
        sleep(3);
    }
}

// Итоговый отчет
echo "\n" . str_repeat('=', 50) . "\n";
echo "📊 ИТОГОВЫЙ ОТЧЕТ\n";
echo str_repeat('=', 50) . "\n";
echo "✅ Успешных методов: $successCount/$totalMethods\n";
echo "🎯 ID компании: $companyId\n";
echo "📅 Время: " . date('H:i:s') . "\n";

if ($successCount > 0) {
    echo "\n🎉 ПОЗДРАВЛЯЕМ! Найден рабочий метод обхода капчи!\n";
    echo "💡 Используйте успешный метод для production\n";
} else {
    echo "\n🚫 ВСЕ МЕТОДЫ ЗАБЛОКИРОВАНЫ\n";
    echo "🔧 Рекомендации:\n";
    echo "   - Смените IP сервера\n";
    echo "   - Используйте VPN или прокси\n";
    echo "   - Попробуйте через несколько часов\n";
    echo "   - Рассмотрите использование платных прокси\n";
}

echo "\n✨ Тест завершен!\n";
?>