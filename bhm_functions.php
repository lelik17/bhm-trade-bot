<?

include "db_connect.php";

$token = ""; // telegram token
$api_url = "https://api.telegram.org/bot";

function api_query($method=null, $params=null) {
    global $token, $api_url;
    
    $query = "$api_url$token/$method";
    
    $ch = curl_init($query);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $params); 
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('content-type: multipart/form-data'));
    $result = curl_exec($ch);
    curl_close($ch);    
    //print_r($result);
    return $result;
}

function bot_sendmessage($chat_id, $message, $markup=null) {
    $response = array(
        'chat_id' => $chat_id,
        'disable_web_page_preview'=>1,
        'parse_mode'=>'HTML',
        'text' => $message,
        'reply_markup' => $markup
    );  
    api_query("sendMessage", $response);
}

function bot_welcome_message($chat_id) {
    bot_sendmessage($chat_id, "Привет, давайте начнём торговать!");
    bot_add_task($chat_id);
}

function bot_add_task($chat_id) {
    bot_sendmessage($chat_id, "Создаём новую задачу для бота.");
    bot_sendmessage($chat_id, "<strong>Укажите криптоактив, которым будем торговать.</strong> Например, BIP");
    clear_states($chat_id);
    save_state($chat_id, "add_task_step1");
}

function bot_show_strategies($chat_id) {
    $inline_button1 = array("text"=>"Арбитраж между парами","callback_data"=>'strategy-arbitrage');
    $inline_keyboard = [[$inline_button1]];
    $keyboard=array("inline_keyboard"=>$inline_keyboard);
    $replyMarkup = json_encode($keyboard); 
    bot_sendmessage($chat_id, "Выберите торговую стратегию:", $replyMarkup);
}

function bot_show_menu($chat_id, $text=null) {
    
    $button1 = array('text' => '➕ Новая задача');
    $button2 = array('text' => '📋 Мои задачи');
    $button3 = array('text' => '🆘 Помощь');

    $keyboard = array('keyboard' => array(array($button1, $button2), array($button3)),'one_time_keyboard' => true, 'resize_keyboard' => true);
    $replyMarkup = json_encode($keyboard); 

    if (empty($text)) {
        $text = "Выберите дальнейшие действия в меню:";
    }

    bot_sendmessage($chat_id, $text, $replyMarkup);
    
}

function save_state($chat_id, $state, $data=null) {
    global $linksql;
    mysqli_query($linksql, "INSERT INTO states SET state='$state', chat_id='$chat_id', date=NOW(), data='$data'");
    $id = mysqli_insert_id($linksql);
    return $id;
}

function clear_states($chat_id) {
    global $linksql;
    mysqli_query($linksql, "DELETE FROM states WHERE chat_id='$chat_id'");
}

function get_state($chat_id) {
    global $linksql;
    $res = mysqli_query($linksql, "SELECT * FROM states WHERE chat_id='$chat_id' ORDER BY id DESC");
    if (mysqli_num_rows($res)>0) {
        $data = mysqli_fetch_assoc($res);
        return $data["state"];
    } else {
        return "start";
    }
}

function get_state_data($chat_id, $state) {
    global $linksql;
    $res = mysqli_query($linksql, "SELECT * FROM states WHERE chat_id='$chat_id' AND state='$state' ORDER BY id DESC");
    if (mysqli_num_rows($res)>0) {
        $data = mysqli_fetch_assoc($res);
        return $data["data"];
    } 
}

function bithumb_get_min_trade($ticker) {

    $resp = file_get_contents("https://global-openapi.bithumb.pro/openapi/v1/spot/config");
    if($resp) {
        $resp = json_decode($resp);
        foreach ($resp->data->coinConfig AS $coin) {
            if ($coin->name==$ticker) {
                return $coin->minTxAmt;
                exit();
            }
        }
        return false;
        exit();
    }

}

function bithumb_check_coin($ticker) {

    $resp = file_get_contents("https://global-openapi.bithumb.pro/openapi/v1/spot/config");
    if($resp) {
        $resp = json_decode($resp);
        foreach ($resp->data->spotConfig AS $pair) {
            if (stripos($pair->symbol, "$ticker-") !== false) {
                return true;
                exit();
            } 
        }
        return false;
        exit();
    }

}

function bithumb_get_pairs($ticker) {

    $pairs = array();

    $resp = file_get_contents("https://global-openapi.bithumb.pro/openapi/v1/spot/config");
    if($resp) {
        $resp = json_decode($resp);
        foreach ($resp->data->spotConfig AS $pair) {
            if (stripos($pair->symbol, "$ticker-") !== false) {
                $pairs[] = $pair->symbol;
            } 
        }
        return $pairs;
    }

}

function bithumb_check_login($api_key, $secret) {

    $params["assetType"] = "spot";
    $params["coinType"] = "";

    $result = bithumb_query("spot/assetList", $params, $api_key, $secret);

    if ($result->code==0) {
        return true;
    } else {
        return false;
    }   
}

function bithumb_create_order($symbol, $side, $price, $quantity, $api_key, $secret) {
    $params["symbol"] = $symbol;
    $params["side"] = $side;
    $params["price"] = $price;
    $params["quantity"] = $quantity;
    $params["type"] = "limit";
    $result = bithumb_query("spot/placeOrder", $params, $api_key, $secret);

    return $result;
}

function bithumb_query($method, $params, $api_key, $secret) {

    $result = file_get_contents("https://global-openapi.bithumb.pro/openapi/v1/serverTime");
    $result = json_decode($result);
    $server_time = $result->timestamp;

    $params["apiKey"] = $api_key;
    $params["msgNo"] = "$server_time";
    $params["timestamp"] = "$server_time";
    $params["signature"] = genSignature($params, $secret);

    $params = json_encode($params);
    $ch = curl_init();

    $headers = array("Content-Type: application/json");
    curl_setopt($ch, CURLOPT_URL, "https://global-openapi.bithumb.pro/openapi/v1/$method");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
     curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $result = curl_exec($ch);
    curl_close($ch);

    $result = json_decode($result);
    return $result;
}


function genSignature($params, $secret_key) {
    ksort($params);
    $str = http_build_query($params);
    $sign = hash_hmac("sha256", $str, $secret_key, false);
    return $sign;
}

?>