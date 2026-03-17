<?php

use CrazyGoat\RabbitStream\Request\OpenRequest;
use CrazyGoat\RabbitStream\Request\PeerPropertiesToStreamBufferV1;
use CrazyGoat\RabbitStream\Request\SaslAuthenticateRequestV1;
use CrazyGoat\RabbitStream\Request\SaslHandshakeRequestV1;
use CrazyGoat\RabbitStream\Request\TuneRequestV1;
use CrazyGoat\RabbitStream\Response\OpenResponseV1;
use CrazyGoat\RabbitStream\Response\TuneResponseV1;
use CrazyGoat\RabbitStream\StreamConnection;

include __DIR__ . '/../vendor/autoload.php';

$connection = new StreamConnection('172.17.0.2', 5552);
$connection->connect();
$connection->sendMessage(new PeerPropertiesToStreamBufferV1());
$response = $connection->readMessage();
var_dump($response);

$connection->sendMessage(new SaslHandshakeRequestV1());
$response = $response = $connection->readMessage();
var_dump($response);

$connection->sendMessage(new SaslAuthenticateRequestV1("PLAIN", "guest", "guest"));
$response = $response = $connection->readMessage();
var_dump($response);

$response = $connection->readMessage();
var_dump($response);
if (!$response instanceof TuneRequestV1) {
    throw new Exception("Failed to read Tune request");
}

$connection->sendMessage(new TuneResponseV1($response->getFrameMax(), $response->getHeartbeat()));
$connection->sendMessage(new OpenRequest());

$response = $connection->readMessage();
var_dump($response);
if (!$response instanceof OpenResponseV1) {
    throw new Exception("Failed to read Tune request");
}

$connection->close();