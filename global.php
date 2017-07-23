<?php

date_default_timezone_set("UTC");

define('callsign', 0);
define('cid', 1);
define('realname', 2);
define('clienttype', 3);
define('frequency', 4);
define('latitude', 5);
define('longitude', 6);
define('altitude', 7);
define('groundspeed', 8);
define('planned_aircraft', 9);
define('planned_tascruise', 10);
define('planned_depairport', 11);
define('planned_altitude', 12);
define('planned_destairport', 13);
define('server', 14);
define('protrevision', 15);
define('rating', 16);
define('transponder', 17);
define('facilitytype', 18);
define('visualrange', 19);
define('planned_revision', 20);
define('planned_flighttype', 21);
define('planned_deptime', 22);
define('planned_actdeptime', 23);
define('planned_hrsenroute', 24);
define('planned_minenroute', 25);
define('planned_hrsfuel', 26);
define('planned_minfuel', 27);
define('planned_altairport', 28);
define('planned_remarks', 29);
define('planned_route', 30);
define('planned_depairport_lat', 31);
define('planned_depairport_lon', 32);
define('planned_destairport_lat', 33);
define('planned_destairport_lon', 34);
define('atis_message', 35);
define('time_last_atis_received', 36);
define('time_logon', 37);
define('heading', 38);
define('QNH_iHg', 39);
define('QNH_Mb', 40);

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
$pdo = new PDO($dsn, config("db_user") , config("db_pass") , $opt);
$readpdo = new PDO($dsn, config("db_user") , config("db_pass") , $opt);
$flightpdo = new PDO($dsn, config("db_user") , config("db_pass") , $opt);
$prepareds['select_flight'] = "SELECT * FROM `flights` WHERE `callsign`=:callsign AND `vatsim_id`=:vatsim_id AND `created_at` >= DATE_SUB(NOW(), INTERVAL 24 HOUR) ORDER BY created_at DESC LIMIT 1";
$prepareds['update_flight'] = "UPDATE `flights` SET `aircraft_type`=:aircraft_type, `departure`=:departure, `arrival`=:arrival, `planned_alt`=:planned_alt, `route`=:route, `remarks`=:remarks, `status`=:status, `departed_at`=:departed_at, `arrived_at`=:arrived_at, `arrival_est`=:arrival_est, `lat`=:lat, `lon`=:lon, `alt`=:alt, `hdg`=:hdg, `spd`=:spd, `missing_count`=:missing_count, `last_update`=:last_update, updated_at=NOW() WHERE `id`=:id";
$prepareds['insert_flight'] = "INSERT INTO `flights`(`callsign`,`vatsim_id`,`aircraft_type`,`departure`,`arrival`,`planned_alt`,`route`,`remarks`,`status`,`lat`,`lon`,`alt`,`hdg`,`spd`,`last_update`,`created_at`,`updated_at`) VALUES(:callsign, :vatsim_id, :aircraft_type, :departure, :arrival, :planned_alt, :route, :remarks, :status, :lat, :lon, :alt, :hdg, :spd, :last_update, NOW(), NOW())";
$prepareds['update_missing_count'] = "UPDATE `flights` SET `missing_count`=:missing_count WHERE `id`=:id";
$prepareds['select_airport'] = "SELECT lat, lon, elevation FROM airports WHERE id=:id";
$prepareds['select_position'] = "SELECT created_at FROM positions WHERE flight_id=:flight_id ORDER BY created_at DESC LIMIT 1";
$prepareds['insert_position'] = "INSERT INTO `positions`(`id`,`flight_id`,`lat`,`lon`,`alt`,`spd`,`hdg`,`created_at`,`updated_at`) VALUES(:id, :flight_id, :lat, :lon, :alt, :spd, :hdg, NOW(), NOW())";
$prepareds['select_missing'] = "SELECT * FROM flights WHERE status NOT LIKE 'Arrived' AND 'status' NOT LIKE 'Incomplete' AND last_update < :current_update";
$prepareds['update_status'] = "UPDATE flights SET status=:status WHERE id=:id";
$prepareds['delete_flight'] = "DELETE FROM flights WHERE id=:id";
$prepareds['delete_positions'] = "DELETE FROM positions WHERE flight_id=:flight_id";

$airport = [];

function checkArrival($lat, $lon, $spd, $arrival)
{
  global $readpdo, $prepareds, $airport;

  if ($arrival == "ZZZZ" || $arrival == '' || $arrival == null) {
    return false;
  }
  if (!isset($airport[$arrival])) {
    $stmt = $readpdo->prepare($prepareds['select_airport']);
    $stmt->execute([":id" => $arrival]);
    $row = $stmt->fetch();
    if ($row) {
      $airport[$arrival]['lat'] = $row['lat'];
      $airport[$arrival]['lon'] = $row['lon'];
      $airport[$arrival]['elevation'] = $row['elevation'];
    } else {
      return false;
    }
  }
  if (!isset($airport[$arrival])) return false;
  if (calc_distance($lat, $lon, $airport[$arrival]['lat'], $airport[$arrival]['lon']) < 3 && $spd < 40) {
    return true;
  }

  return false;
}

function checkDeparture($lat, $lon, $spd, $departure)
{
  global $readpdo, $prepareds, $airport;

  if ($departure == "ZZZZ" || $departure == '' || $departure == null) {
    return false;
  }
  if (!isset($airport[$departure])) {
    $stmt = $readpdo->prepare($prepareds['select_airport']);
    $stmt->execute([":id" => $departure]);
    $row = $stmt->fetch();
    if ($row) {
      $airport[$departure]['lat'] = $row['lat'];
      $airport[$departure]['lon'] = $row['lon'];
      $airport[$departure]['elevation'] = $row['elevation'];
    } else {
      return false;
    }
  }
  if (!isset($airport[$departure])) return false;
  if (calc_distance($lat, $lon, $airport[$departure]['lat'], $airport[$departure]['lon']) < 3 && $spd < 40) {
    return true;
  }

  return false;
}

function arrivalEst($lat, $lon, $spd, $arrival, $status)
{
  global $readpdo, $prepareds, $airport;

  if ($status != "En-Route") {
    return 0;
  }

  if (!$arrival || $spd <= 0) {
    return;
  }

  if (!isset($airport[$arrival])) {
    $stmt = $readpdo->prepare($prepareds['select_airport']);
    $stmt->execute([":id" => $arrival]);
    $row = $stmt->fetch();
    if ($row) {
      $airport[$arrival]['lat'] = $row['lat'];
      $airport[$arrival]['lon'] = $row['lon'];
      $airport[$arrival]['elevation'] = $row['elevation'];
    } else {
      return false;
    }
  }
  $dist = calc_distance($lat, $lon, $airport[$arrival]['lat'], $airport[$arrival]['lon']);
  $time = $dist / $spd; // Ground speed estimate
  $hr = floor($time);
  $min = intval((($hr - $time) + .25) * 60);
  $est = new DateTime();
  if ($hr > 0) { $hr = $hr . "H"; } else { $hr = ""; }
  if ($min > 0) { $min = $min . "M"; } else { $min = "15M"; }
  $est->add(new DateInterval("P" . $hr . $min));
  return $est->format("Y-m-d H:i:s");
}

function airborne($spd)
{
  if ($spd > 50) {
    return true;
  }

  return false;
}

function calc_distance($lat1, $lon1, $lat2, $lon2)
{
  $lat1 = deg2rad(floatval($lat1));
  $lon1 = deg2rad(floatval($lon1));
  $lat2 = deg2rad(floatval($lat2));
  $lon2 = deg2rad(floatval($lon2));
  $theta = $lon1 - $lon2;
  $dist = sin(deg2rad($lat1)) * sin(deg2rad($lat2)) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos(deg2rad($theta));
  $dist = acos($dist);
  $dist = rad2deg($dist);
  return abs($dist * 60 * 1.1515 * 0.8684);
}
