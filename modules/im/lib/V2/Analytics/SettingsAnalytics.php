<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Analytics;

use Bitrix\Main\Analytics\AnalyticsEvent;

/**
 * Analytics for global messenger settings that are not bound to a specific chat,
 * so the chat-scoped Event hierarchy does not fit here.
 */
class SettingsAnalytics
{
	private const TOOL = 'im';
	private const CATEGORY = 'messenger';
	private const EVENT_SET_MAIN_REACTION = 'set_main_reaction';

	public function addSetMainReaction(int $userId, string $reactionName): void
	{
		$this->sendSafely($this->createSetMainReactionEvent($userId, $reactionName));
	}

	public function createSetMainReactionEvent(int $userId, string $reactionName): AnalyticsEvent
	{
		return (new AnalyticsEvent(self::EVENT_SET_MAIN_REACTION, self::TOOL, self::CATEGORY))
			->setUserId($userId)
			->setType($reactionName)
		;
	}

	private function sendSafely(AnalyticsEvent $event): void
	{
		try
		{
			$event->send();
		}
		catch (\Throwable)
		{
			// Analytics is best-effort and must never break the settings write path.
		}
	}
}
