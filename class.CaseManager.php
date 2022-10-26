<?php

/**
 *
 */
class CaseManager {

	public $debugMode = true;   									// Включить журнал отладки
	public $debugLogFile = 'cron/updateIndexes/logs/update.txt';	// Расположение логов
	public $apiURL = '';											// Адрес API сервиса
	public $caseId = '';											// Идентификатор портфеля
	public $caseTitle = '';											// Название портфеля
	public $casePrice = 0;											// Цена портфеля
	public $indexesNumber = 0;
	public $caseIsin = '';											// ISIN портфеля
	public $dateModify = '14 Days';									// За какой период данные получить с сервера
	public $dateFormat = 'd-m-Y';									// Формат даты

	public $beginDate = null;										// Дата начала запроса
	public $endDate = null;											// Дата окончания запроса

	private $isinMissed = 0;
	private $isinUpdated = 0;

	private $priceUpdated = 0;
	private $priceMissed = 0;

	private $indexInserted = 0;
	private $indexUpdated = 0;
	private $indexMissed = 0;

	private $messages = [
		'FUNDS_NOT_FOUND' 	     => 'Не переданы данные фондов.',
		'CASE_FUND_NOT_FOUND'    => 'Фонд портфеля не найден.',
		'CASE_FUND_INACTIVE'     => 'Фонд портфеля не активен.',
		'CASE_NOT_FOUND'    	 => 'Запрашиваемый портфель не найден',
		'CASE_INDEXES_CLEARED'	 => 'Индексы портфеля очищены',
		'CASE_ISIN_UPDATED'      => 'Обновлен ISIN.',
		'CASE_ISIN_UPDATE_ERROR' => 'Ошибка при обновлении ISIN',
		'CASES_NOT_FOUND'        => 'Нет портфелей для получения данных.',
		'CASE_UPDATE_ERROR'      => 'Ошибка при обновлении портфеля ',
		'CASE_UPDATE_OFF'        => 'Автообновление портфеля отключено.',
		'CASE_UPDATED' 	         => 'Обновлен портфель ',
		'PRICE_UPDATED' 	     => 'Обновлена цена.',
		'PRICE_DATE_NOT_FOUND'   => 'Не передана цена или дата.',
		'PRICE_UPDATE_ERROR'     => 'Ошибка обновления цены.',
		'PRICE_UPDATE_OFF'       => 'Автообновление цены портфеля отключено.',
		'INDEXES_NOT_FOUND'      => 'Не переданы индексы портфеля.',
		'ADDED_INDEX'            => 'Добавлен индекс за ',
		'ADD_INDEX_ERROR'        => 'Ошибка добавления индекса за ',
		'UPDATED_INDEX'          => 'Обновлен индекс за ',
		'UPDATE_INDEX_ERROR'     => 'Ошибка обновления индекса за ',
		'INDEXES_GET_ERROR'      => 'Ошибка при получении индесков ',
		'INDEX_PRICE_DATE_ERROR' => 'Не передана цена или дата индекса.',
		'TOTAL_CASES_INSERTED'   => 'Добавлено портфелей: ',
		'TOTAL_CASES_UPDATED'    => 'Обновлено портфелей: ',
		'TOTAL_CASES_MISSED'     => 'Пропущено портфелей: ',
		'TOTAL_INDEXES_INSERTED' => 'Добавлено индексов: ',
		'TOTAL_INDEXES_UPDATED'  => 'Обновлено индексов: ',
		'TOTAL_INDEXES_MISSED'   => 'Пропущено индексов: ',
		'TOTAL_PRICE_UPDATED'    => 'Обновлено цен: ',
		'TOTAL_PRICE_MISSED'     => 'Пропущено цен: ',
		'TOTAL_ISIN_UPDATED'     => 'Обновлено ISIN: ',
		'TOTAL_ISIN_MISSED'      => 'Пропущено ISIN: ',
	];

	public function __construct($options = null) {
		file_put_contents($this->debugLogFile, "");
   	}

   	public function __destruct() {

   	}


	/**
	 * Устанавливаем период
	 */
	public function setPeriod() {
		$endDate = new DateTime('today');
		$beginDate = new DateTime('today');
		$beginDate->modify('-'.$this->dateModify);

		$this->endDate = $endDate->format('d-m-Y');
		$this->beginDate = $beginDate->format('d-m-Y');
	}


	/**
	 * Устанавливаем новый ISIN портфеля
	 */
	public function setCaseIsin($isin) {

		$resultUpdate = db_query("
			UPDATE
				".T_PREFIX."module_case
			SET
				isin = '".$isin."'
			WHERE
				case_id = ".$this->caseId
		);


		if (!empty($resultUpdate)) {
			$this->isinUpdated++;
			$this->debugLog('CASE_ISIN_UPDATED');
		} else {
			$this->isinMissed++;
			$this->debugLog('CASE_ISIN_UPDATE_ERROR');
		}
	}


	/**
	 * Получаем данные фондов
	 */
	public function getFunds($url) {
		$result = file_get_contents($url, false, stream_context_create($this->arrContextOptions));
		$funds = !empty($result) ? json_decode($result, true) : false;

		if (!empty($funds['items'])) {
			$items = $funds['items'];

			if (!empty($funds['next']['$ref'])) {
				$nextPageItems = $this->getFunds($funds['next']['$ref']);
			}
		}


		if (!empty($nextPageItems)) {
			$items = array_merge($items, $nextPageItems);
		}

		return !empty($items) ? $items : false;
	}


	/**
	 * Получаем все портфели для обновления из базы данных
	 *
	 * @return array $cases Данные портфелей
	 */
	public function getCases() {
		$cases = db_get_array("
			SELECT
				*
			FROM
				".T_PREFIX."module_case
			WHERE
				lang_id = 1
				AND is_published = 1
		","","","case_id");


		if (!empty($cases)) {
			return $cases;
		}

		$this->debugLog('CASES_NOT_FOUND');
	}


	/**
	 * Получаем ссылку для получения индексов по идентификатору фонда
	 */
	public function getIndexURLByFundId() {
		return $this->apiURL.'/getINDXP/'.$this->caseId.'/'.$this->beginDate.'/'.$this->endDate;
	}


	/**
	 * Получаем ссылку для получения индексов по ISIN
	 */
	public function getIndexURLByISIN() {
		return $this->apiURL.'/getINDXP/'.$this->caseIsin.'/'.$this->beginDate.'/'.$this->endDate;
	}


	/**
	 * Получаем ссылку для получения цены по идентификатору фонда
	 */
	public function getPriceURLByFundId() {
		return $this->apiURL.'/getUnitPrice/'.$this->caseId.'/'.$this->endDate;
	}


	/**
	 * Получаем ссылку для получения цены по ISIN
	 */
	public function getPriceURLByISIN() {
		return $this->apiURL.'/getUnitPrice/'.$this->caseIsin.'/'.$this->endDate;
	}


	/**
	 * Получаем цену портфеля с веб-сервиса
	 *
	 * @param string $url Ссылка на веб-сервис
	 *
	 * @return array|bool $case Данные портфеля с ценой и датой или false, если данные не переданы
	 */
	public function getPriceFromWeb($url) {
		$item = file_get_contents($url, false, stream_context_create($this->arrContextOptions));
		return !empty($item) ? json_decode($item, true) : false;
	}


	/**
	 * Получаем цену портфеля
	 *
	 * @return array|bool $case Данные портфеля с ценой и датой или false, если данные не переданы
	 */
	public function getCasePrice() {
		$url = $this->getPriceURLByISIN();
		$case = $this->getPriceFromWeb($url);

		if (empty($case)) {
			$url = $this->getPriceURLByFundId();
			$case = $this->getPriceFromWeb($url);
		}

		if (!empty($case['items'][0]['dt']) && !empty($case['items'][0]['nom_price'])) {
			return $case;
		} else {
			$this->priceMissed++;
			$this->debugLog('PRICE_DATE_NOT_FOUND');
		}
	}


	/**
	 * Обновляем цену портфеля в базе данных
	 *
	 * @param array $case Массив с ценой и датой
	 */
	public function updatePrice($case) {

		if (empty($case['dt']) || empty($case['nom_price'])) {
			return false;
		}

		$date = $case['dt'];
		$price = str_replace(',', '.', round($case['nom_price'], 2));

		/*
		if ($price == $this->casePrice) {
			return false;
		}
		*/

		$resultUpdate = db_query("
			UPDATE
				".T_PREFIX."module_case
			SET
				price = ".$price.",
				date = STR_TO_DATE('".$date."', '%d-%m-%Y'),
				date_update = NOW()
			WHERE
				case_id = ".$this->caseId
		);


		if (!empty($resultUpdate)) {
			$this->priceUpdated++;
			$this->debugLog('PRICE_UPDATED');
		} else {
			$this->priceMissed++;
			$this->debugLog('PRICE_UPDATE_ERROR');
		}
	}


	/**
	 * Получаем список индексов
	 *
	 * @return array|false $indexList Массив с данными индексов или false, если индексы не переданы
	 */
	public function getIndexList() {

		$url = $this->getIndexURLByISIN();
		$indexList = $this->getIndexListFromWeb($url);

		if (empty($indexList)) {
			$url = $this->getIndexURLByFundId();
			$indexList = $this->getIndexListFromWeb($url);
		}

		if (!empty($indexList)) {
			return $indexList;
		} else {
			$this->indexMissed++;
			$this->debugLog('INDEXES_GET_ERROR');

			return false;
		}
	}


	/**
	 * Получаем индексы с веб-сервиса
	 *
	 * @param string $url Ссылка на веб-сервис
	 */
	public function getIndexListFromWeb($url) {
		$caseIndexList = file_get_contents($url, false, stream_context_create($this->arrContextOptions));
		$indexList = !empty($caseIndexList) ? json_decode($caseIndexList, true) : false;

		if (!empty($indexList['items'])) {
			$items = $indexList['items'];

			if (!empty($indexList['next']['$ref'])) {
				$nextPageItems = $this->getIndexListFromWeb($indexList['next']['$ref']);
			}
		}


		if (!empty($nextPageItems)) {
			$items = array_merge($items, $nextPageItems);
		}

		return !empty($items) ? $items : null;
	}


	/**
	 * Проверяем наличие индексов в БД
	 */
	public function checkIndexesInDB() {
		return db_get_data("
			SELECT
				*
			FROM
				".T_PREFIX."content_case
			WHERE
				case_id = ".$this->caseId."
			LIMIT
				1
		");
	}


	/**
	 * Проверяет индексы и готовит их к записи в БД
	 *
	 * @param array $indexList Массив с индексами веб-сервиса
	 */
	public function checkIndexes($indexList) {
		$indexValues = '';

		if (empty($indexList)) {
			$this->debugLog('INDEXES_NOT_FOUND');
			return false;
		}


		foreach ($indexList as $key => $item) {
			$indexDateDB = null;

			if (empty($item['market_price_date']) || empty($item['market_price'])) {
				$this->debugLog('INDEX_PRICE_DATE_NOT_FOUND');
				unset($indexList[$key]);

				continue;
			}

			$indexValues .= "(".$this->caseId.", '".$this->caseTitle."', ".str_replace(',', '.', $item['market_price']).", STR_TO_DATE('".$item['market_price_date']."', '%d-%m-%Y'), NOW()),";
		}

		if (!empty($indexValues)) {
			$this->indexesNumber = count($indexList);
			$this->addIndexesInDB($indexValues);
			$this->indexesNumber = 0;
		} else {
			$this->indexMissed++;
			$this->debugLog('CASE_UPDATE_ERROR');
		}
	}


	/**
	 * Добавляет индексы в базу данных
	 *
	 * @param array $indexList Массив с индексами веб-сервиса
	 */
	public function addIndexesInDB($indexValues) {

		if (empty($indexValues)) {
			$this->debugLog('INDEXES_NOT_FOUND');
			return false;
		}

		$resultInsert = db_query("
			INSERT IGNORE INTO
				".T_PREFIX."content_case
				(case_id, case_title, case_index, date, date_update)
			VALUES
				".mb_substr(trim($indexValues), 0, -1)."
		");


		if (!empty($resultInsert)) {
			$this->indexUpdated++;
			$this->debugLog('CASE_UPDATED');
		} else {
			$this->indexMissed++;
			$this->debugLog('CASE_UPDATE_ERROR');
		}
	}


	/**
	 * Удаляет индексы текущего портфеля
	 */
	private function caseIndexesClear() {
		$isNotTodayReload = db_get_data("
			SELECT
				reload_date
			FROM
				".T_PREFIX."module_case
			WHERE
				case_id = ".$this->caseId."
				AND DATE(reload_date) = CURDATE()
		", 'reload_date');

		if (empty($isNotTodayReload)) {
			db_query("
				UPDATE
					".T_PREFIX."module_case
				SET
					reload_date = NOW()
				WHERE
					case_id = ".$this->caseId."
			");

			db_query("
				DELETE FROM
					".T_PREFIX."content_case
				WHERE
					case_id = ".$this->caseId."
			");

			$this->debugLog('CASE_INDEXES_CLEARED');
		}
	}


	/**
	 * Запускает процесс обновления индексов портфеля
	 */
	public function startIndexesUpdate($case, $funds, $years = false, $caseID = false, $reload = false) {
		$this->caseId = $case['case_id'];
		$this->caseTitle = $case['title'];
		$this->casePrice = $case['price'];

		if ($reload) {
			$this->caseIndexesClear();
		}

		$fundId = array_search($case['case_id'], array_column($funds, 'fund_id'));

		if (empty($funds[$fundId])) {
			$this->debugLog('CASE_FUND_NOT_FOUND');
			return false;
		}

		/*
		if (empty($funds[$fundId]['status']) || $funds[$fundId]['status'] != 'active') {
			$this->debugLog('CASE_FUND_INACTIVE');
			continue;
		}
		*/

		if (empty($caseID) && empty($case['autoupdate'])) {
			$this->debugLog('CASE_UPDATE_OFF');
			return false;
		}

		// Проверяем ISIN
		if (!empty($funds[$fundId]['isin']) && $funds[$fundId]['isin'] != $case['isin']) {
			$this->setCaseIsin($funds[$fundId]['isin']);
			$this->caseIsin = $funds[$fundId]['isin'];
		} else {
			$this->caseIsin = $case['isin'];
		}

		$period = $this->checkIndexesInDB() ? '14 Days' : '100 Years';
		$this->dateModify = !empty($years) ? $years.' Years' : $period;
		$this->setPeriod();

		// Получаем цену портфеля
		$casePrice = $this->getCasePrice();
		$this->updatePrice($casePrice['items'][0]);

		// Получаем индексы портфеля
		$indexList = $this->getIndexList();
		$this->checkIndexes($indexList);

		if (!empty($caseID)) {
			echo "Портфель: <strong>".$this->caseTitle."</strong><br /><br />";
			echo "Стоимость условной единицы (<strong>".$this->casePrice."</strong>)<br />";

			if ($casePrice["items"]) {
				foreach ($casePrice["items"] as $item) {
					echo "Дата: ".$item["dt"].", стоимость:".$item["nom_price"]."<br />";
				}
			} else {
				echo "<strong>данные не найдены</strong>";
			}

			if ($indexList) {
				echo "<br />Список индексов<br />";
				foreach($indexList as $index){
					echo "Дата: ".$index["market_price_date"].". Стоимость: ".$index["market_price"]."<br />";
				}
			} else {
				echo "Список индексов не найден<br />";
			}
		}

		return true;
	}


	/**
	 * Выводим результат на экран
	 */
	public function showResult() {
		$this->caseId = null;
		$this->caseTitle = null;

		echo "<br> \n\n";
	//	echo $this->messages['TOTAL_CASES_INSERTED'].' '.$this->indexInserted."<br> \n";
		echo $this->messages['TOTAL_CASES_UPDATED'].' '.$this->indexUpdated."<br> \n";
		echo $this->messages['TOTAL_CASES_MISSED'].' '.$this->indexMissed."<br> \n";
		echo $this->messages['TOTAL_PRICE_UPDATED'].' '.$this->priceUpdated."<br> \n";
		echo $this->messages['TOTAL_PRICE_MISSED'].' '.$this->priceMissed."<br> \n";
		echo $this->messages['TOTAL_ISIN_UPDATED'].' '.$this->isinUpdated."<br> \n";
		echo $this->messages['TOTAL_ISIN_MISSED'].' '.$this->isinMissed."<br> \n";

	//	$this->debugLog('TOTAL_CASES_INSERTED', $this->indexInserted);
		$this->debugLog('TOTAL_CASES_UPDATED', $this->indexUpdated);
		$this->debugLog('TOTAL_CASES_MISSED', $this->indexMissed);
		$this->debugLog('TOTAL_PRICE_UPDATED', $this->priceUpdated);
		$this->debugLog('TOTAL_PRICE_MISSED', $this->priceMissed);
		$this->debugLog('TOTAL_ISIN_UPDATED', $this->isinUpdated);
		$this->debugLog('TOTAL_ISIN_MISSED', $this->isinMissed);
	}


	/**
	 * Подготовка запись в журнал отладки
	 *
	 * @param string $description Строка сообщения в журнал
	 * @param array $data Данные для записи в журнал
	 */
	public function debugLog($description, $data = '') {
		if ($this->debugMode) {

			$message = $this->messages[$description].' '.$data;

			if (!empty($this->caseTitle)) {
				$message .= ' Портфель: '.$this->caseTitle.'. ';
			}

			if (!empty($this->caseId)) {
				$message .= ' Идентификатор портфеля: '.$this->caseId.'. ';
			}

			if (!empty($this->indexesNumber)) {
				$message .= ' Количество обновленных индексов: '.$this->indexesNumber;
			}

		//    $message = iconv("UTF-8", "ISO-8859-5", $str);
			addlog($message, false, $this->debugLogFile);
		}
	}

}
?>
