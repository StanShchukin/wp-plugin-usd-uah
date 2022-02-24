<?php
	
	/**
	 * Plugin Name: USD->UAH
	 * Description: USD conversion plugin -> UAH through DB (MinFin, PrivatBank)
	 * Version: 1.1
	 * Author: Stanislav Shchukin
	 * Author URI: http://digitaldealerz.com
	 */
	
	// Make sure we don't expose any info if called directly
	if ( !function_exists( 'add_action' ) ) {
		echo 'Hi there!  I\'m just a plugin, not much I can do when called directly.';
		exit;
	}
	
	add_action( 'init', 'usd_rates' );
	function usd_rates(){
		return new EX_rates();
	}
	
	
	/**
	 * $ex_rates = new EX_rates();
	 * var_dump($ex_rates->coursesInArray()); // Вернёт массив с курсами валют
	 *
	 */
	
	
	class EX_rates {
		
		//Задаём интервал обновления (в секундах) "43200" - половина суток (12 часов)
		CONST interval = 43200;
		//Путь, откуда забираем данные
		//CONST source = 'http://www.cbr.ru/scripts/XML_daily.asp';
		
		//API MinFin 1e60571cbf052179572d0950b09cbba4c4054cab
		CONST source = "http://api.minfin.com.ua/nbu/1e60571cbf052179572d0950b09cbba4c4054cab/";
		//CONST source = "http://api.minfin.com.ua/mb/1e60571cbf052179572d0950b09cbba4c4054cab/";
		
		// PrivatBank
		//CONST source = "https://api.privatbank.ua/p24api/pubinfo?exchange&coursid=5";
		
		CONST options = array(
			CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_USERAGENT      => 'FinApplicationBot/1.0 (http://test.com)'
		);
		//Здесь будет WP'шный лежать объект для работы с БД
		private $wpdb;
		//Здесь будут имена таблиц
		private $table_rates;
		private $table_log;
		
		/**
		 * В конструкторе определяем объект для работы с БД, имена таблиц, и если
		 * надо то обновляем данные
		 */
		public function __construct() {
			global $wpdb;
			//Устанавливаем объект для работы с БД
			$this->wpdb = $wpdb;
			//Устанавливам имена таблиц
			$this->table_rates = $this->wpdb->prefix . 'ex_rates';
			$this->table_log   = $this->wpdb->prefix . 'ex_log';
			
			//(Текущее время - время последнего обновления), если оно больше чем
			//заданный интервал, то обновляем данные
			
			if ( ( ( time() - $this->updatedTime() ) > self::interval ) && ($this->demand_course() == true )) {
				$this->refresh();
			}
		}
		
		private function demand_course() {
			if ( ! $xml = curl_init( self::source ) ) {
				return false;
			}
			curl_setopt_array( $xml, self::options );
			$page = curl_exec( $xml );
			curl_close( $xml );
			unset( $xml );
			$rate = array();
			$rate = json_decode( $page, true );
			
			if ( ( count( $rate ) ) == 0 ) {
				return false;
			}
			
			return true;
		}
		
		/**
		 * Возвращает все данные из таблицы с курсами в виде объекта
		 * @return object
		 */
		public function courses() {
			return $this->wpdb->get_results( 'SELECT * FROM ' . $this->table_rates );
		}
		Digital Dealerz
		/**
		 * Возвращает данные из таблицы с курсами в массиве
		 * @return array
		 */
		public function coursesInArray() {
			foreach ( $this->courses() as $rate ) {
				$arr[ $rate->code ] = array(
					'value' => $rate->course,
				);
			}
			
			return $arr;
		}
		
		/**
		 * Возвращает данные['usd'] из таблицы с курсами в виде string
		 * @return string
		 */
		public function coursesUsd() {
			$usd = $this->wpdb->get_var( 'SELECT course FROM ' . $this->table_rates . ' WHERE code="usd"' );
			
			return $usd;
		}
		
		/**
		 * Возвращает все данные из таблицы с курсами в виде объекта
		 * @return object update
		 * @return boolean
		 */
		// make to -> privat
		public function updateFlat() {
			//обновляем курс в админке
			$usd = $this->coursesUsd();
			
			$this->wpdb->update(
				$this->wpdb->postmeta,
				array( 'meta_value' => $usd ),
				array( 'meta_key' => 'p_price_current_usd' )
			);
			//обновили...  перемножаем usd->uah
			
			$this->wpdb->query(
				'UPDATE '.$this->wpdb->postmeta. ' t1
			   JOIN '.$this->wpdb->postmeta.' t2 ON t2.post_id = t1.post_id
			   AND t1.meta_key IN ("p_price_data","p_price_current_data")
SET t1.meta_value = (SELECT t2.meta_value
                       WHERE t2.meta_key="p_price_data"  ) *'. $usd .
				' WHERE t1.meta_key = "p_price_current_data" AND
      (SELECT t1.post_id WHERE t1.meta_key = "p_price_current_data"  AND t2.meta_key = "p_price_data");');
			
			return true;
			
			/** для прямого запроса в SQL
			UPDATE  wp_postmeta   t1
			   JOIN  wp_postmeta   t2 ON t2.post_id = t1.post_id
			                             AND t1.meta_key IN ("p_price_data","p_price_current_data")
SET t1.meta_value = (SELECT t2.meta_value
                       WHERE t2.meta_key="p_price_data"  ) *   55
  WHERE t1.meta_key = "p_price_current_data" AND
        (SELECT t1.post_id WHERE t1.meta_key = "p_price_current_data"  AND t2.meta_key = "p_price_data")
			*/
			
		}
		
		
		
		/**
		 * Возвращает время последнего обновления данных в формате unix timestamp
		 * @return int
		 */
		private function updatedTime() {
			return $this->wpdb->get_var( 'SELECT updated FROM ' . $this->table_log );
		}
		
		/**
		 * Обновляет данные в таблице
		 * @return boolean
		 */
		private function refresh() {
			//Если не удалось загрузить xml файл, то возвращаем false;
			if ( ! $xml = curl_init( self::source ) ) {
				return false;
			}
			//Очищаем таблицу с курсами
			if ( ! $this->wpdb->query( 'TRUNCATE TABLE ' . $this->table_rates . ';', '%s' ) ) {
				return false;
			}
			//Очищаем таблицу с логом
			if ( ! $this->wpdb->query( 'TRUNCATE TABLE ' . $this->table_log .';', '%d' ) ) {
				return false;
			}
			curl_setopt_array( $xml, self::options );
			// Скачиваем
			$page = curl_exec( $xml );   //В переменную $page помещается страница
			// Закрываем соединение и обнуляем переменную
			curl_close( $xml );
			unset( $xml );
			// MinFin
			$rate = array();
			$rate = json_decode( $page, true );
			
			
			
			
			// PrivatBank
			//$p_price_course = new SimpleXMLElement($page);
			//$p_price_course=$p_price_course->row[0]->exchangerate['sale'];
			//return $p_price_course;
			
			//Прогоняем в цикле валюты
			foreach ( $rate as $rates => $value ) {
				//Формируем массив с курсами
				$ex_rates = array(
					'code'   => $value['currency'],
					'course' => $value['ask']
				);
				
				//Вставлям массив с курсами в БД.
				if ( ! $this->wpdb->insert( $this->table_rates, $ex_rates ) ) {
					return false;
				}
			}

			//Записываем время обновления данных (текущее время)
			if ( ! $this->wpdb->insert( $this->table_log, array( 'updated' => time() ) ) ) {
				return false;
			}
			
			// Обновляет в БД курсы всех квартир
			$this->updateFlat();
			
			return true;
		}
	}
	
	/**
	 * Активатор
	 * Создаёт две таблицы в БД
	 * @global type $wpdb
	 */
	function ex_rates_activation() {
		//Это глобальный объект для работы с БД
		global $wpdb;
		//Выполняем запросы (создаём две таблицы)
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'ex_rates;' );
		$wpdb->query(
			'CREATE TABLE IF NOT EXISTS ' . $wpdb->prefix . 'ex_rates (
		code_id BIGINT(20) NOT NULL AUTO_INCREMENT ,
       code VARCHAR(3) NOT NULL,
       course DECIMAL(8,4) NOT NULL,
       PRIMARY KEY (code_id))
     ENGINE = InnoDB;' );
		
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'ex_log;' );
		$wpdb->query(
			'CREATE TABLE IF NOT EXISTS ' . $wpdb->prefix . 'ex_log (
       updated INT NOT NULL,
       PRIMARY KEY (updated))
     ENGINE = InnoDB;' );
	}
	
	/**
	 * Удаление плагина
	 * Удаляет таблицы из БД
	 * @global type $wpdb
	 */
	function ex_rates_uninstall() {
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'ex_rates;' );
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'ex_log;' );
	}

//Регистрируем хук установки
	register_activation_hook( __FILE__, 'ex_rates_activation' );
//Регистрируем хук удаления
	register_uninstall_hook( __FILE__, 'ex_rates_uninstall' );
	
	
