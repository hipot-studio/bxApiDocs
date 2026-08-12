<?php

declare(strict_types=1);

namespace Bitrix\Landing\Realtime;

interface EventSerializerInterface
{
	public function serialize(Event $event): array;
}
