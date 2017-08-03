<?php
require(".config.php");
require("parse.def.php");
require("global.php");

if (file_exists(".lastts")) {
  $lastts = file_get_contents(".lastts");
} else {
  $lastts = false;
}

if (!file_exists(config('DATAFILE'))) {
  echo "No datafile defined or does not exist.";
  exit;
}

// Make sure data file complete, otherwise wait a second and try again up to 10 times.
exec("sed -n \"/;   END/p\" " . config('DATAFILE'), $output);
$ready = false; $count = 0;
if (isset($output[0]) && $output[0] == ";  END") {
  $ready = true;
} else {
  $count++;
  if ($count >= 10) { echo "Failed"; exit;}
  sleep(1);
}

unset($output);
// Get update timestamp, don't duplicate updates.
exec("sed -n -r \"s/UPDATE = ([0-9]+)/\\1/p\" " . config('DATAFILE'), $output);
if ($lastts && $output[0] == $lastts) {
  exit;                         // We got this TS already
}

$current_update = $output[0];
$current_stamp = substr($current_update, 0, 4) . "-" . substr($current_update, 4, 2) . "-" . substr($current_update, 6, 2) . " " . substr($current_update, 8, 2) . ":" . substr($current_update, 10, 2) . ":" . substr($current_update, 12, 2);
$current_minute = substr($current_update, 10, 2);

$fp = fopen(".lastts", "w");
fwrite($fp, $output[0]);
fclose($fp);

$stream = file(config("DATAFILE"), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$in_clients = false;
$x = 0;
foreach ($stream as $line) {
  if (preg_match("/^!CLIENTS:/", $line)) {
    $in_clients = true;
    continue;
  }
  elseif (preg_match("/^!/", $line) && $in_clients) { $in_clients = false; }

  if (!$in_clients || preg_match("/^;/", $line)) continue;

  /*if ($x == 0 || $x == 500) {
    if ($x == 500) { $flightpdo->commit(); }
    $flightpdo->beginTransaction();
    $x = 1;
  }*/
  process_line($line);

  $x++;
}
//$flightpdo->commit();
process_missing();

function process_line($line) {
  global $prepareds, $current_update, $flightpdo, $current_stamp, $current_minute;

  $data = explode(":", $line);
  // Skip bad data or ATC clients
  if (!$data[cid]) return;
  if ($data[clienttype] == "ATC") return;
  if (!$data[planned_depairport] || !$data[planned_destairport]) return;
  if (!$data[latitude] || !$data[longitude]) return;

  // Little reformatting
  $data[callsign] = str_replace("-", "", $data[callsign]);
  $data[planned_altitude] = preg_replace("/[FAL]+/", "", $data[planned_altitude]); // Change ICAO and FL350 altitude entries into VATSIM-standard
  if (strlen($data[planned_altitude]) < 4) $data[planned_altitude] = $data[planned_altitude] . "00"; // Make altitudes consistent
  $data[planned_route] = str_replace("DCT", "", $data[planned_route]);
  $data[planned_route] = preg_replace("/[\+\.]/", " ", $data[planned_route]); // Ignore VATSIM ATC amendment and misc. markings
  $data[planned_route] = preg_replace("/\s+/", " ", trim($data[planned_route])); // Remove extra spaces

  $new = 0;
  $stmt = $flightpdo->prepare($prepareds['select_flight']);
  $stmt->execute([
    ':callsign' => $data[callsign],
    ':vatsim_id' => $data[cid]
  ]);
  if (!$stmt)
    $flight = false;
  else
    $flight = $stmt->fetch();

  if ($flight && $flight['status'] == "Incomplete" && $flight['departure'] == substr($data[planned_depairport], 0, 4)
    && $flight['arrival'] == substr($data[planned_destairport], 0, 4) && !checkDeparture($data[latitude], $data[longitude], $data[groundspeed], $data[planned_destairport])) {
      // Resuming flight
      $flight['status'] = "En-Route";
  } elseif (!$flight || ($flight['status'] == "Arrived" && !checkArrival($data[latitude], $data[longitude], $data[groundspeed], $data[planned_destairport]))) {
    $new = 1;
    $flight = [
      'callsign' => $data[callsign],
      'vatsim_id' => $data[cid],
      'aircraft_type' => '',
      'departure' => '',
      'arrival' => '',
      'route' => '',
      'remarks' => '',
      'planned_alt' => '',
      'status' => 'Departing Soon',
      'departed_at' => '',
      'arrived_at' => '',
      'arrival_est' => '0000-00-00 00:00:00',
      'lat' => 0,
      'lon' => 0,
      'hdg' => 0,
      'spd' => 0,
      'last_update' => '',
      'missing_count' => 0
    ];
  }

  $flight['lat'] = $data[latitude];
  $flight['lon'] = $data[longitude];
  $flight['alt'] = $data[altitude];
  $flight['hdg'] = $data[heading];
  $flight['spd'] = $data[groundspeed];
  $flight['route'] = $data[planned_route];
  $flight['remarks'] = $data[planned_remarks];
  // For aircraft type, filter out excess info
  // Set aircraft, ensure to filter out things like (2H/) and (/L)
  if ( preg_match("/^(?:.\/)?([^\/]+)(?:\/.)?/", $data[planned_aircraft], $matches)) {
    $flight['aircraft_type'] = substr($matches[1], 0, 4);
  } else {
    $flight['aircraft_type'] = $data[planned_aircraft];
  }
  // Final cleanup
  $flight['aircraft_type'] = substr(str_replace('/','', $flight['aircraft_type']), 0, 4);
  // Update flight plan details
  $flight['departure'] = substr($data[planned_depairport], 0, 4);
  $flight['arrival'] = substr($data[planned_destairport], 0, 4);
  $flight['planned_alt'] = $data[planned_altitude];
  $changedstatus = 0;

  if (($flight['status'] == "En-Route" || $flight['status'] == "Incomplete") && $flight['spd'] < 40 && checkArrival($flight['lat'], $flight['lon'], $flight['spd'], $flight['arrival'])) {
    $flight['arrived_at'] = $current_stamp;
    $changedstatus = 1;
    $flight['status'] = "Arrived";
  } elseif ($flight['status'] == "Unknown" || $flight['status'] == "Incomplete") {
    // Try to set a proper status
    if (checkDeparture($flight['lat'], $flight['lon'], $flight['spd'], $flight['departure'])) {
      $flight['status'] = "Departing Soon"; $changedstatus = 1;
    } elseif ($flight['spd'] < 50 && checkArrival($flight['lat'], $flight['lon'], $flight['spd'], $flight['arrival'])) {
      $flight['status'] = "Arrived"; $changedstatus = 1;
    } elseif (airborne($flight['spd'])) {
      $flight['status'] = "En-Route"; $changedstatus = 1;
    } else {
      $flight['status'] = "Unknown"; $changedstatus = 1;    // Should never get here unless the plane diverted elsewhere without a FP change
    }
  } elseif (($flight['status'] == "Departing Soon" || $flight['status'] == "Incomplete") && airborne($flight['spd'])) {
    $flight['departed_at'] = ($flight['departed_at']) ? $flight['departed_at'] : $current_stamp;
    $flight['status'] = "En-Route";
    $changedstatus = 1;
  }

  $flight['last_update'] = $current_update;
  $flight['missing_count'] = 0;
  /*if ($flight['status'] == "En-Route") {
    $flight['arrival_est'] = arrivalEst($flight['lat'],$flight['lon'],$flight['spd'],$flight['arrival'],$flight['status']);
  }*/

  if (!$new) {
    if (empty($flight['arrival_est'])) { $flight['arrival_est'] = null; }
    $allowed = ['id','aircraft_type','departure','arrival','planned_alt','route','remarks','status','departed_at','arrived_at','arrival_est','lat','lon','alt','hdg','spd','last_update','missing_count'];
    $flightpdo->prepare($prepareds['update_flight'])->execute(
      array_filter($flight, function($key) use ($allowed) { return in_array($key, $allowed);}, ARRAY_FILTER_USE_KEY)
    );
  } else {
    $allowed = ['callsign','vatsim_id','aircraft_type','departure','arrival','planned_alt','route','remarks','status','lat','lon','alt','hdg','spd','last_update'];
    $flightpdo->prepare($prepareds['insert_flight'])->execute(
      array_filter($flight, function($key) use ($allowed) { return in_array($key, $allowed);}, ARRAY_FILTER_USE_KEY)
    );
    $flight['id'] = $flightpdo->lastInsertId();
  }

  $interval = 10;
  if ($flight['spd'] >= 250) {
    $interval = 2;
  } elseif ($flight['spd'] >= 100) {
    $interval = 5;
  }

  // Synchornize them.

  if (($current_minute % $interval == 0) || $changedstatus || $new) {
    $flightpdo->prepare($prepareds['insert_position'])->execute([
      'id'        => $flight['id'] . '-' . $current_update,
      'flight_id' => $flight['id'],
      'lat'       => $flight['lat'],
      'lon'       => $flight['lon'],
      'alt'       => $flight['alt'],
      'spd'       => $flight['spd'],
      'hdg'       => $flight['hdg'],
      'created'   => $current_stamp,
      'updated'   => $current_stamp
    ]);
  }
}

function process_missing() {
  global $flightpdo, $prepareds, $current_update,$current_stamp;

  $stmt = $flightpdo->prepare($prepareds['select_missing']);
  $stmt->execute(['current_update' => $current_update]);
  //$log = "";
  if ($stmt) {
    while ($row = $stmt->fetch()) {
      $flightpdo->prepare($prepareds['update_missing_count'])->execute(['id' => $row['id'], 'missing_count' => $row['missing_count'] + 1]);

      $row['missing_count'] += 1; // For processing's sake
//      $log .= "$current_stamp," . $row['callsign'] . "," . $row['missing_count'] . "," . $row['last_update'] . "," . $row['status'] . ",\n";
      if ($row['missing_count'] >= 10) {
        if ($row['status'] == "Departing Soon") {
          $flightpdo->prepare($prepareds['delete_flight'])->execute(['id' => $row['id']]);
          $flightpdo->prepare($prepareds['delete_positions'])->execute(['flight_id' => $row['id']]);
        } else {
          $flightpdo->prepare($prepareds['update_missing_count'])->execute(['id' => $row['id'], 'missing_count' => 0]);
          $flightpdo->prepare($prepareds['update_status'])->execute(['id' => $row['id'], 'status' => 'Incomplete']);
        }
      }
    }
  }

  //file_put_contents("log", $log, FILE_APPEND);
}

function checkArrival($lat, $lon, $spd, $arrival)
{
  global $flightpdo, $prepareds, $airport;

  if ($arrival == "ZZZZ" || $arrival == '' || $arrival == null) {
    return false;
  }
  if (!isset($airport[$arrival])) {
    $stmt = $flightpdo->prepare($prepareds['select_airport']);
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
  global $flightpdo, $prepareds, $airport;

  if ($departure == "ZZZZ" || $departure == '' || $departure == null) {
    return false;
  }
  if (!isset($airport[$departure])) {
    $stmt = $flightpdo->prepare($prepareds['select_airport']);
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
  global $flightpdo, $prepareds, $airport;

  if ($status != "En-Route") {
    return 0;
  }

  if (!$arrival || $spd <= 0) {
    return;
  }

  if (!isset($airport[$arrival])) {
    $stmt = $flightpdo->prepare($prepareds['select_airport']);
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
  $est->add(new DateInterval("PT" . $hr . $min));
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
  $theta = $lon1 - $lon2;
  $dist = sin(deg2rad($lat1)) * sin(deg2rad($lat2)) +  cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos(deg2rad($theta));
  $dist = acos($dist);
  $dist = rad2deg($dist);
  return abs($dist * 60 * 1.1515 * 0.8684);
}
