<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Integration\HumanResources;

use Bitrix\HumanResources\Type\MemberEntityType;
use Bitrix\Main\Event;
use Bitrix\Main\Loader;
use Bitrix\Mobile\Tab\Preset\Service\MobileMenuCacheInvalidator;

final class EventHandler
{
	public static function onMemberChanged(Event $event): void
	{
		if (!Loader::includeModule('humanresources'))
		{
			return;
		}

		$member = $event->getParameter('member');
		if (
			!is_object($member)
			|| ($member->entityType ?? null) !== MemberEntityType::USER
			|| (int)($member->entityId ?? 0) <= 0
		)
		{
			return;
		}

		(new MobileMenuCacheInvalidator())->clearUser((int)$member->entityId);
	}
}
