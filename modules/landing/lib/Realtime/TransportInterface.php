<?php

declare(strict_types=1);

namespace Bitrix\Landing\Realtime;

interface TransportInterface
{
	public function send(array $recipientIds, Event $event): void;
}
