<?php

declare(strict_types=1);

namespace Bitrix\Landing\Realtime\Recipient;

use Bitrix\Landing\Realtime\Event;
use Bitrix\Landing\Realtime\RecipientResolverInterface;

final class InitiatorRecipientResolver implements RecipientResolverInterface
{
	public function resolve(Event $event): array
	{
		$initiatorUserId = $event->getInitiatorUserId();

		return $initiatorUserId > 0 ? [$initiatorUserId] : [];
	}
}
