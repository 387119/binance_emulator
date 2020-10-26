<?php
$dbconn = pg_connect("host=db port=5432 dbname=cc user=cc password=Ero5Pooz");
$sql="select opentime,open,high,low,close from binance.klines where interval='1d' and symbolid=1 order by opentime;";
$ret=pg_query($sql);
$res=pg_fetch_all($ret);
foreach($res as $r){
 echo $r['opentime'].",".$r['open'].",".$r['high'].",".$r['low'].",".$r['close']."\n";
}

#echo "2019-10-14,114.750000,116.809998,110.680000,115.209999\n";
#echo "2019-10-21,116.199997,119.459999,116.190002,117.410004\n";
#echo "2019-10-28,117.050003,120.980003,113.949997,120.809998\n";
#echo "2019-11-05,120.730003,126.349998,118.400002,122.879997\n";
#echo "2019-11-12,122.839996,127.430000,122.300003,124.220001\n";
#echo "2019-11-19,124.300003,127.739998,122.870003,125.589996\n";
#echo "2019-11-26,126.239998,129.070007,123.599998,123.800003\n";
?>

