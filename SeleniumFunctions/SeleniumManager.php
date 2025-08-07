<?php

namespace SeleniumFunctions;

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
use Facebook\WebDriver\Firefox\FirefoxOptions;
use Exception;

header('Content-Type: text/html; charset=utf-8');

class SeleniumManager
{
    private const MAX_RETRIES = 3;
    private const SESSION_FILE = 'session_id.txt';
    private const REQUESTS_LOG = 'requests_log.txt';

    private $driver;
    private $sessionId;

    /**
     * Инициализирует или восстанавливает сессию WebDriver
     */
    public function initializeWebDriver()
    {
        $attempt = 0;

        while ($attempt < self::MAX_RETRIES) {
            try {
                // Пытаемся восстановить существующую сессию
                if ($this->tryRestoreSession()) {
                    return $this->driver;
                }

                // Создаем новую сессию
                $this->createNewSession();
                return $this->driver;
            } catch (Exception $e) {
                $attempt++;
                sleep(2);
                $this->cleanupSession();
            }
        }

        throw new Exception("Не удалось инициализировать WebDriver после " . self::MAX_RETRIES . " попыток");
    }

    /**
     * Пытается восстановить существующую сессию
     */
    private function tryRestoreSession(): bool
    {
        if (file_exists(self::SESSION_FILE)) {
            $this->sessionId = file_get_contents(self::SESSION_FILE);
            if (!empty($this->sessionId)) {
                $this->driver = RemoteWebDriver::createBySessionID($this->sessionId, SERVER_URL);
                // Проверяем активность сессии
                $this->driver->getCurrentUrl();
                return true;
            }
        }
        return false;
    }

    /**
     * Создает новую сессию WebDriver
     */
    private function createNewSession()
    {
        $options = new FirefoxOptions();
        $options->setPreference("browser.download.dir", "/home/seluser/Desktop");
        $options->setPreference("download.prompt_for_download", false);
        $options->setPreference("safebrowsing.enabled", true);
        $options->setPreference("browser.download.folderList", 2);

        $capabilities = DesiredCapabilities::firefox();
        $capabilities->setCapability(FirefoxOptions::CAPABILITY, $options);

        $this->driver = RemoteWebDriver::create(SERVER_URL, $capabilities);
        $this->sessionId = $this->driver->getSessionID();
        file_put_contents(self::SESSION_FILE, $this->sessionId);
    }

    /**
     * Очищает данные сессии
     */
    private function cleanupSession()
    {
        if (file_exists(self::SESSION_FILE)) {
            unlink(self::SESSION_FILE);
        }
        $this->sessionId = null;
        $this->driver = null;
    }

    /**
     * Безопасное получение HTML страницы
     */
    public function getPageHtmlSafe($url): string
    {
        try {
            // Способ 1: Через executeScript с Base64 кодированием
            $html = $this->driver->executeScript("
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
            $html = $this->driver->getPageSource();
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

    /**
     * Запускает новую сессию
     */
    public function startSession()
    {
        if (file_exists(self::SESSION_FILE)) {
            $existingSessionId = file_get_contents(self::SESSION_FILE);
            if ($existingSessionId) {
                return [
                    "status" => "error",
                    "message" => "Сессия уже запущена",
                    "data" => ["sessionId" => $existingSessionId]
                ];
            }
        }

        $this->createNewSession();

        try {
            $this->driver->get(MAIN_PAGE);
            $wait = new WebDriverWait($this->driver, 30);
            $wait->until(WebDriverExpectedCondition::visibilityOfElementLocated(WebDriverBy::id('main')));
        } catch (Exception $e) {
            return [
                "status" => "error",
                "message" => "Ошибка: " . $e->getMessage(),
                "data" => []
            ];
        }

        return [
            "time" => date("Y-m-d H:i:s"),
            "status" => "success",
            "message" => "Браузер открыт",
            "data" => ["sessionId" => $this->sessionId]
        ];
    }

    /**
     * Получает информацию о текущих сессиях через Selenium Grid/Standalone API
     */
    public function getSessionsInfo()
    {
        try {
            // Новый API endpoint для Selenium 4+
            $selenium_host = "http://localhost:4444"; // или ваш SERVER_URL
            $url = $selenium_host . "/status";

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Accept: application/json'
            ]);

            $response = curl_exec($ch);

            if ($response === false) {
                return [
                    "status" => "error",
                    "message" => "Ошибка получения статуса: " . curl_error($ch)
                ];
            }

            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200) {
                return [
                    "status" => "error",
                    "message" => "Сервер вернул код $httpCode",
                    "response" => $response
                ];
            }

            $responseData = json_decode($response, true);

            // Для Selenium 4+ структура ответа изменилась
            if (isset($responseData['value']['nodes'])) {
                $sessions = [];
                foreach ($responseData['value']['nodes'] as $node) {
                    if (isset($node['slots'])) {
                        foreach ($node['slots'] as $slot) {
                            if (isset($slot['session'])) {
                                $sessions[] = $slot['session'];
                            }
                        }
                    }
                }

                return [
                    "status" => "success",
                    "data" => [
                        "sessions" => $sessions,
                        "count" => count($sessions)
                    ]
                ];
            }

            return [
                "status" => "success",
                "data" => [
                    "sessions" => [],
                    "count" => 0,
                    "raw_response" => $responseData
                ]
            ];
        } catch (Exception $e) {
            return [
                "status" => "error",
                "message" => "Исключение: " . $e->getMessage()
            ];
        }
    }

    /**
     * Выполняет основной сценарий работы
     */
public function executeMainScenario(array $requestParams)
{
    try {
        // Проверяем активность сессии
        if (!$this->driver || !$this->isSessionActive()) {
            $this->initializeWebDriver();
        }

        $pageURL = $requestParams['PAGE_URL'];
        $scroll = $requestParams['SCROLL'] ?? false;

        $this->driver->get($pageURL);
        $this->waitForPageLoad();

        if ($scroll) {
            $this->scrollPage();
        }

        $html = $this->getPageHtmlSafe($pageURL);
        $html = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $html);

        // Возвращаем чистый HTML без JSON обертки
        return $html;

    } catch (Exception $e) {
        // Возвращаем строку с ошибкой
        return "Error: " . $e->getMessage();
    }
}

    /**
     * Ожидает загрузки страницы
     */
    private function waitForPageLoad(int $timeout = 15)
    {
        $wait = new WebDriverWait($this->driver, $timeout);
        $wait->until(function ($driver) {
            return $driver->executeScript("return document.readyState === 'complete';");
        });
    }

    private function isSessionActive(): bool
    {
        try {
            $this->driver->getTitle(); // Простая проверка
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Прокручивает страницу и кликает по элементам
     */
    private function scrollPage()
    {
        $this->driver->executeScript("window.scrollTo(0, document.body.scrollHeight);");
        sleep(2);

        $elements = $this->driver->findElements(WebDriverBy::cssSelector("a.tabs__button"));
        $downloadedElements = [];

        foreach ($elements as $element) {
            try {
                $elementDownloadLink = $element->getAttribute("download");
                if (!$elementDownloadLink) continue;

                $elementDownloadLink = str_replace("/", "_", $elementDownloadLink);
                $downloadedElements[] = $elementDownloadLink;

                $this->driver->executeScript('arguments[0].click();', [$element]);
                sleep(1);
            } catch (Exception $e) {
                error_log("Click error: " . $e->getMessage());
            }
        }
    }

    /**
     * Логирует запрос
     */
    private function logRequest(array $requestParams)
    {
        $logData = array_merge(
            ['time' => date("Y-m-d H:i:s")],
            $requestParams
        );
        file_put_contents(self::REQUESTS_LOG, print_r($logData, true), FILE_APPEND);
    }

    /**
     * Обрабатывает ошибки
     */
    private function handleError(Exception $e, string $url)
    {
        $errorMessage = "Ошибка: " . $e->getMessage();
        error_log($errorMessage);

        return json_encode([
            'status' => 'error',
            'message' => $errorMessage,
            'details' => [
                'url' => $url,
                'time' => date('Y-m-d H:i:s')
            ]
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * Закрывает сессию
     */
    public function __destruct()
    {
        if ($this->driver) {
            try {
                $this->driver->executeScript("console.log('Session kept alive')");
            } catch (Exception $e) {
                $this->cleanupSession();
            }
        }
    }
}
