<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service\Project\Owner;

use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Socialnetwork\V2\Internal\Repository\ProjectOwnerRepositoryInterface;

class OwnerReplacementService
{
	public function __construct(
		private readonly ProjectOwnerRepositoryInterface $projectOwnerRepository,
		private readonly OwnerPromotionService $ownerPromotionService,
	)
	{
	}

	public function replaceFiredOwnerByProjectId(int $projectId): Result
	{
		$result = new Result();

		$ownerState = $this->projectOwnerRepository->getOwnerState($projectId);
		if ($ownerState === null)
		{
			return $result->addError(new Error("Project {$projectId} was not found"));
		}

		if ($ownerState->isConsistent())
		{
			return $result;
		}

		$newOwnerId = $this->projectOwnerRepository->findReplacementOwnerId($projectId, $ownerState->ownerId);
		if ($newOwnerId === null || $newOwnerId <= 0)
		{
			return $result->addError(new Error("New owner for project {$projectId} was not resolved"));
		}

		$promotionResult = $this->promoteOwner(
			$newOwnerId,
			$projectId,
			$ownerState->projectFields,
			OwnerRecoveryMode::Interactive,
		);
		if (!$promotionResult->isSuccess())
		{
			return $result->addErrors($promotionResult->getErrors());
		}

		return $result;
	}

	public function ensureOwnerForConvert(int $projectId): OwnerRecoveryResult
	{
		$result = new OwnerRecoveryResult();

		$ownerState = $this->projectOwnerRepository->getOwnerState($projectId);
		if ($ownerState === null)
		{
			return $result->addError(new Error("Project {$projectId} was not found"));
		}

		if ($ownerState->isConsistent())
		{
			return $result->setStatus(OwnerRecoveryStatus::Unchanged);
		}

		$newOwnerId = $this->projectOwnerRepository->findReplacementOwnerId($projectId, $ownerState->ownerId);
		if ($newOwnerId === null || $newOwnerId <= 0)
		{
			return $result->setStatus(OwnerRecoveryStatus::NoCandidate);
		}

		$promotionResult = $this->promoteOwner(
			$newOwnerId,
			$projectId,
			$ownerState->projectFields,
			OwnerRecoveryMode::Silent,
		);
		if (!$promotionResult->isSuccess())
		{
			return $result->addErrors($promotionResult->getErrors());
		}

		return $result
			->setStatus(OwnerRecoveryStatus::OwnerChanged)
			->setOwnerId($newOwnerId)
		;
	}

	protected function promoteOwner(
		int $newOwnerId,
		int $projectId,
		array $project,
		OwnerRecoveryMode $mode,
	): Result
	{
		return $this->ownerPromotionService->promote($newOwnerId, $projectId, $project, $mode);
	}
}
