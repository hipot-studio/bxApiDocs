<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Infrastructure\Integration\Im\EventHandler;

use Bitrix\Im\V2\Chat\ExternalChat\Event\ResolveManageCapabilityEvent;
use Bitrix\Main\EventResult;
use Bitrix\Vibecodeconnector\Public\Provider\AppChatProvider;

final class ResolveManageCapability
{
	public static function execute(ResolveManageCapabilityEvent $event, ?AppChatProvider $provider = null): ?EventResult
	{
		$chatIds = array_values(array_unique(array_map('intval', $event->getChatIds())));
		if ($chatIds === [])
		{
			return null;
		}

		$capabilities = ($provider ?? new AppChatProvider())->resolveManageCapabilities(
			$chatIds,
			$event->getUserId(),
		);

		return new EventResult(EventResult::SUCCESS, ['capabilities' => $capabilities]);
	}
}
