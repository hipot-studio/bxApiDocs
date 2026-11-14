<?php

namespace Bitrix\Call\Analytics;

use Bitrix\Call\Analytics\Event\ReactionEvent;

/**
 * @internal
 */
class ReactionAnalytics extends AbstractAnalytics
{
	protected const ADD_REACTION = 'add_reaction';

	/**
	 * @param string $source Where the reaction was picked from, see ReactionEvent::SOURCE_*.
	 */
	public function reactionUsed(string $reactionId, string $source = ''): void
	{
		$this->async(function () use ($reactionId, $source) {
			$this
				->createEvent(self::ADD_REACTION, $reactionId, $source)
				?->send()
			;
		});
	}

	protected function createEvent(string $eventName, string $reactionId, string $source): ?ReactionEvent
	{
		return (new ReactionEvent($eventName, $this->call, $reactionId, $source));
	}
}
