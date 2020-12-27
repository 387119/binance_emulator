<?php
/*
 * принцип работы апи
 * - --проверяем открытые ордера в которых не стоит дата когда он может быть отменён (цена убежала больше чем на дельту)
 * - --получаем для этих ордеров дату когда он может быть отменён
 * - --получаем таже для этих ордеров даты когда он может быть выполнен и когда он может быть отменён
 * - --устанавливаем значения в базе
 * - при выставлении нового ордера мы сразу высчитываем время когда этот ордер может быть выполнен или отменён (дельта)
 * - прописываем эти даты в базу
 * - а базе прописываем текущую метку времени которая является минимальным значением от "времени когда отменён" и "когда выполнен"
 * - если время когда он может быть выполнен меньшее то помечаем ордер как выполнен и меняем состояния счетов
 * - после того как ордер выполнен снимаем fee
 * - текущим значением цены считаем среднее значение за минуту
 * - текущим значением времени считаем время открытия "минутки"
 *
 */
$dbconn = pg_connect("host=db port=5432 dbname=cc user=cc password=Ero5Pooz");
$response="";

function db($sql){
	global $dbconn;
	$req=pg_query($sql);
	if (!$req){
		die("sql command return error: $sql");
	}
	$res=pg_fetch_all($req);
	return $res;
}

function getExchangeInfo(){
	$response=file_get_contents("exchangeinfo.txt");
	return $response;
}

function setInitTime(){//устанавливаем новое время если оно не установленно
	$sql="insert into binance.bot1_config (name,val) values ('now',(select opentime from binance.klines where interval='1m' order by opentime limit 1)) on conflict (name) do nothing;";
	db($sql);
}

function setNewCurrentTime($str){//установить явное значение новой текущей даты
	$sql="update binance.bot1_config set val='$str' where name='now';";
	db($sql);
}

function getCurrentTime(){//возвращает значение времени на котором сейчас остановились
	$sql="select val from binance.bot1_config where name='now';";
	$res=db($sql);
	$return=$res[0]['val'];
	return $return;
}

function placeOrder($in){
	$ctime=getCurrentTime();
	$sql="insert into binance.bot1_orders (status,symbol,type,price,amount,time_open,real_tstamp) values ('open','".$in['symbol']."','".$in['side']."',".$in['price'].",".$in['quantity'].",'".$ctime."',now()) returning *;";
	$res=db($sql);
	$orderid=$res[0]['orderid'];
	if ($in['type']=='BUY'){
		$sql="
			update binance.bot1_orders as bo1 
			set can_close=(
				select opentime
				from binance.klines as bn 
				where 1=1
					and bn.interval='1m' 
					and bn.opentime > bo1.time_open 
					and bo1.price < (bn.low+bn.high)/2 
				order by opentime 
				limit 1
			) 
			where orderid=$orderid;
			update binance.bot1_orders as bo1 
			set can_cancel=(
				select opentime 
				from binance.klines as bn 
				where 1=1
					and bn.interval='1m' 
					and bn.opentime > bo1.time_open 
					and ((bn.low+bn.high)/2) > bo1.price+(bo1.price*0.1) 
				order by opentime 
				limit 1
			) 
			where orderid=$orderid;
		";
	}else{
		$sql="
			update binance.bot1_orders as bo1 
			set can_close=(
				select opentime 
				from binance.klines as bn 
				where 1=1
					and bn.interval='1m' 
					and bn.opentime > bo1.time_open 
					and bo1.price >= (bn.low+bn.high)/2 
					order by opentime 
					limit 1
			) where orderid=$orderid;
			update binance.bot1_orders as bo1 
			set can_cancel=(
				select opentime 
				from binance.klines as bn 
				where 1=1
				and bn.interval='1m' 
				and bn.opentime > bo1.time_open 
				and ((bn.low+bn.high)/2) < bo1.price-(bo1.price*0.1) 
				order by opentime 
				limit 1
			) where orderid=$orderid;
		";
	}
	db($sql);
	return $orderid;
}

function cancelOrder($orderid){
	//отмена текущего ордера
	$sql="update binance.bot1_orders set status='cancel',time_close=(select val::timestamp without time zone from binance.bot1_config where name='now') where orderid=$orderid;";
	db($sql);
	return $orderid;
}

function closeOrder($orderid){
	//выполнение ордера, перемещение средств между счетами, снятие налога
	$exchangeInfo=getExchangeInfo();
	$baseAsset="";
	$quoteAsset="";
	$sql="select * from binance.bot1_orders where orderid=$orderid;";
	$order=db($sql);
	$x=json_decode($exchangeInfo,true);
	foreach($x["symbols"] as $v){
		if ($v['symbol']==$order[0]['symbol']){
			$baseAsset=$v['baseAsset'];
			$quoteAsset=$v['quoteAsset'];
			break;
		}
	}
	$sql="update binance.bot1_orders set status='close',time_close=(select val::timestamp without time zone from binance.bot1_config where name='now') where orderid=$orderid;";
	$quoteCount=$order[0]['price']*$order[0]['amount'];
	$baseCount=$order[0]['amount'];
	if ($order[0]['type']=='BUY'){
		//покупка, снимаем сумму с quoteAsset, докидываем в baseAsset
		$sql.="update binance.bot1_funds set val=val-$quoteCount where cur='$quoteAsset';";
		$sql.="update binance.bot1_funds set val=val+$baseCount where cur='$baseAsset';";
		$fee=$baseCount*0.01;
		$sql.="update binance.bot1_funds set val=val-$fee where cur='$baseAsset';";
	}else{
		//продажа, снимаем сумму с baseAsset, докидываем в quoteAsset
		$sql.="update binance.bot1_funds set val=val+$quoteCount where cur='$quoteAsset';";
		$sql.="update binance.bot1_funds set val=val-$baseCount where cur='$baseAsset';";
		$fee=$quoteCount*0.01;
		$sql.="update binance.bot1_funds set val=val-$fee where cur='$quoteAsset';";
	}
	file_put_contents ("/tmp/debug.txt","$sql");
	db($sql);
}

function livetime(){
	$sql="
		select orderid,tstamp,type 
		from (
			select * 
			from (
				select orderid,can_cancel as tstamp,'cancel' as type 
				from binance.bot1_orders 
				where 1=1
					and can_cancel>=(select val::timestamp without time zone from binance.bot1_config where name='now')
					and status='open' 
					and time_close is null 
				order by can_cancel 
				limit 1
			) as t1 
			union 
			select * 
			from (
				select orderid,can_close as tstamp,'close' as type 
				from binance.bot1_orders 
				where 1=1
					and can_close>=(select val::timestamp without time zone from binance.bot1_config where name='now')
					and status='open'
					and time_close is null 
				order by can_close 
				limit 1
			) as t2 
			order by tstamp 
			limit 1
		) as t1t2;
	";
	$res=db($sql);
	setNewCurrentTime($res[0]['tstamp']);
	if ($res[0]['type']=='close'){
		closeOrder($res[0]['orderid']);
	}
}

function getAvgPrice(){
	$sql="select trunc(((high+low)/2)::numeric,2) as avgprice from binance.klines where opentime=(select val::timestamp without time zone from binance.bot1_config where name='now');";
	$res=db($sql);
	return $res[0]['avgprice'];
}

setInitTime();
if (!isset($_GET["api_request"]))
	die("api request is not exists");
switch ($_GET["api_request"]){
	case "account"://нужен для bot1 +
		$retdata=array();
		$sql="select * from binance.bot1_funds;";
		$res=db($sql);
		foreach ($res as $v){
			$retdata['balances'][]=array(
				"asset"=>$v['cur'],
				"free"=>$v['val'],
				"locked"=>0
			);
		}
		$response=json_encode($retdata);
		break;
	case "order"://нужен для bot1 - формализовать вывод в правильной форме
		$_REQUEST=array_merge($_GET,$_POST);
		switch ($_SERVER['REQUEST_METHOD']){
			case "POST"://создание нового ордера
				$param=array(
					"symbol"=>$_REQUEST['symbol'],
					"side"=>$_REQUEST['side'],
					"type"=>$_REQUEST['type'],
					"quantity"=>$_REQUEST['quantity'],
					"price"=>$_REQUEST['price']
				);
				$orderid=placeOrder($param);
				//********** надо заполнить ордер данными по времени закрытия и открытия
				livetime();
				$res=array(
					"symbol" => $_REQUEST['symbol'],
					"orderId" => $orderid,
					"price" => $_REQUEST['price'],
					"origQty" => $_REQUEST['quantity'],
					"status" => "NEW",
					"side" => $_REQUEST['side']
				);
				break;
			case "GET"://запрос данных ордера
				break;
			case "DELETE"://отмана ордера
				cancelOrder($_REQUEST['orderId']);
				$res=array(
					"orderId" => $orderid,
					"status" => "CANCELED"
				);
				break;
		}
		$response=json_encode($res);
		break;
	case "exchangeInfo": // +
		$response=getExchangeInfo();
		break;
	case "openOrders"://нужен для bot1 +
		if (isset($_GET['limit']))
			$limit=$_GET['limit'];
		else
			$limit=500;
		if (isset($_GET['symbol']))
			$sym=" and symbol='".$_GET['symbol']."' ";
		else
			$sym="";
		$sql="select * from binance.bot1_orders where 1=1 and status='open' $sym order by time_open desc limit $limit;";
		$res=db($sql);
		$retdata=array();
		$i=0;
		foreach ($res as $v){
			$retdata[$i]['symbol']=$v['symbol'];
			$retdata[$i]['orderId']=$v['orderid'];
			$retdata[$i]['price']=$v['price'];
			$retdata[$i]['origQty']=$v['amount'];
			$retdata[$i]['executedQty']=0;
			if ($v['status']=='open')
				$retdata[$i]['status']='NEW';
			if ($v['type']=='BUY')
				$retdata[$i]['side']='BUY';
			else
				$retdata[$i]['side']='SELL';
			$i++;
		}
		$response=json_encode($retdata);
		break;
	case "allOrders"://нужен для bot1 +
		if (isset($_GET['limit']))
			$limit=$_GET['limit'];
		else
			$limit=500;
		if (isset($_GET['symbol']))
			$sym=" and symbol='".$_GET['symbol']."' ";
		else
			$sym="";
		$sql="select * from binance.bot1_orders where 1=1 $sym order by time_open desc limit $limit;";
		$res=db($sql);
		$retdata=array();
		$i=0;
		foreach ($res as $v){
			$retdata[$i]['symbol']=$v['symbol'];
			$retdata[$i]['orderId']=$v['orderid'];
			$retdata[$i]['price']=$v['price'];
			$retdata[$i]['origQty']=$v['amount'];
			$retdata[$i]['executedQty']=$v['amount'];
			$status="UNDEFINED";
			if ($v['status']=='open')
				$retdata[$i]['status']='NEW';
			if ($v['status']=='close')
				$retdata[$i]['status']='FILLED';
			if ($v['status']=='cancel')
				$retdata[$i]['status']='CANCELED';
			if ($v['type']=='BUY')
				$retdata[$i]['side']='BUY';
			else
				$retdata[$i]['side']='SELL';
			$i++;
		}
		$response=json_encode($retdata);
		break;
	case "avgPrice"://нужен для bot1 +
		$avgPrice=getAvgPrice();
		$res=array("price"=>$avgPrice);
		$response=json_encode($res);
		break;
	case "depth":
		break;
	case "trades":
		break;
	case "historicalTrades":
		break;
	case "klines":
		break;
	default:
		die("api request is not defined or wrong");
}
echo "$response";
?>

