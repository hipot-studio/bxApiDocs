<?php

namespace Bitrix\ImOpenLines\V2\Message;

use Bitrix\Main\Loader;

Loader::requireModule('im');

class EventHandler
{
	/**
	 * Handles the ExternalChat BeforeSendMessage event for LINES entity type.
	 *
	 * @see \Bitrix\Im\V2\Chat\ExternalChat\Event\BeforeMessageSendEvent
	 * @param \Bitrix\Im\V2\Chat\ExternalChat\Event\BeforeMessageSendEvent $event
	 * @return void
	 */
	public static function onBeforeSendMessageExternalChatLines(\Bitrix\Im\V2\Chat\ExternalChat\Event\BeforeMessageSendEvent $event): void
	{
		\Bitrix\ImOpenLines\V2\Message\MessageEnricher::getInstance()->enrich($event->getMessage());
	}
}
