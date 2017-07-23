<?php
require(".config.php");

require("global.php");

if (file_exists(".lastts")) {
  $lastts = file_get_contents(".lastts");
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

$fp = fopen(".lastts", "w");
fwrite($fp, $output[0]);
fclose($fp);

$stream = file(config("DATAFILE"), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$in_clients = false;
foreach ($stream as $line) {
  if (preg_match("/^!CLIENTS:/", $line)) {
    $in_clients = true;
    continue;
  }
  elseif (preg_match("/^!/", $line) && $in_clients) { $in_clients = false; }

  if (!$in_clients || preg_match("/^;/", $line)) continue;

  process_line($line);

  process_missing();
}

function process_line($line) {
  global $pdo, $readpdo, $prepareds, $current_update;

  $data = explode(":", $line);
  // Skip bad data or ATC clients
  if (!$data[cid]) return;
  if ($data[clienttype] == "ATC") return;
  if (!$data[planned_depairport] || !$data[planned_destairport]) return;
  if (!$data[latitude] || !$data[longitude]) return;

  echo $data[callsign] . " processing...\n";

  // Little reformatting
  $data[callsign] = str_replace("-", "", $data[callsign]);
  $data[planned_altitude] = preg_replace("/[FAL]+/", "", $data[planned_altitude]); // Change ICAO and FL350 altitude entries into VATSIM-standard
  if (strlen($data[planned_altitude]) < 4) $data[planned_altitude] = $data[planned_altitude] . "00"; // Make altitudes consistent
  $data[planned_route] = str_replace("DCT", "", $data[planned_route]);
  $data[planned_route] = preg_replace("/[\+\.]/", " ", $data[planned_route]); // Ignore VATSIM ATC amendment and misc. markings
  $data[planned_route] = preg_replace("/\s+/", " ", trim($data[planned_route])); // Remove extra spaces

  $new = 0;
  $stmt = $pdo->prepare($prepareds['select_flight'])->execute([
    ':callsign' => $data[callsign],
    ':vatsim_id' => $data[cid]
  ]);
  $flight = $stmt->fetch();
  if ($flight && $flight['status'] == "Incomplete" && $flight['departure'] == substr($data[planned_depairport], 0, 4)
    && $flight['arrival'] == substr($data[planned_destairport], 0, 4) && !check_departure($data[latitude], $data[longitude], $data[groundspeed], $data[planned_destairport])) {
      // Resuming flight
      $flight['status'] = "En-Route";
  } elseif (!$flight || ($flight['status'] == "Arrived" && !checkArrival($data[latitude], $data[longitude], $data[groundspeed], $data[planned_destairport]))) {
    $new = 1;
    $flight = [
      'callsign' => $data[callsign],
      'vatsim_id' => $data[cid]
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
  // Update flight plan details
  $flight['departure'] = substr($data[planned_depairport], 0, 4);
  $flight['arrival'] = substr($data[planned_destairport], 0, 4);
  $flight['planned_alt'] = $data[planned_altitude];
  $changedstatus = 0;

  if (($flight['status'] == "En-Route" || $flight['status'] == "Incomplete") && checkArrival($flight['lat'], $flight['lon'], $flight['spd'], $flight['arrival'])) {
    $flight['arrived_at'] = date("Y-m-d H:i:s");
    $changedstatus = 1;
    $flight['status'] = "Arrived";
  } elseif ($flight['status'] == "Unknown" || $flight['status'] == "Incomplete") {
    // Try to set a proper status
    if (checkDeparture($flight['lat'], $flight['lon'], $flight['spd'], $flight['departure'])) {
      $flight['status'] = "Departing Soon"; $changedstatus = 1;
    } elseif (checkArrival($flight['lat'], $flight['lon'], $flight['spd'], $flight['arrival'])) {
      $flight['status'] = "Arrived"; $changedstatus = 1;
    } elseif (airborne($flight['spd'])) {
      $flight['status'] = "En-Route"; $changedstatus = 1;
    } else {
      $flight->status = "Unknown"; $changedstatus = 1;    // Should never get here unless the plane diverted elsewhere without a FP change
    }
  } elseif (($flight['status'] == "Departing Soon" || $flight['status'] == "Incomplete") && airborne($flight['spd'])) {
    $flight['departed_at'] = ($flight['departed_at']) ? $flight['departed_at'] : date("Y-m-d H:i:s");
    $flight['status'] = "En-Route";
    $changedstatus = 1;
  }

  $flight['last_update'] = $current_update;
  $flight['missing_count'] = 0;
  if ($flight['status'] == "En-Route") {
    $flight['arrival_est'] = arrivalEst($flight['lat'],$flight['lon'],$flight['spd'],$flight['arrival'],$flight['status']);
  }

  if (!$new) {
    $allowed = ['id','aircraft_type','departure','arrival','planned_alt','route','remarks','status','departed_at','arrived_at','arrival_est','lat','lon','alt','hdg','spd','last_update','missing_count'];
    $pdo->prepare($prepareds['update_flight'])->execute(
      array_filter($flight, function($key) use ($allowed) { return in_array($key, $allowed);}, ARRAY_FILTER_USE_KEY)
    );
  } else {
    $allowed = ['callsign','vatsim_id','aircraft_type','departure','arrival','planned_alt','route','remarks','status','lat','lon','alt','hdg','spd','last_update'];
    $pdo->prepare($prepareds['insert_flight'])->execute(
      array_filter($flight, function($key) use ($allowed) { return in_array($key, $allowed);}, ARRAY_FILTER_USE_KEY)
    );
    $flight['id'] = $pdo->lastInsertId();
  }

  $interval = 10;
  if ($flight['spd'] >= 250) {
    $interval = 2;
  } elseif ($flight['spd'] >= 100) {
    $interval = 5;
  }

  $interval = 0;
  $row = $pdo->prepare($prepareds['select_position'])->execute(['flight_id' => $flight['id']]);
  if ($row) {
    $lastposition = new DateTime($row['created_at']);
    $posinterval = $lastpos->diff(new DateTime("now"), true);
    $posinterval = $lastpos->format("%i");
  }
  if (!$row || ($row && $posinterval >= $interval) || $changedstatus || $new) {
    $pdo->prepare($prepareds['insert_position'])->execute([
      'id'        => $flight['id'] . '-' . $current_update,
      'flight_id' => $flight['id'],
      'lat'       => $flight['lat'],
      'lon'       => $flight['lon'],
      'alt'       => $flight['alt'],
      'spd'       => $flight['spd'],
      'hdg'       => $flight['hdg']
    ]);
  }
}

function process_missing() {
  global $readpdo, $pdo, $prepareds, $current_update;

  $stmt = $readpdo->prepare($prepareds['select_missing'])->execute(['current_update' => $current_update]);
  if ($stmt) {
    while ($row = $stmt->fetch()) {
      $pdo->prepare($prepareds['update_missing_count'])->execute(['id' => $row['id'], 'missing_count' => $row['missing_count'] + 1]);

      $row['missing_count'] += 1; // For processing's sake

      if ($row['missing_count'] >= 5) {
        if ($row['status'] == "Departing Soon") {
          $pdo->prepare($prepareds['delete_flight'])->execute(['id' => $flight['id']]);
          $pdo->prepare($prepareds['delete_positions'])->execute(['flight_id' => $flight['id']]);
        } else {
          $pdo->prepare($prepareds['update_missing_count'])->execute(['id' => $flight['id'], 'missing_count' => 0]);
          $pdo->prepare($prepareds['update_status'])->execute(['id' => $flight['id'], 'status' => 'Incomplete']);
        }
      }
    }
  }
}
