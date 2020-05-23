<?

include $_SERVER["DOCUMENT_ROOT"] . "/bot/bhm/bhm_functions.php";
 
function check_for_arbitrage($ticker, $need_profit, $task_id, $min_trade, $max_trade) {
	global $btc_price, $pairs;

	print "<br><br><strong>Начинаем проверку по задаче $task_id - $ticker</strong><br><br>BTC/USDT: $btc_price<br><br>";

	$buy_orders = array();
	$sell_orders = array();

	foreach($pairs AS $pair) {
		$resp = file_get_contents("https://global-openapi.bithumb.pro/openapi/v1/spot/orderBook?symbol=$ticker-$pair");
		if($resp) {
			$resp = json_decode($resp);
			$sell_book = $resp->data->s[0];
			$buy_book = $resp->data->b[0];

			$sell_pair_price = $sell_book[0];
			$buy_pair_price = $buy_book[0];

			if ($pair=="BTC") {
				$sell_book[0] = $sell_book[0]*$btc_price;
				$buy_book[0] = $buy_book[0]*$btc_price;
			}

			$sell_orders[] = array("price"=>$sell_book[0], "volume"=>$sell_book[1], "pair"=>$pair, "pair_price"=>$sell_pair_price);
			$buy_orders[] = array("price"=>$buy_book[0], "volume"=>$buy_book[1], "pair"=>$pair, "pair_price"=>$buy_pair_price);
		}
	}


	// сортировки
	foreach ($sell_orders as $key => $row) {
	    $price_arr[$key]  = $row['price'];
	}
	array_multisort($price_arr, SORT_ASC, $sell_orders);

	foreach ($buy_orders as $key => $row) {
	    $price_arr[$key]  = $row['price'];
	}
	array_multisort($price_arr, SORT_DESC, $buy_orders);

	print_r($sell_orders);
	print "<br><br>";
	print_r($buy_orders);


	if ($sell_orders[0]["price"]<$buy_orders[0]["price"]) {
		if ($buy_orders[0]["volume"]<=$sell_orders[0]["volume"]) {
			$volume = $buy_orders[0]["volume"];
		} else {
			$volume = $sell_orders[0]["volume"];
		}

		$profit = round($buy_orders[0]["price"]/($sell_orders[0]["price"]/100)-100, 2);

		print "<br><br>Потенциальный профит $profit%<br><br>";

		if ($profit>=$need_profit) {

			$buy_pair = "$ticker-" . $sell_orders[0]["pair"];
			$sell_pair = "$ticker-" . $buy_orders[0]["pair"];

			$message = "Можно купить $volume $ticker в паре $buy_pair по " . $sell_orders[0]["price"] . " и продать по " . $buy_orders[0]["price"] . " в паре $sell_pair, профит $profit%";

			$volume_in_usdt = $volume * $sell_orders[0]["price"];
			if ($volume>=$min_trade AND $volume_in_usdt<=$volume_in_usdt) {
				// можно проводить сделку

				$res = mysqli_query($linksql, "SELECT * FROM tasks WHERE id='$task_id'");
				$task = mysqli_fetch_assoc($res);

				$symbol = $buy_pair;
				$side = "buy";
				$price = $sell_orders[0]["price_pair"];
				$quantity = $volume;
				$result = bithumb_create_order($symbol, $side, $price, $quantity, $task["api_key"], $task["secret"]);

				if ($result->code==0) {
					// создали ордер
					$order_id = $result->data->orderId;
					mysqli_query($linksql, "INSERT INTO orders SET code='".$result->code."', bhid='$order_id', symbol='$symbol', side='$side', price='$price', quantity='$quantity', date=NOW(), task_id='$task_id'");

					// проверяем выполнился ли ордер
					$params = array();
					$params["symbol"] = $symbol;
					$result = bithumb_query("spot/openOrders", $params, $task["api_key"], $task["secret"]);

					if ($result->code==0) {
						foreach($result->data->list AS $order) {
							if ($order->orderId==$order_id) {
								if ($order->status=="success") {
									// ордер сработал, теперь продаём купленное 

									$symbol = $sell_pair;
									$side = "sell";
									$price = $buy_orders[0]["price_pair"];
									$quantity = $volume;
									$result = bithumb_create_order($symbol, $side, $price, $quantity, $task["api_key"], $task["secret"]);

									if ($result->code==0) {
										// создали ордер продажу, проверяем выполнение
										$order_id = $result->data->orderId;
										mysqli_query($linksql, "INSERT INTO orders SET code='".$result->code."', bhid='$order_id', symbol='$symbol', side='$side', price='$price', quantity='$quantity', date=NOW(), task_id='$task_id'");

										sleep(1);

										$params = array();
										$params["symbol"] = $symbol;
										$result = bithumb_query("spot/openOrders", $params, $task["api_key"], $task["secret"]);
										if ($result->code==0) {
											foreach($result->data->list AS $order) {
												if ($order->orderId==$order_id) {
													if ($order->status=="success") {
														// сделка завершена, шлём уведомление. 
														bot_sendmessage($task["user_id"], "Закрыли сделку в паре $symbol. Профит составил $profit%");
													}
												}
											}
										}
									} else {
										mysqli_query($linksql, "INSERT INTO orders SET code='".$result->code."', symbol='$symbol', side='$side', price='$price', quantity='$quantity', date=NOW(), task_id='$task_id'");
									}

								} else {
									// удаляем ордер, если по каким-то причинам сделка не прошла (например, не успели)
									$params["orderId"] = $order_id;
									$result = bithumb_query("spot/cancelOrder", $params, $task["api_key"], $task["secret"]);
									mysqli_query($linksql, "INSERT INTO orders SET code='".$result->code."', bhid='$order_id', symbol='$symbol', side='cancel', price='$price', quantity='$quantity', date=NOW(), task_id='$task_id'");
								}
							}
						}
					}

				} else {
					mysqli_query($linksql, "INSERT INTO orders SET code='".$result->code."', symbol='$symbol', side='$side', price='$price', quantity='$quantity', date=NOW(), task_id='$task_id'");
				}

				exit();

			} else {
				$message .= ", но сделка не подходит по объёмам";
			}

			print $message;
		}
		
	}

}

function get_btc_price() {
	$resp = file_get_contents("https://global-openapi.bithumb.pro/openapi/v1/spot/ticker?symbol=BTC-USDT");
	if($resp) {
		$resp = json_decode($resp);
		$btc_price = $resp->data[0]->c;
		return $btc_price;
	}
}

$pairs = array("USDT", "BTC");

$z = 0;
while ($z<40) {
	$btc_price = get_btc_price();
	$res = mysqli_query($linksql, "SELECT * FROM tasks WHERE max_trade!='0' AND need_profit!='0' ORDER BY id DESC");

	while ($task = mysqli_fetch_array($res)) {
		check_for_arbitrage($task["data"], $task["need_profit"], $task["id"], $task["min_trade"], $task["max_trade"]);
	}

	sleep(1);
	$z++;
}

?>