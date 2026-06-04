<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
require 'vendor/autoload.php';
require_once 'defines.php';

//require 'src/ftp.php';

use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverWait;
use Facebook\WebDriver\WebDriverExpectedCondition;
use Facebook\WebDriver\Firefox\FirefoxOptions;

// Перед запуском скрипта нужно выполнить в терминале команду для запуска образа докера с webdriver firefox
// Сначала проверить есть ли запущенный контейнер командой — 'docker ps'
// Если нет то — 'docker run -d -p 4444:4444 -p 7900:7900 --shm-size="2g" selenium/standalone-firefox:4.27.0-20241204'
//Вот это не менять

//$pageURL = $_REQUEST["PAGE_URL"] ?? MAIN_PAGE;
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
//
//$options = new FirefoxOptions();
//
//// Указываем путь для скачивания файлов
//$options->addArguments(["--headless", "--disable-gpu", "--no-sandbox"]);
//$options->setExperimentalOption('prefs', [
//	"download.default_directory" => "/var/www/html/upload",  // Путь для скачивания
//	"download.prompt_for_download" => false, // Отключаем запросы на скачивание
//	"safebrowsing.enabled" => true // Включаем безопасный режим
//]);

// Firefox
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

	// Ожидаем, пока элемент не появится
//	$wait = new WebDriverWait($driver, 30);
//	$wait->until(WebDriverExpectedCondition::visibilityOfElementLocated(WebDriverBy::id('main')));

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
//	$html = $driver->getPageSource(); //Вот тут загружается весь html страницы
	//
	//$ftp = new FTP();
	//$ftp->conn('lsleadqn.beget.tech', 'lsleadqn_total', '4nH&0N2g');

//	file_put_contents("test.html", $html);
	//$ftp->put('/armada.leadspace-tech.ru/public_html/local/parser/krasnodar.html', '/home/seleniumParser/test.html');
}
//echo $html;