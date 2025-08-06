<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
require 'vendor/autoload.php';
require_once 'defines.php';

use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverWait;
use Facebook\WebDriver\WebDriverExpectedCondition;
use Facebook\WebDriver\Firefox\FirefoxOptions;

$existingSessionId = file_get_contents('session_id.txt');
if ($existingSessionId) {
	echo json_encode([
		"status" => "error",
		"message" => "Сессия уже запущена",
		"data" => [
			"sessionId" => $existingSessionId
		]
	], JSON_UNESCAPED_UNICODE);
	exit;
}

$options = new FirefoxOptions();

// Указываем путь для скачивания файлов
//$options->addArguments(["--headless", "--disable-gpu", "--no-sandbox"]);
$options->setPreference("browser.download.dir", "/home/seluser/Desktop");  // Путь для скачивания
$options->setPreference("download.prompt_for_download",false); // Отключаем запросы на скачивание
$options->setPreference("safebrowsing.enabled", true); // Включаем безопасный режим
$options->setPreference("browser.download.folderList", 2); // 0 - desktop, 1 - default, 2 - download.dir

$capabilities = DesiredCapabilities::firefox();
$capabilities->setCapability(FirefoxOptions::CAPABILITY, $options);

$driver = RemoteWebDriver::create(SERVER_URL, $capabilities);
$sessionId = $driver->getSessionID();
file_put_contents('session_id.txt', $sessionId);

try {
	// Переходим на страницу
	$driver->get(MAIN_PAGE);

	// Ожидаем, пока элемент не появится
	$wait = new WebDriverWait($driver, 30);
	$wait->until(WebDriverExpectedCondition::visibilityOfElementLocated(WebDriverBy::id('main')));

} catch (Exception $e) {
	echo json_encode([
		"status" => "error",
		"message" => "Ошибка: " . $e->getMessage() . "\n",
		"data" => []
	], JSON_UNESCAPED_UNICODE);
	exit;
}

echo json_encode([
	"time" => date("Y-m-d H:i:s"),
	"status" => "success",
	"message" => "Браузер открыт",
	"data" => [
		"sessionId" => $sessionId
	]
], JSON_UNESCAPED_UNICODE);