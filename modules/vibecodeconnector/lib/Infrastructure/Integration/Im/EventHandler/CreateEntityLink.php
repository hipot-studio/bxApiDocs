<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Infrastructure\Integration\Im\EventHandler;

use Bitrix\Im\V2\Chat\EntityLink\EntityLinkDto;
use Bitrix\Im\V2\Chat\ExternalChat\Event\CreateEntityLinkEvent;
use Bitrix\Main\EventResult;
use Bitrix\Vibecodeconnector\Public\Provider\AppChatProvider;

final class CreateEntityLink
{
	public static function execute(CreateEntityLinkEvent $event, ?AppChatProvider $provider = null): ?EventResult
	{
		$chat = $event->getChat();
		$link = ($provider ?? new AppChatProvider())->createEntityLink(
			(string)$chat->getEntityType(),
			(int)$chat->getEntityId(),
			(int)$chat->getChatId(),
		);
		if ($link === null)
		{
			return null;
		}

		return new EventResult(
			EventResult::SUCCESS,
			['entityLinkDto' => new EntityLinkDto(
				type: $link['type'],
				chatId: $link['chatId'],
				entityId: $link['entityId'],
				url: $link['url'],
			)],
		);
	}
}
