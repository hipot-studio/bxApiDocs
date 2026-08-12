<?php

declare(strict_types=1);

namespace Bitrix\Landing\Realtime;

interface EventEnricherInterface
{
	public function supports(Event $event): bool;

	public function enrich(Event $event): Event;
}
