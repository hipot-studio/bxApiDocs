<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Public\Command\User;

use Bitrix\Intranet\Contract\Repository\UserRepository;
use Bitrix\Intranet\Entity\User;
use Bitrix\Intranet\Exception\UpdateFailedException;
use Bitrix\Intranet\Internal\Integration\Extranet\ExtranetService;
use Bitrix\Intranet\Internal\Integration\Humanresources\UserDepartmentAssigner;
use Bitrix\Intranet\Internal\Integration\Humanresources\UserDepartmentProvider;
use Bitrix\Main\Error;
use Bitrix\Main\ErrorCollection;

class TransferToIntranetCommandHandler
{
	public function __construct(private readonly UserRepository $userRepository)
	{
	}

	public function __invoke(TransferToIntranetCommand $command): bool
	{
		$user = $command->user;
		$userId = $user->getId();
		$nodeIds = array_map(static fn ($department) => $department->getId(), $command->departments->all());

		try
		{
			$groups = $this->getGroups($userId);
			$extranetGroupId = $this->getExtranetUserGroupId();
			$employeeGroupId = $this->getEmployeeGroupId();
			if ($employeeGroupId <= 0 || $extranetGroupId === $employeeGroupId)
			{
				return false;
			}
		}
		catch (\Throwable)
		{
			return false;
		}

		$assignmentAttempted = false;
		try
		{
			$retainedGroups = $extranetGroupId === null ? $groups : array_diff($groups, [$extranetGroupId]);
			$newGroups = array_values(array_unique([...$retainedGroups, $employeeGroupId]));
			if (!$this->updateGroups($user, $newGroups))
			{
				throw new \RuntimeException('Failed to update guest groups');
			}

			$assignmentAttempted = true;
			$this->assignDepartments($userId, $nodeIds);
			if (!$this->verifyDepartments($userId, $nodeIds))
			{
				throw new \RuntimeException('Assigned departments do not match selection');
			}

			return true;
		}
		catch (\Throwable)
		{
			if ($assignmentAttempted)
			{
				try
				{
					$this->removeAssignedDepartments($userId, $nodeIds);
				}
				catch (\Throwable)
				{
					throw $this->createRollbackFailure();
				}
			}

			try
			{
				$this->restoreGroups($user, $groups);
			}
			catch (\Throwable)
			{
				throw $this->createRollbackFailure();
			}

			return false;
		}
	}

	protected function getGroups(int $userId): array
	{
		$groups = \CUser::GetUserGroup($userId);
		if (!is_array($groups))
		{
			throw new \RuntimeException('Failed to read guest groups');
		}

		return $groups;
	}

	protected function getEmployeeGroupId(): int
	{
		return (int)\CGroup::GetIDByCode('EMPLOYEES_' . SITE_ID);
	}

	protected function getExtranetUserGroupId(): ?int
	{
		return (new ExtranetService())->getExtranetUserGroupId();
	}

	protected function updateGroups(User $user, array $groups): bool
	{
		$groupUpdate = new User(id: $user->getId());
		$groupUpdate->setGroupIds($groups);
		$this->userRepository->update($groupUpdate);

		return true;
	}

	protected function assignDepartments(int $userId, array $departmentIds): void
	{
		(new UserDepartmentAssigner())->reassignToDepartments($userId, $departmentIds);
	}

	protected function verifyDepartments(int $userId, array $departmentIds): bool
	{
		return (new UserDepartmentProvider())->hasExactEmployeeDepartments($userId, $departmentIds);
	}

	protected function restoreGroups(User $user, array $groups): void
	{
		if (!$this->updateGroups($user, $groups))
		{
			throw new \RuntimeException('Failed to restore guest groups');
		}

		$restoredGroups = $this->getGroups($user->getId());
		sort($groups);
		sort($restoredGroups);
		if ($groups !== $restoredGroups)
		{
			throw new \RuntimeException('Restored guest groups do not match snapshot');
		}
	}

	protected function removeAssignedDepartments(int $userId, array $departmentIds): void
	{
		(new UserDepartmentAssigner())->removeAssignedDepartments($userId, $departmentIds);
	}

	private function createRollbackFailure(): UpdateFailedException
	{
		return new UpdateFailedException(new ErrorCollection([
			new Error('Failed to roll back guest transfer', 'DEPARTMENT_ROLLBACK_ERROR'),
		]));
	}
}
