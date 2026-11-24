<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Analytics\Event;

/**
 * A gesture aimed at the messenger rather than at one chat. The category names the product, and the
 * parameters a chat fills are left empty on purpose: the question such an event answers is whether
 * the gesture is used at all and from where, so a chat of its own would only split the rows.
 */
class MessengerEvent extends ChatEvent
{
	protected function getCategory(string $eventName): string
	{
		return 'messenger';
	}

	protected function setDefaultParams(): self
	{
		return $this;
	}
}
