<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Collab\Onboarding\Event\Type;

use Bitrix\Main\EventResult;

class UserUpdateEventListener extends AbstractEventListener
{
	public function onAfterUserFired(int $userId): EventResult
	{
		$this->queueService->deleteByUserIds($userId);

		return new EventResult(EventResult::SUCCESS);
	}
}
