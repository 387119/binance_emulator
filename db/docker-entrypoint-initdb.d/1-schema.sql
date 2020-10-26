CREATE SCHEMA binance;

CREATE TABLE binance.klines (
    symbolid integer,
    opentime timestamp without time zone,
    open real,
    high real,
    low real,
    close real,
    volume real,
    closetime timestamp without time zone,
    quotevolume real,
    numtrades integer,
    tbasevol real,
    tquotevol real,
    ignore real,
    "interval" character varying
);

CREATE UNIQUE INDEX binance_klines_symbolid_opentime_closetime ON binance.klines USING btree (symbolid, opentime, closetime);
create unique index binance_klines_interval_opentime on binance.klines (interval,opentime);
create unique index binance_klines_interval_closetime on binance.klines (interval,closetime);
create unique index binance_klines_interval_opentime_closetime on binance.klines (interval,opentime,closetime);
create unique index binance_klines_symbolid_interval_opentime_closetime on binance.klines (interval,symbolid,interval,opentime,closetime);
create unique index binance_klines_symbolid_interval_opentime on binance.klines (interval,symbolid,interval,opentime);
create unique index binance_klines_symbolid_interval_closetime on binance.klines (interval,symbolid,interval,closetime);

CREATE TABLE binance.bot1_orders (
    orderid serial unique NOT NULL,
    deltaid integer,
    status character varying,
    symbol character varying,
    type character varying,
    price real,
    amount real,
    time_open timestamp without time zone,
    time_close timestamp without time zone,
	can_close timestamp without time zone,
	can_cancel timestamp without time zone
);

CREATE TABLE binance.bot1_funds (
    cur character varying unique,
    val real DEFAULT 0 NOT NULL
);

CREATE TABLE binance.bot1_config (
	name varchar unique not null,
	val varchar
);
