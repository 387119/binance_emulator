<?php
$dbconn = pg_connect("host=db port=5432 dbname=cc user=cc password=Ero5Pooz");
$type=$_GET['type'];
$sql="select date_trunc('day',time_close) as time_close,price from binance.bot1_orders where type='".strtoupper($type)."' and status='close' order by time_close;";
$ret=pg_query($sql);
$res=pg_fetch_all($ret);
foreach($res as $r){
	echo $r['time_close'].",".$r['price']."\n";
}
?>
