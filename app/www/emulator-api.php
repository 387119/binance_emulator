<?php
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

function db_clean(){
	$sql="update binance.bot1_funds set val=1 where cur='LTC';";
	$sql.="update binance.bot1_funds set val=0 where cur='USDT';";
	$sql.="truncate binance.bot1_orders;";
	$sql.="delete from binance.bot1_config where name='now';";
	db($sql);
}

if (!isset($_GET["hash"]))
	die("hash is not defined");
if (!isset($_GET["req"]))
	die("api request is not exists");
switch ($_GET["req"]){
	case "clean":
		db_clean();
		break;
	default:
		die("api request is not defined or wrong");
}
echo "$response";
?>

