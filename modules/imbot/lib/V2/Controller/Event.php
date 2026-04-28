<?php

declare(strict_types=1);

namespace Bitrix\Imbot\V2\Controller;

use Bitrix\Im\V2\EventLog\EventService;
use Bitrix\Main\Error;

class Event extends BotController
{
	/**
	 * @restMethod imbot.v2.Event.get
	 */
	public function getAction(
		?\CRestServer $restServer = null,
		int $offset = 0,
		int $limit = 100,
		bool $withUserEvents = false,
	): ?array
	{
		if (!empty($this->getErrors()))
		{
			return null;
		}

		$botUserId = $this->getBotUserId();

		if ($botUserId <= 0)
		{
			$this->addError(new Error('Bot not found', 'BOT_NOT_FOUND'));

			return null;
		}

		$userIds = [$botUserId];

		if ($withUserEvents)
		{
			$currentUserId = $this->resolveSubscribedUserId($restServer);
			if ($currentUserId === null)
			{
				return null;
			}

			$userIds[] = $currentUserId;
		}

		return (new EventService())->fetchEventsForRest($userIds, $offset, $limit);
	}

	private function resolveSubscribedUserId(?\CRestServer $restServer): ?int
	{
		if ($restServer === null || !in_array('im', $restServer->getAuthScope(), true))
		{
			$this->addError(new Error(
				"Scope 'im' is required to fetch user events",
				'SCOPE_ERROR',
			));

			return null;
		}

		$userId = (int)\Bitrix\Im\Common::getUserId();
		if ($userId <= 0)
		{
			$this->addError(new Error(
				'Authorization required for user events',
				'AUTH_ERROR',
			));

			return null;
		}

		$user = \Bitrix\Im\User::getInstance($userId);
		$fields = $user->getFields();
		if (($fields['event_log'] ?? 'N') !== 'Y')
		{
			$this->addError(new Error(
				'User is not subscribed. Call im.v2.Event.subscribe first',
				'USER_NOT_SUBSCRIBED',
			));

			return null;
		}

		return $userId;
	}
}
