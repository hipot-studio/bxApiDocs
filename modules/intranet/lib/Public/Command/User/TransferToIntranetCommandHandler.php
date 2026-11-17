<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Public\Command\User;

use Bitrix\Intranet\Entity;
use Bitrix\Intranet\Internal\Result\User\TransferToIntranetUserResult;
use Bitrix\Intranet\Service\ServiceContainer;
use Bitrix\Intranet\Service\UserService;
use Bitrix\Intranet\Integration\HumanResources\HrUserService;
use Bitrix\Intranet\Internal\Integration\Extranet\ExtranetService;
use Bitrix\Intranet\Internal\Integration\Humanresources\UserDepartmentAssigner;
use Bitrix\Intranet\Internal\Integration\Humanresources\UserDepartmentProvider;
use Bitrix\Intranet\Exception\UpdateFailedException;
use Bitrix\Main\Error;
use Bitrix\Main\ErrorCollection;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Main\UserTable;

class TransferToIntranetCommandHandler
{

	public function transferOne(int $userId, array $departmentIds, string $siteId): TransferToIntranetUserResult
	{
		$status = 'error';
		$errors = [];
		try
		{
			$user = $userId > 0 ? ServiceContainer::getInstance()->userRepository()->getUserById($userId) : null;
			if (!$user)
			{
				$errors = $this->error('USER_NOT_FOUND')->getErrors();
			}
			elseif ((new HrUserService())->isEmployee($user))
			{
				$status = 'already_employee';
			}
			elseif (!$this->isEligible($userId, $user))
			{
				$errors = $this->error('UNSUPPORTED_USER_TYPE')->getErrors();
			}
			else
			{
				global $APPLICATION;
				$APPLICATION->ResetException();
				if ($this->transferNative($userId, $departmentIds, $siteId) === $userId)
				{
					$status = 'transferred';
				}
				else
				{
					$errors = $this->error('TRANSFER_FAILED')->getErrors();
				}
			}
		}
		catch (\Throwable)
		{
			$errors = $this->error('TRANSFER_FAILED')->getErrors();
		}
		return (new TransferToIntranetUserResult($userId, $status))->addErrors($errors);
	}

	protected function transferNative(int $userId, array $departmentIds, string $siteId): int|false
	{
		$originalGroups = $this->getGroups($userId);
		$groups = [];
		$extranetGroup = $this->getExtranetUserGroupId();
		$employeeGroups = $this->getEmployeeGroupIds($siteId);
		if (!$employeeGroups || $extranetGroup === null)
		{
			return false;
		}
		foreach ($originalGroups as $group)
		{
			$id = (int)$group['GROUP_ID'];
			if ($id === 2 || $id === $extranetGroup)
			{
				continue;
			}
			$groups[$id] = [
				'GROUP_ID' => $id,
				'DATE_ACTIVE_FROM' => $group['DATE_ACTIVE_FROM'],
				'DATE_ACTIVE_TO' => $group['DATE_ACTIVE_TO'],
			];
		}
		foreach ($employeeGroups as $id)
		{
			$id = (int)$id;
			if ($id > 0 && $id !== 2 && $id !== $extranetGroup)
			{
				$groups[$id] = ['GROUP_ID' => $id, 'DATE_ACTIVE_FROM' => null, 'DATE_ACTIVE_TO' => null];
			}
		}
		$preexistingDepartmentIds = $this->getExistingDepartmentIds($userId);
		if (!$this->updateGroups($userId, array_values($groups)))
		{
			return false;
		}
		try
		{
			$this->assignDepartments($userId, $departmentIds);
			if (!$this->verifyDepartments($userId, $departmentIds))
			{
				throw new \RuntimeException('Assigned departments do not match selection');
			}
		}
		catch (\Throwable)
		{
			try
			{
				$this->removeAssignedDepartments(
					$userId, array_values(array_diff($departmentIds, $preexistingDepartmentIds)),
				);
				$this->restoreGroups($userId, $originalGroups);
			}
			catch (\Throwable)
			{
				throw new UpdateFailedException(new ErrorCollection([
					new Error('Failed to roll back guest transfer', 'DEPARTMENT_ROLLBACK_ERROR'),
				]));
			}

			return false;
		}
		return $userId;
	}

	protected function getExtranetUserGroupId(): ?int
	{
		return (new ExtranetService())->getExtranetUserGroupId();
	}

	protected function getEmployeeGroupIds(string $siteId): array
	{
		return (new UserService())->getEmployeeGroupIds($siteId);
	}

	protected function getGroups(int $userId): array
	{
		return ServiceContainer::getInstance()->userRepository()->getGroupMemberships($userId);
	}

	protected function updateGroups(int $userId, array $groups): bool
	{
		return ServiceContainer::getInstance()->userRepository()->updateGroupMemberships($userId, $groups);
	}

	protected function getExistingDepartmentIds(int $userId): array
	{
		return (new UserDepartmentProvider())->getExistingDepartmentIds($userId);
	}

	protected function assignDepartments(int $userId, array $departmentIds): void
	{
		(new UserDepartmentAssigner())->assignToDepartments($userId, $departmentIds);
	}

	protected function verifyDepartments(int $userId, array $departmentIds): bool
	{
		return (new UserDepartmentProvider())->hasSelectedEmployeeDepartments($userId, $departmentIds);
	}

	protected function removeAssignedDepartments(int $userId, array $departmentIds): void
	{
		(new UserDepartmentAssigner())->removeAssignedDepartments($userId, $departmentIds);
	}

	protected function restoreGroups(int $userId, array $groups): void
	{
		if (!$this->updateGroups($userId, $groups) || $this->getGroups($userId) !== $groups)
		{
			throw new \RuntimeException('Failed to restore guest groups');
		}
	}

	private function isEligible(int $userId, Entity\User $user): bool
	{
		if ($user->getExternalAuthId() === 'visitor'
			|| !UserTable::getList(['select' => ['ID'], 'filter' => ['=ID' => $userId, '=REAL_USER' => true]])->fetch())
		{
			return false;
		}
		$groups = [];
		$now = time();
		foreach ($this->getGroups($userId) as $membership)
		{
			$from = $membership['DATE_ACTIVE_FROM'];
			$to = $membership['DATE_ACTIVE_TO'];
			if (($from === null || \Bitrix\Main\Type\DateTime::createFromUserTime($from)->getTimestamp() <= $now)
				&& ($to === null || \Bitrix\Main\Type\DateTime::createFromUserTime($to)->getTimestamp() >= $now))
			{
				$groups[] = (int)$membership['GROUP_ID'];
			}
		}
		$integratorGroup = Loader::includeModule('bitrix24') ? (int)\CBitrix24::getIntegratorGroupId() : 0;
		$extranetGroup = $this->getExtranetUserGroupId();
		$allowedByGroups = !in_array(1, $groups, true) && !($integratorGroup > 0 && in_array($integratorGroup, $groups, true))
			&& $extranetGroup !== null && in_array($extranetGroup, $groups, true);

		return $allowedByGroups
			&& (new ExtranetService())->isTransferToIntranetAllowedByRegistryRole($userId);
	}

	private function error(string $code): Result
	{
		return (new Result())->addError(new Error('User transfer cannot be performed', $code));
	}
}
