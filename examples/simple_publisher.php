<?php

use CrazyGoat\StreamyCarrot\Request\OpenRequest;
use CrazyGoat\StreamyCarrot\Request\PeerPropertiesToStreamBufferV1;
use CrazyGoat\StreamyCarrot\Request\SaslAuthenticateRequestV1;
use CrazyGoat\StreamyCarrot\Request\SaslHandshakeRequestV1;
use CrazyGoat\StreamyCarrot\Request\TuneRequestV1;
use CrazyGoat\StreamyCarrot\Response\OpenResponseV1;
use CrazyGoat\StreamyCarrot\Response\ResponseBuilder;
use CrazyGoat\StreamyCarrot\Response\TuneResponseV1;
use CrazyGoat\StreamyCarrot\StreamConnection;

include __DIR__ . '/../vendor/autoload.php';

$connection = new StreamConnection('172.17.0.2', 5552);
$connection->connect();
$response = ResponseBuilder::fromResponseBuffer($connection->sendMessage(new PeerPropertiesToStreamBufferV1()));
var_dump($response);

$response = ResponseBuilder::fromResponseBuffer($connection->sendMessage(new SaslHandshakeRequestV1()));
var_dump($response);

$response = ResponseBuilder::fromResponseBuffer($connection->sendMessage(new SaslAuthenticateRequestV1("PLAIN", "guest", "guest")));
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