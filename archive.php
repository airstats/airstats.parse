<?php
require 'aws/aws-autoloader.php';
require '.config.php';
require 'global.php';
require 'vendor/autoload.php';

use MicrosoftAzure\Storage\Blob\BlobRestProxy;

$blobClient = BlobRestProxy::createBlobService(config('azure_connection_string'));

$flights = $flightpdo->query("SELECT *, DATE_FORMAT(`created_at`, '%Y%m%d%H%i%s') AS filedate FROM `flights` WHERE `archived`='' AND (`status`='Arrived' OR `status`='Incomplete') AND `updated_at` <= DATE_SUB(NOW(), INTERVAL 1 DAY)")->fetchAll();

foreach($flights as $flight) {
  $positions = $flightpdo->query("SELECT * FROM `positions` WHERE `flight_id`='" . $flight['id'] . "' ORDER BY `created_at` ASC")->fetchAll();
  $posdata = [];
  foreach($positions as $pos) {
    $posdata[] = [
      'lat' => $pos['lat'],
      'lon' => $pos['lon'],
      'alt' => $pos['alt'],
      'spd' => $pos['spd'],
      'hdg' => $pos['hdg'],
      'date' => $pos['created_at']
    ];
  }
  $file = "flights/" . $flight['callsign'] . "_" . $flight['filedate'] . ".json";
  $blobClient->createBlockBlob(
    "flights",
    $flight['callsign'] . "_" . $flight['filedate'] . ".json",
    bzcompress(json_encode($posdata, JSON_NUMERIC_CHECK), 9)
  );
  $flightpdo->prepare("UPDATE flights SET archived='$file' WHERE `id`=?")->execute([$flight['id']]);
  $flightpdo->prepare("DELETE FROM `positions` WHERE `flight_id`=?")->execute([$flight['id']]);
}
