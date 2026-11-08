<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Public\Command\User;

use Bitrix\Intranet\Entity\Collection\DepartmentCollection;
use Bitrix\Intranet\Entity\Department;
use Bitrix\Intranet\Entity\User;
use Bitrix\Intranet\Exception\UpdateFailedException;
use Bitrix\Intranet\Internal\Integration\Humanresources\DepartmentRepository;
use Bitrix\Intranet\Internal\Integration\Humanresources\UserDepartmentProvider;
use Bitrix\Intranet\Service\ServiceContainer;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

class TransferToIntranetCommand extends AbstractCommand
{
	public function __construct(
		public readonly User $user,
		public readonly DepartmentCollection $departments,
	)
	{
	}

	protected function execute(): Result
	{
		try
		{
			if (!$this->hasValidPromotionEntities() || $this->hasExistingDepartmentMembership())
			{
				return $this->createAssignmentErrorResult();
			}

			$handler = $this->createHandler();
			if ($handler($this))
			{
				return new Result();
			}
		}
		catch (UpdateFailedException $error)
		{
			return (new Result())->addErrors($error->getErrors()->getValues());
		}
		catch (\Throwable)
		{
			return $this->createAssignmentErrorResult();
		}

		return $this->createAssignmentErrorResult();
	}

	protected function hasValidPromotionEntities(): bool
	{
		if ($this->user->getId() === null || $this->user->getId() <= 0 || $this->departments->count() === 0)
		{
			return false;
		}

		$nodeIds = [];
		foreach ($this->departments as $department)
		{
			if (!$department instanceof Department || $department->isIblockSource())
			{
				return false;
			}

			$nodeId = $department->getId();
			if ($nodeId === null || $nodeId <= 0 || in_array($nodeId, $nodeIds, true))
			{
				return false;
			}

			$nodeIds[] = $nodeId;
		}

		return $this->areValidPromotionDepartments($nodeIds);
	}

	protected function areValidPromotionDepartments(array $nodeIds): bool
	{
		return (new DepartmentRepository())->areValidDepartmentIds($nodeIds);
	}

	protected function hasExistingDepartmentMembership(): bool
	{
		return (new UserDepartmentProvider())->hasDepartmentMembership($this->user->getId());
	}

	private function createAssignmentErrorResult(): Result
	{
		return (new Result())->addError(new Error(
			'Failed to assign selected department',
			'DEPARTMENT_ASSIGN_ERROR',
		));
	}

	protected function createHandler(): TransferToIntranetCommandHandler
	{
		return new TransferToIntranetCommandHandler(ServiceContainer::getInstance()->userRepository());
	}
}
