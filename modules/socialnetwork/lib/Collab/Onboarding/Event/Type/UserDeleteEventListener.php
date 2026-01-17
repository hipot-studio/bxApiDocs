<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Collab\Onboarding\Event\Type;

use Bitrix\Main\EventResult;

class UserDeleteEventListener extends AbstractEventListener
{
	public function onAfterUserDelete(int $userId): EventResult
	{
		$this->queueService->deleteByUserIds($userId);

		return new EventResult(EventResult::SUCCESS);
	}
}
