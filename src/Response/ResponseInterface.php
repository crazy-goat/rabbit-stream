<?php

namespace CrazyGoat\StreamyCarrot\Response;

interface ResponseInterface
{
    public function __construct(ReadBuffer $responseBuffer);
}