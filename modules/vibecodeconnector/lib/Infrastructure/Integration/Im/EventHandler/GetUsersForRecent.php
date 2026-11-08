<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Infrastructure\Integration\Im\EventHandler;

use Bitrix\Im\Recent;
use Bitrix\Im\V2\Chat\ExternalChat\Event\GetUsersForRecentEvent;
use Bitrix\Main\EventResult;
use Bitrix\Vibecodeconnector\Public\Provider\AppChatProvider;

final class GetUsersForRecent
{
	public static function execute(GetUsersForRecentEvent $event, ?AppChatProvider $provider = null): ?EventResult
	{
		$provider ??= new AppChatProvider();
		$chat = $event->getChat();
		$catalogItemId = (int)$chat->getEntityId();
		$chatId = (int)$chat->getChatId();
		if ($provider->getOwnerId($catalogItemId, $chatId) === null)
		{
			return null;
		}

		$membersOutOfRecent = Recent::getUsersOutOfRecent($chat);
		$userIds = $provider->filterUserIds($catalogItemId, $chatId, $membersOutOfRecent)['userIds'];

		return new EventResult(EventResult::SUCCESS, ['userIds' => array_combine($userIds, $userIds)]);
	}
}
