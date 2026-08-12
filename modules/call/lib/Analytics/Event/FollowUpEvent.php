<?php

namespace Bitrix\Call\Analytics\Event;

/**
 * @internal
 */
class FollowUpEvent extends Event
{
	protected function setDefaultParams(): self
	{
		if ($this->call->getUuid() !== null)
		{
			$this->setP5('callId_' . $this->call->getUuid());
		}

		return $this;
	}

	/**
	 * Parameter st[category].
	 * @param string $eventName
	 * @return string
	 */
	protected function getCategory(string $eventName): string
	{
		return 'call_followup';
	}
}
