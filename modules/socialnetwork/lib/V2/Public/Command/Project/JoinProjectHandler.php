<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Command\Project;

use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Socialnetwork\Helper\Workgroup;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;
use Throwable;

class JoinProjectHandler
{
	public function __invoke(JoinProjectCommand $command): Result
	{
		$result = new Result();

		try
		{
			$confirmationNeeded = Workgroup::join([
				'groupId' => $command->projectId,
				'userId' => $command->userId,
			]);

			if ($confirmationNeeded)
			{
				$this->notifyModerators($command->projectId);
			}
		}
		catch (Throwable $e)
		{
			$result->addError(new Error($e->getMessage()));
		}

		return $result;
	}

	private function notifyModerators(int $projectId): void
	{
		$moderatorIds = Container::getInstance()
			->getProjectMemberRepository()
			->getModeratorUserIds($projectId);

		Container::getInstance()
			->getProjectRealtimePublisher()
			->publishMemberRequestConfirm($projectId, $moderatorIds)
		;
	}
}
