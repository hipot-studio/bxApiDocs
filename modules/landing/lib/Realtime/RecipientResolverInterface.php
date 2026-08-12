<?php

declare(strict_types=1);

namespace Bitrix\Landing\Realtime;

interface RecipientResolverInterface
{
	public function resolve(Event $event): array;
}
