<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Infrastructure\Integration\Im\EventHandler;

use Bitrix\Im\V2\Chat\ExternalChat\Event\BeforeCreateEvent;
use Bitrix\Main\EventResult;
use Bitrix\Vibecodeconnector\Internal\Integration\Im\AppChatType;
use Bitrix\Vibecodeconnector\Public\Provider\AppChatProvider;

final class GuardCreate
{
	public static function execute(BeforeCreateEvent $event, ?AppChatProvider $provider = null): ?EventResult
	{
		$fields = $event->getFields();
		if ((string)($fields['ENTITY_TYPE'] ?? '') !== AppChatType::ENTITY_TYPE)
		{
			return null;
		}

		return ($provider ?? new AppChatProvider())->isCreationAllowed(AppChatType::ENTITY_TYPE)
			? null
			: new EventResult(EventResult::ERROR);
	}
}
