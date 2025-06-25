<?php

namespace CrazyGoat\StreamyCarrot\Request;

use CrazyGoat\StreamyCarrot\CommandCode;

interface RequestInterface
{
    public function getCommandCode(): CommandCode;
    public function withCorrelationId(int $correlationId): void;
    public function getCorrelationId(): int;
}