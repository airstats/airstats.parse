<?php
date_default_timezone_set("UTC");

function config($key, $default = null)
{
  global $_config;
  if ($_config[$key]) {
    return $_config[$key];
  }
  else {
    return $default;
  }
}

$dsn = "mysql:host=" . config("db_host") . ";dbname=" . config("db_name") . ";charset=utf8";
$opt = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
$flightpdo = new PDO($dsn, config("db_user") , config("db_pass") , $opt);
$flightpdo->exec("SET time_zone='+00:00';");
