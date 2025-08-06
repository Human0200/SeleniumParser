<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// Установка таймаутов для WebDriver
putenv('WEBDRIVER_REMOTE_TIMEOUT=60');
putenv('WEBDRIVER_REMOTE_CONNECT_TIMEOUT=30');

require 'vendor/autoload.php';
require_once 'defines.php';

use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverWait;
use Facebook\WebDriver\WebDriverExpectedCondition;

header('Content-Type: text/html; charset=utf-8');

/**
 * Создает или восстанавливает сессию WebDriver
 */
function initializeWebDriver() {
    $maxRetries = 3;
    $attempt = 0;

    while ($attempt < $maxRetries) {
        try {
            // Пытаемся восстановить существующую сессию
            if (file_exists('session_id.txt')) {
                $sessionId = file_get_contents('session_id.txt');
                if (!empty($sessionId)) {
                    $driver = RemoteWebDriver::createBySessionID($sessionId, SERVER_URL);
                    // Проверяем активность сессии
                    $driver->getCurrentUrl();
                    return $driver;
                }
            }

            // Создаем новую сессию
            $capabilities = DesiredCapabilities::firefox();
            $driver = RemoteWebDriver::create(SERVER_URL, $capabilities);
            file_put_contents('session_id.txt', $driver->getSessionID());
            return $driver;

        } catch (Exception $e) {
            $attempt++;
            sleep(2);
            // Удаляем нерабочий session_id
            if (file_exists('session_id.txt')) {
                unlink('session_id.txt');
            }
        }
    }

    throw new Exception("Не удалось инициализировать WebDriver после $maxRetries попыток");
}

/**
 * Безопасное получение HTML страницы
 */
function getPageHtmlSafe($driver, $url) {
    try {
        // Способ 1: Через executeScript с Base64 кодированием
        $html = $driver->executeScript("
            try {
                const html = document.documentElement.outerHTML;
                return btoa(unescape(encodeURIComponent(html)));
            } catch(e) {
                console.error(e);
                return 'ERROR:' + e.message;
            }
        ");

        if (!empty($html) && strpos($html, 'ERROR:') !== 0) {
            $decoded = base64_decode($html, true);
            if ($decoded !== false) {
                return $decoded;
            }
        }

        // Способ 2: Стандартный getPageSource
        $html = $driver->getPageSource();
        return $html;

    } catch (Exception $e) {
        // Способ 3: Резервный вариант через file_get_contents
        $directContent = @file_get_contents($url);
        if ($directContent !== false) {
            return $directContent;
        }

        return "<!-- ERROR: " . htmlspecialchars($e->getMessage()) . " -->";
    }
}

// Основной код выполнения
try {
    // Получаем параметры
    $pageURL = $_REQUEST["PAGE_URL"] ?? MAIN_PAGE;
    $_REQUEST["TIME"] = date("Y-m-d H:i:s");
    $scroll = $_REQUEST["SCROLL"] ?? false;

    // Логируем запрос
    file_put_contents(date("Y-m-d") . "_requests.txt", print_r($_REQUEST, true), FILE_APPEND);

    // Инициализируем драйвер
    $driver = initializeWebDriver();

    // Переходим на нужную страницу
    $currentPage = $driver->getCurrentURL();
    if ($currentPage != $pageURL) {
        $driver->get($pageURL);
    }

    // Ожидаем загрузки
    $wait = new WebDriverWait($driver, 15);
    $wait->until(function($driver) {
        return $driver->executeScript("return document.readyState === 'complete';");
    });

    // Прокрутка страницы (если нужно)
    if ($scroll) {
        $driver->executeScript("window.scrollTo(0, document.body.scrollHeight);");
        sleep(2);

        // Клики по элементам (если нужно)
        $elements = $driver->findElements(WebDriverBy::cssSelector("a.tabs__button"));
        $downloadedElements = [];

        foreach ($elements as $element) {
            try {
                $elementDownloadLink = $element->getAttribute("download");
                if (!$elementDownloadLink) continue;

                $elementDownloadLink = str_replace("/", "_", $elementDownloadLink);
                $downloadedElements[] = $elementDownloadLink;

                $driver->executeScript('arguments[0].click();', [$element]);
                sleep(1);
            } catch (Exception $e) {
                error_log("Click error: " . $e->getMessage());
            }
        }
    }

    // Получаем HTML безопасным способом
    $html = getPageHtmlSafe($driver, $pageURL);

    // Очистка от бинарных данных
    $html = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $html);

    // Вывод результата
    echo $html;

} catch (Exception $e) {
    // Обработка ошибок
    $errorMessage = "Ошибка: " . $e->getMessage();
    error_log($errorMessage);

    echo json_encode([
        'status' => 'error',
        'message' => $errorMessage,
        'details' => [
            'url' => $pageURL ?? 'undefined',
            'time' => date('Y-m-d H:i:s')
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} finally {
    // Не закрываем сессию явно, чтобы можно было повторно использовать
    if (isset($driver)) {
        try {
            $driver->executeScript("console.log('Session kept alive')");
        } catch (Exception $e) {
            // Сессия уже мертва, очищаем файл
            if (file_exists('session_id.txt')) {
                unlink('session_id.txt');
            }
        }
    }
}