<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Collab\Onboarding\Event;

use Bitrix\Main\EventResult;
use Bitrix\Socialnetwork\Collab\Onboarding\Event\Type\UserDeleteEventListener;
use Bitrix\Socialnetwork\Collab\Onboarding\Event\Type\UserUpdateEventListener;

class EventDispatcher
{
	public static function onAfterUserFired(array $data): EventResult
	{
		$eventResult = new EventResult(EventResult::SUCCESS);

		$isFired = ($data['ACTIVE'] ?? '') === 'N';
		if (!$isFired)
		{
			return $eventResult;
		}

		$userId = (int)($data['ID'] ?? 0);
		if ($userId <= 0)
		{
			return $eventResult;
		}

		return UserUpdateEventListener::getInstance()->onAfterUserFired($userId);
	}

	public static function onAfterUserDelete(int $userId): EventResult
	{
		if ($userId <= 0)
		{
			return new EventResult(EventResult::SUCCESS);
		}

		return UserDeleteEventListener::getInstance()->onAfterUserDelete($userId);
	}
}
