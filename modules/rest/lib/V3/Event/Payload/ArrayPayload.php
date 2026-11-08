<?php

namespace Bitrix\Rest\V3\Event\Payload;

use Bitrix\Rest\V3\Event\EventType;

class ArrayPayload extends AbstractPayload
{
	public function getType(): EventType
	{
		return EventType::Array;
	}

	public function getSchema(): array
	{
		return [];
	}
}