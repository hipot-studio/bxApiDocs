<?php

namespace Bitrix\Intranet\Integration\HumanResources;

use Bitrix\HumanResources\Config\Storage;
use Bitrix\HumanResources\Public\Service\Container as PublicContainer;
use Bitrix\HumanResources\Public\Service\Department\UserService as DepartmentUserService;
use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Service\UserService;
use Bitrix\Intranet\Entity\Collection\UserCollection;
use Bitrix\Intranet\Entity\User;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Loader;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\SystemException;
use Bitrix\Main\UserTable;

class HrUserService
{
	private ?UserService $hrUserService = null;
	private ?DepartmentUserService $departmentUserService = null;
	private Storage $storage;

	public function __construct(
		?UserService $hrUserService = null,
		?Storage $storage = null,
		?DepartmentUserService $departmentUserService = null,
	)
	{
		if ($storage !== null)
		{
			$this->hrUserService = $hrUserService;
			$this->departmentUserService = $departmentUserService;
			$this->storage = $storage;

			return;
		}

		if (!Loader::includeModule('humanresources'))
		{
			throw new SystemException('Module "humanresources" is not installed.');
		}

		$this->hrUserService = $hrUserService ?? Container::getUserService();
		$this->departmentUserService = $departmentUserService ?? PublicContainer::getUserDepartmentService();
		$this->storage = Storage::instance();
	}

	/**
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @throws ArgumentException
	 */
	public function isEmployee(User $user): bool
	{
		return $this->getHrUserService()->isEmployee($user->getId());
	}

	public function filterEmployees(UserCollection $userCollection): UserCollection
	{
		return $userCollection->filter(fn (User $user) => $this->isEmployee($user));
	}

	public function filterEmployeesByUserIds(array $userIds): array
	{
		$userIds = array_values(array_filter(
			array_map('intval', $userIds),
			static fn(int $userId): bool => $userId > 0,
		));

		if (empty($userIds))
		{
			return [];
		}

		$uniqueUserIds = array_values(array_unique($userIds));
		if ($this->storage->isCompanyStructureConverted())
		{
			$employeeIds = $this->getDepartmentUserService()->filterEmployeeIds($uniqueUserIds);
		}
		else
		{
			$employeeIds = array_column(
				$this->createLegacyEmployeeQuery()
					->setSelect(['ID'])
					->whereIn('ID', $uniqueUserIds)
					->where('UF_DEPARTMENT', '!=', false)
					->fetchAll(),
				'ID',
			);
		}

		$employeeIdMap = array_fill_keys(array_map('intval', $employeeIds), true);

		return array_values(array_filter(
			$userIds,
			static fn(int $userId): bool => isset($employeeIdMap[$userId]),
		));
	}

	public function filterNotEmployees(UserCollection $userCollection): UserCollection
	{
		return $userCollection->filter(fn (User $user) => !$this->isEmployee($user));
	}

	private function getHrUserService(): UserService
	{
		return $this->hrUserService ?? Container::getUserService();
	}

	private function getDepartmentUserService(): DepartmentUserService
	{
		return $this->departmentUserService ?? PublicContainer::getUserDepartmentService();
	}

	protected function createLegacyEmployeeQuery(): Query
	{
		return UserTable::query();
	}
}
