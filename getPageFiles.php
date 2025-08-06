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

$pageURL = $_REQUEST["PAGE_URL"] ?? "https://krasnodar.tstn.ru/product/material-krovelnyy-tekhnonikol-unifleks-vent-epv/";
$_REQUEST["TIME"] = date("Y-m-d H:i:s");
$scroll = $_REQUEST["SCROLL"] ?? false;

file_put_contents(date("Y-m-d") . "_requests.txt", print_r($_REQUEST, true), FILE_APPEND);


$existingSessionId = file_get_contents('session_id.txt');
if (!$existingSessionId) {
	echo json_encode([
		"status" => "error",
		"message" => "Нет открытой сессии",
		"data" => []
	], JSON_UNESCAPED_UNICODE);
	exit;
}

$driver = RemoteWebDriver::createBySessionID($existingSessionId, SERVER_URL);

try {
	$currentPage = $driver->getCurrentURL();
	if ($currentPage != $pageURL) {
		// Переходим на страницу
		$driver->get($pageURL);
	}

	if ($scroll) {
		$driver->executeScript("window.scrollTo(0, document.body.scrollHeight);");
		sleep(10);
	}


} catch (Exception $e) {
	echo json_encode([
		"status" => "error",
		"message" => "Ошибка: " . $e->getMessage() . "\n",
		"data" => []
	], JSON_UNESCAPED_UNICODE);
	exit;
} finally {
	// Находим все элементы по CSS-селектору
	$wait = new WebDriverWait($driver, 10); // 10 секунд ожидания
	$wait->until(WebDriverExpectedCondition::elementToBeClickable(WebDriverBy::cssSelector("a.tabs__button"))); // Ждем, пока элемент станет кликабельным
	$elements = $driver->findElements(WebDriverBy::cssSelector('a.tabs__button'));
// Прокликаем все найденные элементы
	foreach ($elements as $element) {
		try {
			$elementDownloadLink = $element->getAttribute("download");
			if (!$elementDownloadLink) continue;
			$elementDownloadLink = str_replace("/", "_", $elementDownloadLink);
			exec("docker exec selenium_grid rm -f /home/seluser/Desktop/$elementDownloadLink");
			$element->click(); // Кликаем на элемент
			sleep(2);
			exec("docker cp selenium_grid:/home/seluser/Desktop/$elementDownloadLink /var/www/html/upload/$elementDownloadLink");
		} catch (Exception $e) {
			echo "Не удалось кликнуть на элемент: " . $e->getMessage() . "\n";
		}
	}
}
