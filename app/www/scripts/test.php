<?php
//пока по простому, 
// * первый ордер на продажу выставляется по текущей цене дабы он выполнился сразу
//
// 1 - выставляем ордер на продажу
// 2 - запускаем алгоритм определения когда указанный ордер будет закрыт
// 2.1 - если ордер закрыт то
//     - закрываем ордер, меняем состояние счета
//     - выставляем опозитный ордер
// если ордер не закрыт а цена ушла на дельту в другую сторону то
//     - отменяем текущий ордер
//     - выставляем ордер по скорректированной цене (текущей)
// 5 - переходим к п.2

$dbconn = pg_connect("host=db port=5432 dbname=cc user=cc password=Ero5Pooz");

//amount всегда s1
// если buy то покупаем amount s1 по price
// если sell то продаём amount s1 по price

$ctime="";//начальное значение времени
$cprice="";//текущая цена на ту метку времени (и соответсвенно цена закрытого ордера)
$cend="false";

$symbol="BTCUSDT";
$s1="BTC";
$s1_start_amount=1;
$s1_amount=0;
$s1_min=0;
$s1_max=1;

$s2="USDT";
$s2_amount=4000;

$fee="0.001";

//коефициенты изменения для каждой линии
// пока используем только 0 коэфициент, в процесе проверики посмотрим стоит ли вводить дополнительные
$delta=array(
	0=>0.1
);

function db($sql){
	global $dbconn;
	$req=pg_query($sql);
	if (!$req){
		die("sql command return error: $sql");
	}
	$res=pg_fetch_all($req);
	return $res;
}

function status_order($id){
	$sql="select * from binance.bot1_orders where orderid=$id;";
	$data=db($sql);
	$res=$data[0];
	return $res;
}

function check_available_balance($amount,$price,$op){
	global $s1,$s2;
	if ($type=='buy'){
		$sql="select val-($amount*$price) as new_val from binance.bot1_funds where cur='$s2';";
	}else{
		$sql="select val-$amount as new_val from binance.bot1_funds where cur='$s1';";
	}
	$data=db($sql);
	if ($data[0]['new_val']<0)
		return false;
	return true;
}

function place_order($amount,$type,$price,$deltaid){
	global $ctime,$symbol,$s1,$s2;
	//check balance before place order
//	if (!check_available_balance($amount,$price,$type)){
//		die("cannot place order: not enough funds");
//	}
	//if balance after place order will be minus then stop with error
	$sql="insert into binance.bot1_orders (deltaid,status,symbol,type,price,amount,time_open) values ($deltaid,'open','$symbol','$type',$price,$amount,'$ctime');";
	if ($type=='buy'){
		$sql.="update binance.bot1_funds set val=val-($amount*$price) where cur='$s2';";
	}else{
		$sql.="update binance.bot1_funds set val=val-$amount where cur='$s1';";
	}
	db($sql);
}

function close_order($orderid){
	global $s1,$s2,$fee,$ctime;
	$sql="update binance.bot1_orders set status='close',time_close='$ctime' where orderid=$orderid returning *;";
	$data=db($sql);
	$amount=$data[0]['amount'];
	$price=$data[0]['price'];
	$type=$data[0]['type'];
	// изменение состояние счёта
	if ($type=='buy'){
		$sql.="update binance.bot1_funds set val=val+$amount-($amount*$fee) where cur='$s1';";
	}else{
		$sql.="update binance.bot1_funds set val=val+($amount*$price)-($amount*$price*$fee) where cur='$s2';";
	}
	db($sql);
}

function cancel_order ($orderid){
	global $s1,$s2,$fee,$ctime;
	echo "cancel orderid: $orderid\n";
	$sql="update binance.bot1_orders set status='cancel',time_close='$ctime' where orderid=$orderid returning *;";
	$data=db($sql);
	$amount=$data[0]['amount'];
	$price=$data[0]['price'];
	$type=$data[0]['type'];
	// изменение состояние счёта (вернуть бабки на счёт)
	if ($type=='buy'){
		$sql.="update binance.bot1_funds set val=val+($amount*$price) where cur='$s2';";
	}else{
		$sql.="update binance.bot1_funds set val=val+$amount where cur='$s1';";
	}
	db($sql);
}

function cancel_all_orders (){
	echo "canceling all orders\n";
	$sql="select orderid from binance.bot1_orders where status='open';";
	$data=db($sql);
	print_r($data);
	foreach ($data as $order){
		cancel_order($order['orderid']);
	}
}

function get_opened_orders(){
	global $ctime,$cprice;
	$sql="select count(*) from binance.bot1_orders where status = 'open';";
	$data=db($sql);
	if ($data[0]['count']>0){
		$sql="select time_open,price from binance.bot1_orders where status='open' order by time_open desc limit 1;";
		$nd=db($sql);
		$ctime=$nd[0]['time_open'];
		$cprice=$nd[0]['price'];
		return true;
	}else
		return false;
}

function set_start_time(){
	//установка начальных значений времени и цены
	global $ctime,$cprice;
	$sql="select opentime,low from binance.klines order by opentime limit 1;";
	$data=db($sql);
	$ctime=$data[0]['opentime'];
	$cprice=$data[0]['low'];
	return true;
}

function get_oposite($orderid){
	global $delta,$fee;
	$sql="select deltaid,type,price from binance.bot1_orders where orderid=$orderid;";
	$data=db($sql);
	$newprice=0;
	$newtype='';
	if ($data[0]['type']=='buy'){
		//была операция покупки, значит цена должна быть увеличена
		$newtype='sell';
		$newprice=$data[0]['price']+($data[0]['price']*($delta[$data[0]['deltaid']]+$fee));
	}else{
		//была операция продажи, значит цена должна быть уменьшена
		$newtype='buy';
		$newprice=$data[0]['price']-($data[0]['price']*($delta[$data[0]['deltaid']]+$fee));
	}
	$aret=array($newtype,$newprice,$data[0]['deltaid']);
	return $aret;
}

function livetime(){
	// *** !!! надо изменить функцию так чтоб она не обновляла базу ордеров автоматически а только возвращала статус, по какому ордеру какая операция в данный момент времени возможна, а уже потом другие функции будут выполнять процедуры закрытия или отмены ордера
	global $ctime,$cprice,$cend;
	$ctime_prev=$ctime;
	$res=array();
	//движение по времени
	//цель, от текущей позиции времени в памяти ищем первое совпадение когда первый из открытых ордеров может быть закрыт
	//возвращаем ордер который может быть выполен
	//меняем текущую метку времени и ткущую цену

	//задача найти первый ордер который мы можем обработать и что конкредно мы можем с ним сделать
	//например но может быть выполнен по причине достижения цены или может быть отменён по причине убегания цены далеко
	
	#	$sql="update binance.bot1_orders as to1 set time_close=(select opentime from binance.klines as bn where bn.interval='1m' and bn.opentime > to1.time_open and to1.price>bn.low and to1.price<bn.high order by opentime limit 1) where status='open' and time_close is null;";
	$sql="select *,case when can_cancel<can_close then 'to_cancel' when can_close is null then 'to_cancel' else 'to_close' end as todo from (select orderid, deltaid, type, price, amount, time_open, time_close, (select opentime from binance.klines as bn where bn.interval='1m' and bn.opentime > to1.time_open and to1.price>bn.low and to1.price<bn.high order by opentime limit 1) as can_close,(select opentime from binance.klines as bn1 where bn1.interval='1d' and bn1.opentime > to1.time_open and (to1.price>bn1.high or to1.price<bn1.low) order by opentime limit 1) as can_cancel from binance.bot1_orders as to1 where status='open' and time_close is null) as res;";
#	$sql.="select orderid,time_close,price from binance.bot1_orders where status='open' and time_close is not null;";
	$data=db($sql);
	if (!is_numeric($data[0]['orderid']))
		return false;
	$res['id']=$data[0]['orderid'];
	if ($data[0]['todo']=='to_close'){
		$res['status']='success';
		$ctime=$data[0]['can_close'];
	}else{
		$res['status']='cancel';
		$ctime=$data[0]['can_cancel'];
	}
	if ($ctime!=""){
		$sql="select ((high+low)/2)::decimal(16,2) as price from binance.klines where opentime='$ctime' order by interval desc;";
		$data=db($sql);
		$cprice=$data[0]['price'];
	}else{
		echo "end, finishing\n";
		$ctime=$ctime_prev;
		$cend="true";
	}
	return $res;
}

function clear_data(){
	global $s1,$s2,$s1_amount,$s2_amount;
	$sql="update binance.bot1_funds set val=$s2_amount where cur='$s2';update binance.bot1_funds set val=$s1_amount where cur='$s1';delete from binance.bot1_orders;";
	db($sql);
}

function get_work_amount($price,$op){
	global $s1,$s2,$s1_min,$s1_max;
	//функция возвращает количество s1 с которым можно работать
	//оно не должно превышать s1_max и не быть менее чем s1_min, 
	//но при этом его должно быть в наличии в базе в той валюте согластно операции которую мы хотим выполнить
	if ($op=='buy'){
		//операция покупки, у нас должно быть достаточно s2
		$sql="select trunc((val/$price)::numeric,2) as val from binance.bot1_funds where cur='$s2';";
	}else{
		//операция продажи, у нас должно быть достаточно s1
		$sql="select val from binance.bot1_funds where cur='$s1';";
	}
	$data=db($sql);
	$cur_amount=$data[0]['val'];
	$res_amount=$cur_amount;
	if ($cur_amount>$s1_max)
		$res_amount=$s1_max;
	if ($cur_amount<=$s1_min)
		die("not enough amount in db: $cur_amount");
	return $res_amount;
}

clear_data();//отчистка данных для того чтоб начинать пробег с самого начала

if (!get_opened_orders()){
	//открытых ордеров ненайдено, значит начинаем сначала
	set_start_time();
	$work_s1_amount=get_work_amount($s1_start_amount,'buy');
	//place_order ($s1_start_amount,'buy',$cprice+($cprice*$delta[0]),0);
	place_order ($s1_start_amount,'buy',$cprice,0);
}
while (true){
	echo "was date: $ctime\n";
	$order=livetime();//движение по времени от текущей позиции, возвращает ордер который может быть выполнен, и меняет переменные $ctime,$cprice на "новые текущие"
	// движение по времени должно вернуть ордер и что с ним сделать в этот момент времени, (отменить или закрыть)
	//print "current" date
	echo "jump to date: $ctime (end: $cend)\n";
	if ($cend=="true"){// по времени побежать не получилось, значит мы в конце времени, отменяем ордер и выходим
		echo ("livetime order is null\n");
		cancel_all_orders();
		die ("exiting.\n");
	}
	echo "	orderid: ".$order['id']."\n";
	if ($order['status']=='success'){
		close_order($order['id']);
		/// высчитать опозитные прибыльную цену относительно ордера
		list($newop,$newprice,$newdeltaid)=get_oposite($order['id']);
		$work_s1_amount=get_work_amount($newprice,$newop);
		// выставить ордер
		place_order ($work_s1_amount,$newop,$newprice,$newdeltaid);
	}
	if ($order['status']=='cancel'){
		//ордер не успел выполнится так как цена ушла в оппозитную сторону на дельту
		echo "cancel\n";
		cancel_order($order['id']);
		$order_details=status_order($order['id']);
		$work_s1_amount=get_work_amount($cprice,$order_details['type']);
		place_order ($work_s1_amount,$order_details['type'],$cprice,$order_details['deltaid']);
	}
}
?>

