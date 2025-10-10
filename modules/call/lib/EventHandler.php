<?php

namespace Bitrix\Call;

use Bitrix\Main\Event;
use Bitrix\Main\Entity\EventResult;
use Bitrix\Im\Call\Call;
use Bitrix\Im\V2\Call\CallFactory;
use Bitrix\Im\Call\Integration\EntityType;
use Bitrix\Call\Cache\ActiveCallsCache;


class EventHandler
{
	/**
	 * @event 'im:OnChatUserDelete'
	 * @see \Bitrix\Im\V2\Chat::sendEventUserDelete
	 * @param Event $event
	 * @return EventResult
	 */
	public static function onChatUserLeave(Event $event): EventResult
	{
		$result = new EventResult;

		/** @var array{chatId: int, userIds: int[]} $eventData */
		$eventData = $event->getParameters();
		if (!empty($eventData['chatId']) && !empty($eventData['userIds']))
		{
			['chatId' => $chatId, 'userIds' => $userIds] = $eventData;

			$type = Call::TYPE_INSTANT;
			$chat = \Bitrix\Im\V2\Chat::getInstance($chatId);
			if ($chat->getEntityType() == \Bitrix\Im\V2\Chat\ExtendedType::Videoconference->value)
			{
				$type = Call::TYPE_PERMANENT;
			}

			$call = CallFactory::searchActive(
				type: $type,
				provider: Call::PROVIDER_BITRIX,
				entityType: EntityType::CHAT,
				entityId: 'chat'.$chatId
			);
			if ($call)
			{
				foreach ($userIds as $userId)
				{
					$call->removeUser($userId);
					$call->getSignaling()->sendHangup($userId, $call->getUsers(), null);
				}
				if (Settings::isNewCallsEnabled())
				{
					$chat->getCallToken()->update();
					$call->getSignaling()->sendPushTokenUpdate($chat->getCallToken()->getToken(), $call->getUsers());
				}

				$ids = array_unique(array_merge($userIds, array_keys($call->getCallUsers())));
				foreach ($ids as $userId)
				{
					\Bitrix\Call\Call::updateUserActiveCallsCache($userId);
				}
			}
		}

		return $result;
	}

	/**
	 * Handles call finished event: update active calls cache for all participants
	 *
	 * @event 'call:onCallFinished'
	 * @param Event $event
	 * @return void
	 */
	public static function onCallFinished(Event $event): void
	{
		$call = $event->getParameter('call');
		if (!$call instanceof \Bitrix\Im\Call\Call)
		{
			return;
		}

		\Bitrix\Call\Call::updateCallCache($call->getId());
		\Bitrix\Call\Call::terminateAllCallsInChat($call->getChatId(), $call->getId());
	}
}
