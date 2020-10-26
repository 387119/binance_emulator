--generate group data from historical
select date_trunc('day',time) as grptime,first(price) as first_price,last(price) as last_price,min(price) as min_price,max(price) as max_price into binance.graph from (select * from binance.historicaltrades order by time) as t group by grptime order by grptime;

