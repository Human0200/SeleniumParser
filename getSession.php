<?php

// Адрес вашего Selenium WebDriver (например, localhost или IP контейнера Docker)
$selenium_host = "http://localhost:4444";

// URL для получения всех сессий
$url = $selenium_host . "/wd/hub/sessions";

// Инициализируем cURL
$ch = curl_init();

// Устанавливаем cURL параметры для отправки GET запроса
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);      // Возвращаем результат как строку
curl_setopt($ch, CURLOPT_HTTPHEADER, [
	'Content-Type: application/json',
]);

// Выполняем запрос
$response = curl_exec($ch);

// Проверяем наличие ошибок в запросе
if ($response === false) {
	echo "Ошибка получения сессий: " . curl_error($ch);
} else {
	// Декодируем JSON ответ
	$response_data = json_decode($response, true);

	// Проверяем, есть ли активные сессии
	if (isset($response_data['value']) && is_array($response_data['value'])) {
		echo "Активные сессии:\n";
		print_r($response_data);
		foreach ($response_data['value'] as $session) {
			echo "Session ID: " . $session['id'] . "\n";
		}
	} else {
		echo "Нет активных сессий.\n";
	}
}

// Закрываем соединение cURL
curl_close($ch);

?>