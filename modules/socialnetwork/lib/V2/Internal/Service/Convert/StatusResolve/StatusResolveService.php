<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service\Convert\StatusResolve;

use Bitrix\Socialnetwork\Item\Workgroup\Type;
use Bitrix\Socialnetwork\V2\Internal\Entity\Convert\ConvertProgress;
use Bitrix\Socialnetwork\V2\Internal\Entity\Convert\ConvertStatus;

class StatusResolveService
{
	private const TRANSITIONS = [
		'startFromGroup' => [
			null => ConvertStatus::InProgressFromGroup,
			ConvertStatus::StoppedByErrorFromGroup->value => ConvertStatus::InProgressFromGroup,
		],
		'startFromCollab' => [
			null => ConvertStatus::InProgressFromCollab,
			ConvertStatus::StoppedByErrorFromCollab->value => ConvertStatus::InProgressFromCollab,
		],
		'complete' => [
			ConvertStatus::InProgressFromGroup->value => ConvertStatus::CompletedFromGroup,
			ConvertStatus::InProgressFromCollab->value => ConvertStatus::CompletedFromCollab,
		],
		'fail' => [
			ConvertStatus::InProgressFromGroup->value => ConvertStatus::StoppedByErrorFromGroup,
			ConvertStatus::InProgressFromCollab->value => ConvertStatus::StoppedByErrorFromCollab,
		],
	];

	public function start(ConvertProgress $progress, Type $groupType): void
	{
		if ($this->isForbiddenForStart($progress))
		{
			return;
		}

		$status = $progress->getStatus();

		if ($status === null)
		{
			$groupType === Type::Collab
				? $this->applyTransition($progress, 'startFromCollab')
				: $this->applyTransition($progress, 'startFromGroup')
			;

			return;
		}

		if ($status === ConvertStatus::StoppedByErrorFromCollab)
		{
			$this->applyTransition($progress, 'startFromCollab');
		}
		else if ($status === ConvertStatus::StoppedByErrorFromGroup)
		{
			$this->applyTransition($progress, 'startFromGroup');
		}
	}

	public function complete(ConvertProgress $progress): void
	{
		$this->applyTransition($progress, 'complete');
	}

	public function fail(ConvertProgress $progress): void
	{
		$this->applyTransition($progress, 'fail');
	}

	public function isCompleted(ConvertProgress $progress): bool
	{
		return in_array(
			$progress->getStatus(),
			self::TRANSITIONS['complete'],
			true,
		);
	}

	public function isInProgress(ConvertProgress $progress): bool
	{
		return in_array(
			$progress->getStatus(),
			[
				ConvertStatus::InProgressFromGroup,
				ConvertStatus::InProgressFromCollab,
			],
			true,
		);
	}

	public function isNotRequired(ConvertProgress $progress): bool
	{
		return $progress->getStatus() === ConvertStatus::NotRequired;
	}

	public function isForbiddenForStart(ConvertProgress $progress): bool
	{
		return $this->isCompleted($progress) || $this->isNotRequired($progress);
	}

	private function applyTransition(ConvertProgress $progress, string $transitionName): void
	{
		$currentStatusValue = $progress->getStatus()?->value;
		$nextStatus = self::TRANSITIONS[$transitionName][$currentStatusValue] ?? null;

		if ($nextStatus !== null)
		{
			$progress->setStatus($nextStatus);
		}
	}
}
