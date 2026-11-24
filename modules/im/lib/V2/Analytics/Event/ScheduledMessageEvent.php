<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Analytics\Event;

use DateInterval;

class ScheduledMessageEvent extends ChatEvent
{
	public function setSendDelayP3(DateInterval $delay): self
	{
		$this->p3 = 'scheduled_' . $this->formatDelay($delay);

		return $this;
	}

	private function formatDelay(DateInterval $delay): string
	{
		if ($delay->y > 0)
		{
			return '1y';
		}

		if ($delay->m > 0)
		{
			return $delay->m . 'm';
		}

		if ($delay->d > 0)
		{
			return $delay->d . 'd';
		}

		return $delay->h . 'h';
	}
}
