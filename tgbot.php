<?

include "bhm_functions.php";

$tg_data = file_get_contents('php://input');
$tg_data = json_decode($tg_data, true);

if (isset($tg_data['message'])) { // прислали сообщение
	$message = $tg_data['message']['text'];
	$chat_id = $tg_data['message']['chat']['id'];
	$type = "message";
}

if (isset($tg_data['callback_query'])) { // прислали callback
	$callback_query = $tg_data['callback_query'];
	$message = $callback_query['data'];
	$message_id = ['callback_query']['message']['message_id'];
	$chat_id = $callback_query['message']['chat']['id'];
	$type = "callback";
}

if (isset($tg_data["callback_query"]["message"]["chat"]['type'])) {
	if ($tg_data["callback_query"]["message"]["chat"]['type']!="private") {
		// это группа, ничего не пишем
		exit();
	}
}

if (isset($tg_data["message"]["chat"]['type'])) {
	if ($tg_data["message"]["chat"]['type']!="private") {
		// это группа, ничего не пишем
		exit();
	}
}

$state = get_state($chat_id);

if ($type=="message") { // если юзер прислал сообщение
	switch($message) {
	    case '/start':  
		    bot_welcome_message($chat_id);
			break;
		case '➕ Новая задача':  
		    bot_add_task($chat_id);
			break;
		case '📋 Мои задачи':  
		    $res = mysqli_query($linksql, "SELECT * FROM tasks WHERE user_id='$chat_id' AND max_trade!='0' AND need_profit!='0'");
			$tasks = mysqli_num_rows($res);

			if ($tasks>0) {
				$n = 0;
				$keyboard = '{"inline_keyboard":[';
				while ($task = mysqli_fetch_array($res)) {
					$n++;

					if ($task["type"]=="arbitrage") {
						$type = "Арбитраж";
					}

					$task_name = "🔸" . $task["data"] . " - ".$type . " - min " . $task["need_profit"] . "%";
					$keyboard .= '[{"text":"'.$task_name.'","callback_data":"get-task-'.$task["id"].'"}]';
					if ($n!=mysqli_num_rows($res)) {
						$keyboard .= ",";
					}
				}
				$keyboard .= ']}';
				bot_sendmessage($chat_id, "Ваши активные задачи:", $keyboard);
			} else {
				$inline_button1 = array("text"=>"➕ Создать","callback_data"=>'add-task');
				$inline_keyboard = [[$inline_button1]];
				$keyboard=array("inline_keyboard"=>$inline_keyboard);
				$replyMarkup = json_encode($keyboard); 
				bot_sendmessage($chat_id, "У вас нет активных задач по торговле. Создайте новую", $replyMarkup);
			}
			break;
		case '🆘 Помощь':  
		    bot_sendmessage($chat_id, "Бот работает в тестовом режиме. Сообщить о найденных багах или задать вопросы можно @lkk17");
			break;
		default: // если команда не распознана
			switch($state) {
				case 'add_task_step1':
					$message = trim(strtoupper($message));
					if (bithumb_check_coin($message)) {
						save_state($chat_id, "add_task_step2", $message);
						bot_show_strategies($chat_id);
					} else {
						bot_sendmessage($chat_id, "Указан некорректный тикер. Попробуйте ещё раз.");
					}
					break;
				case 'add_task_step2':
					bot_show_strategies($chat_id);
					break;
				case 'add_task_step3':
					save_state($chat_id, "add_task_step4", $message);
					bot_sendmessage($chat_id, "Сообщите ваш Secret Key для биржи bithumb.pro");
					break;
				case 'add_task_step4':
					$secret_key = trim($message);
					$api_key = get_state_data($chat_id, "add_task_step4");
					$task_id = get_state_data($chat_id, "add_task_step3");

					if (bithumb_check_login($api_key, $secret_key)) {
						bot_sendmessage($chat_id, "Спасибо, данные корректные.");
						mysqli_query($linksql, "UPDATE tasks SET api_key='$api_key', secret='$secret_key' WHERE id='$task_id' AND user_id='$chat_id'");
						bot_sendmessage($chat_id, "<strong>Укажите желаемый % профита</strong> <i>(Например: 1.5%)</i>. Бот будет проводить только сделки, удовлетворяющие данному условию. Профит должен учитывать комиссию биржи.");
						save_state($chat_id, "add_task_step5", $task_id);
					} else {
						bot_sendmessage($chat_id, "Данные указаны некорректно, нет доступа.");
						bot_sendmessage($chat_id, "Сообщите ваш API-ключ для биржи bithumb.pro.");
						save_state($chat_id, "add_task_step3", $task_id);
					}
					break;
				case 'add_task_step5':
					$profit = floatval($message);
					if (empty($profit) OR $profit<=0) {
						bot_sendmessage($chat_id, "Вы ввели некорректное значение. Введите положительное число без посторонних символов.");
					} else {
						$task_id = get_state_data($chat_id, "add_task_step5");
						mysqli_query($linksql, "UPDATE tasks SET need_profit='$profit' WHERE id='$task_id' AND user_id='$chat_id'");
						bot_sendmessage($chat_id, "Укажите максимальную сумму одной сделки (в USDT-эквиваленте). <i>Например: 100</i>");
						save_state($chat_id, "add_task_step6", $task_id);
					}
					
					break;
				case 'add_task_step6':
					$max_trade = floatval($message);
					if (empty($max_trade) OR $max_trade<=0) {
						bot_sendmessage($chat_id, "Вы ввели некорректное значение. Введите положительное число без посторонних символов.");
					} else {
						$task_id = get_state_data($chat_id, "add_task_step6");
						mysqli_query($linksql, "UPDATE tasks SET max_trade='$max_trade' WHERE id='$task_id' AND user_id='$chat_id'");
						bot_sendmessage($chat_id, "👍");
						bot_sendmessage($chat_id, "Торговля запущена. Вы будете получать уведомления об успешных сделках.");
						clear_states($chat_id);
						bot_show_menu($chat_id);
					}
					
					break;
			}
			
	}
}

if ($type=="callback") { // если юзер кликнул по кнопке
	switch($message) {
		case 'add-task':
			bot_add_task($chat_id);
			break;
		case 'strategy-arbitrage':
			$ticker = get_state_data($chat_id, "add_task_step2");
			$pairs = bithumb_get_pairs($ticker);

			if (sizeof($pairs)<2) {
				bot_sendmessage($chat_id, "Нельзя выбрать стратегию Арбитраж для тикера $ticker, так как для торговли доступна только одна пара.");
				clear_states();
				bot_add_task($chat_id);
				exit();
			}

			$n = 0;
			foreach ($pairs AS $pair) {
				if ($n==0) {
					$pairs_text = $pair;
					$pair = str_replace("$ticker-", "", $pair);
					$pair = str_replace("-$ticker", "", $pair);
					$pairs_dop_text = $pair;
				} else {
					$pairs_text .= ", $pair";
					$pair = str_replace("$ticker-", "", $pair);
					$pair = str_replace("-$ticker", "", $pair);
					$pairs_dop_text .= " и $pair";
				}
				$n++;
			}

			$min_trade = bithumb_get_min_trade($ticker);

			mysqli_query($linksql, "INSERT INTO tasks SET user_id='$chat_id', type='arbitrage', data='$ticker', min_trade='$min_trade'");
			$task_id = mysqli_insert_id($linksql);
			save_state($chat_id, "add_task_step3", $task_id);
			bot_sendmessage($chat_id, "Торговля будет осуществляться в парах $pairs_text, вам необходимо иметь на балансе $pairs_dop_text для работы.");

			$res = mysqli_query($linksql, "SELECT DISTINCT(api_key) FROM tasks WHERE user_id='$chat_id' AND api_key!='' AND secret!='' ORDER BY id DESC LIMIT 5");
			$tasks = mysqli_num_rows($res);

			if ($tasks>0) {
				$n = 0;
				$keyboard = '{"inline_keyboard":[';
				while ($task = mysqli_fetch_array($res)) {
					$n++;
					$keyboard .= '[{"text":"'.$task["api_key"].'","callback_data":"api-key-'.$task["api_key"].'"}]';
					if ($n!=mysqli_num_rows($res)) {
						$keyboard .= ",";
					}
				}
				$keyboard .= ']}';
				bot_sendmessage($chat_id, "<strong>Сообщите ваш API-ключ</strong> для биржи bithumb.pro или выберите существующий из списка.", $keyboard);
			} else {
				bot_sendmessage($chat_id, "<strong>Сообщите ваш API-ключ</strong> для биржи bithumb.pro");
			}			
			break;
		default: // если callback не распознана

			if (substr($message, 0, 9)=="get-task-") { 
				$task_id = substr($message, 9);

				$res = mysqli_query($linksql, "SELECT * FROM tasks WHERE id='$task_id' AND user_id='$chat_id'");
				$task = mysqli_fetch_assoc($res);

				if ($task["type"]=="arbitrage") {
					$type = "Арбитраж";
				}

				$text = "<strong>Задача:</strong> $type " . $task["data"] . " с профитом " . $task["need_profit"] . "%\nМаксимальная сумма сделки: " . $task["max_trade"] . " USDT";

				$inline_button1 = array("text"=>"Изменить параметры","callback_data"=>'edit-task-'.$task_id);
				$inline_button2 = array("text"=>"Удалить задачу","callback_data"=>'delete-task-'.$task_id);
				$inline_keyboard = [[$inline_button1],[$inline_button2]];
				$keyboard=array("inline_keyboard"=>$inline_keyboard);
				$replyMarkup = json_encode($keyboard); 

				bot_sendmessage($chat_id, $text, $replyMarkup);
			}

			if (substr($message, 0, 10)=="edit-task-") { 
				$task_id = substr($message, 10);
				bot_sendmessage($chat_id, "<strong>Укажите желаемый % профита</strong> <i>(Например: 1.5%)</i>. Бот будет проводить только сделки, удовлетворяющие данному условию. Профит должен учитывать комиссию биржи.");
				save_state($chat_id, "add_task_step5", $task_id);	
			}

			if (substr($message, 0, 12)=="delete-task-") { 
				$task_id = substr($message, 12);
				$inline_button1 = array("text"=>"Да, удалить","callback_data"=>'go-delete-task-'.$task_id);
				$inline_keyboard = [[$inline_button1]];
				$keyboard=array("inline_keyboard"=>$inline_keyboard);
				$replyMarkup = json_encode($keyboard); 

				bot_sendmessage($chat_id, "Точно удалить?", $replyMarkup);
			}

			if (substr($message, 0, 15)=="go-delete-task-") { 
				$task_id = substr($message, 15);

				mysqli_query($linksql, "DELETE FROM tasks WHERE id='$task_id' AND user_id='$chat_id'");

				$inline_button1 = array("text"=>"➕ Создать задачу","callback_data"=>'add-task');
				$inline_keyboard = [[$inline_button1]];
				$keyboard=array("inline_keyboard"=>$inline_keyboard);
				$replyMarkup = json_encode($keyboard); 

				bot_sendmessage($chat_id, "<strong>Задача удалена</strong>. Может хотите создать новую?", $replyMarkup);
			}

			if (substr($message, 0, 8)=="api-key-") { 
				$api_key = substr($message, 8);

				$res = mysqli_query($linksql, "SELECT * FROM tasks WHERE api_key='$api_key' AND user_id='$chat_id' ORDER BY id DESC LIMIT 1");
				$api = mysqli_fetch_assoc($res);

				$new_task_id = get_state_data($chat_id, "add_task_step3");
				mysqli_query($linksql, "UPDATE tasks SET api_key='".$api["api_key"]."', secret='".$api["secret"]."' WHERE id='$new_task_id' AND user_id='$chat_id'");
				bot_sendmessage($chat_id, "<strong>Укажите желаемый % профита</strong> <i>(Например: 1.5%)</i>. Бот будет проводить только сделки, удовлетворяющие данному условию. Профит должен учитывать комиссию биржи.");
				save_state($chat_id, "add_task_step5", $new_task_id);
			}
	}
}


?>