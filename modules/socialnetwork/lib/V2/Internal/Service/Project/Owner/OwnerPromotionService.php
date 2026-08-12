<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service\Project\Owner;

use Bitrix\Main\Error;
use Bitrix\Main\Result;

class OwnerPromotionService
{
	public function promote(
		int $newOwnerId,
		int $projectId,
		array $project,
		OwnerRecoveryMode $mode,
	): Result
	{
		$result = new Result();

		$skipUserNotifications = ($mode === OwnerRecoveryMode::Silent);
		if ($this->setOwner(
			$newOwnerId,
			$projectId,
			$project,
			$skipUserNotifications,
			$skipUserNotifications,
		))
		{
			return $result;
		}

		return $result->addError(new Error($this->getOwnerChangeErrorMessage($projectId)));
	}

	protected function setOwner(
		int $newOwnerId,
		int $projectId,
		array $project,
		bool $skipChatMessage,
		bool $skipNotifications,
	): bool
	{
		return \CSocNetUserToGroup::SetOwner(
			$newOwnerId,
			$projectId,
			$project,
			$skipChatMessage,
			$skipNotifications,
		);
	}

	protected function getOwnerChangeErrorMessage(int $projectId): string
	{
		return "Failed to change owner in project {$projectId}";
	}
}
