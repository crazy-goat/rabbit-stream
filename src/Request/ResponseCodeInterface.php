<?php

namespace CrazyGoat\StreamyCarrot\Request;

use CrazyGoat\StreamyCarrot\ResponseCode;

interface ResponseCodeInterface
{
    public function setResponseCode(ResponseCode $code): void;
    public function getResponseCode(): ResponseCode;
}