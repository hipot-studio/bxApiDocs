<?php

declare(strict_types=1);

namespace Bitrix\Landing\Realtime;

final class EventSerializer implements EventSerializerInterface
{
	public function serialize(Event $event): array
	{
		return [
			'eventName' => $event->getEventName(),
			'entityType' => $event->getEntityType(),
			'entityId' => $event->getEntityId(),
			'action' => $event->getAction(),
			'source' => $event->getSource(),
			'scope' => $event->getScope(),
			'meta' => $event->getMeta(),
			'ts' => time(),
		];
	}
}
