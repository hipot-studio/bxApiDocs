<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Infrastructure\Integration\Im\EventHandler;

use Bitrix\Im\V2\Chat\ExternalChat\Event\FilterUsersByAccessEvent;
use Bitrix\Main\EventResult;
use Bitrix\Vibecodeconnector\Public\Provider\AppChatProvider;

final class FilterUsersByAccess
{
	public static function execute(
		FilterUsersByAccessEvent $event,
		?AppChatProvider $provider = null,
	): ?EventResult
	{
		$result = ($provider ?? new AppChatProvider())->filterUserIds(
			(int)$event->getChat()->getEntityId(),
			(int)$event->getChat()->getChatId(),
			$event->getUserIds(),
		);

		return new EventResult(EventResult::SUCCESS, [
			'userIds' => $result['userIds'],
			'userIdsToKeepRelations' => $event->isBatchRelationFilterContext()
				? $result['undecidedUserIds']
				: [],
		]);
	}
}
