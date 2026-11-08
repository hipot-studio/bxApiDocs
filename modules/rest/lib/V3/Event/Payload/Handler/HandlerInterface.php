<?php

namespace Bitrix\Rest\V3\Event\Payload\Handler;

interface HandlerInterface
{
	public function handle(array $payload): array;
}