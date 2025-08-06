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

$sessionId = $_REQUEST["sessionId"] ?? 0;
$existingSessionId = file_get_contents('session_id.txt');
if (!$existingSessionId) {
	echo json_encode([
		"status" => "error",
		"message" => "Нет открытой сессии",
		"data" => []
	], JSON_UNESCAPED_UNICODE);
	exit;
}


//if ($sessionId != $existingSessionId) {
//	echo json_encode([
//		"status" => "error",
//		"message" => "Id сессии не совпадает с существующим",
//		"data" => [
//			"sessionId" => $existingSessionId
//		]
//	]);
//	exit;
//}
$error = "";
try {
	$driver = RemoteWebDriver::createBySessionID($existingSessionId, SERVER_URL);
	//Вот это ОБЯЗАТЕЛЬНО!! в самом конце скрипта иначе потом будет тяжко убить незакрытую сессию браузера, а новую он не даст открыть
	$driver->quit();
} catch (Exception $e) {
	$error = json_encode([
		"status" => "error",
		"message" => "Ошибка: " . $e->getMessage() . "\n",
		"data" => []
	], JSON_UNESCAPED_UNICODE);
	echo $error;
	echo "<pre>" . print_r("Обнови страницу", true) . "</pre>";
} finally {
	file_put_contents('session_id.txt', "");
}

if (!$error) {
	echo json_encode([
		"time" => date("Y-m-d H:i:s"),
		"status" => "success",
		"message" => "Сессия закрыта",
		"data" => []
	], JSON_UNESCAPED_UNICODE);
}