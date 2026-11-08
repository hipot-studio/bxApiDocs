<?php

namespace Bitrix\Rest\V3\Event\Payload;

use Bitrix\Rest\V3\Event\EventType;

interface PayloadInterface
{
	public function getType(): EventType;

	public function getSchema(): array;

	public function getHandlerClass(): ?string;
}