<?php

namespace Bitrix\IntranetMobile\Provider;

use Bitrix\Intranet\User\UserManager;
use Bitrix\Intranet\UserTable;
use Bitrix\IntranetMobile\Dto\SortingDto;
use Bitrix\IntranetMobile\Dto\UserDto;
use Bitrix\Main\UI\PageNavigation;
use Bitrix\IntranetMobile\Dto\FilterDto;

final class UserProvider
{
	private ?UserManager $userManager = null;

	public const ALL_DEPARTMENTS = 0;

	public function __construct()
	{
		if (\Bitrix\Main\Loader::includeModule('tasks'))
		{
			$this->userManager = new UserManager('IntranetMobile/UserProvider/getByPage', []);
		}
	}

	public function getByPage(
		FilterDto $filter,
		SortingDto $sorting,
		?PageNavigation $nav = null,
	)
	{
		$users = $this->getUsers($this->getSelect(), $filter, $sorting, $nav);

		return $this->convertUsers($users);
	}

	private function convertUsers(array $users)
	{
		$usersMainInfo = [];
		$usersIntranetInfo = [];
		foreach ($users as $user)
		{
			$usersMainInfo[] = \Bitrix\Mobile\Provider\UserRepository::createUserDTO($user['data']);
			$usersIntranetInfo[] = \Bitrix\IntranetMobile\Repository\UserRepository::createUserDto([...$user['data'], 'ACTIONS' => $user['actions']]);
		}

		return [
			'items' => $usersMainInfo,
			'users' => $usersIntranetInfo,
		];
	}

	public function getUserListTabs(): array
	{
		$tabs = (new UserProvider())->getPresets();

		$intranetUser = new \Bitrix\Intranet\User();
		$result = [];

		foreach ($tabs as $tab)
		{
			if ($tab['id'] === 'invited')
			{
				$tab['value'] = $intranetUser->getInvitationCounterValue();
			}
			if ($tab['id'] === 'wait_confirmation')
			{
				$tab['value'] = $intranetUser->getWaitConfirmationCounterValue();
			}
			$result[] = $tab;
		}

		return $result;
	}

	private function getPresets(): array
	{
		$presets = $this->userManager?->getDefaultFilterPresets();
		$result = [];

		foreach ($presets as $preset)
		{
			$result[] = ['id' => $preset->getId(), ...$preset->toArray()];
		}

		return $result;
	}

	private function getDefaultPreset()
	{
		foreach ($this->getPresets() as $preset)
		{
			if ($preset['default'] === true)
			{
				return $preset;
			}
		}
	}

	private function isDefaultFilter(FilterDto $filter): bool
	{
		return (
			$filter->searchString === ''
			&& $filter->presetId === $this->getDefaultPreset()['id']
			&& $filter->department === FilterDto::ALL_DEPARTMENTS
		);
	}

	public function isEmptyFilter(FilterDto $filter): bool
	{
		return (
			$filter->searchString === ''
			&& $filter->presetId === null
			&& $filter->department === FilterDto::ALL_DEPARTMENTS
		);
	}

	public function isDefaultOrEmptyFilter($filter): bool
	{
		return $this->isDefaultFilter($filter) || $this->isEmptyFilter($filter);
	}

	private function getUsers(array $select, ?FilterDto $filter = null, ?SortingDto $sorting = null, ?PageNavigation $nav = null): array
	{
		$nav ??= new PageNavigation('page');
		$filter ??= new FilterDto();
		$sorting ??= new SortingDto();

		$params = [
			'select' => $select,
			'limit' => $nav->getLimit(),
			'offset' => $nav->getOffset(),
			'filter' => ['DEPARTMENT' => $filter->department],
		];

		$sort = $sorting->getType();
		if (is_array($sort))
		{
			$params['order'] = [
				...UserManager::SORT_WAITING_CONFIRMATION,
				...UserManager::SORT_INVITED,
				...UserManager::SORT_STRUCTURE,
				...UserManager::SORT_INVITATION,
				];
		}

		return $this->userManager
			? $this->userManager->getList(
				$params,
				$filter->presetId,
				$filter->searchString,
			) : [];
	}

	public function getUsersByIds(array $ids): array
	{
		$params = [
			'select' => $this->getSelect(),
			'filter' => ['=ID' => $ids],
		];

		return $this->userManager ? $this->convertUsers($this->userManager->getList($params)) : [];
	}

	public function hasMoreThanOneUser(): bool
	{
		$users = $this->userManager ? $this->userManager->getList([
			'select' => ['ID'],
			'limit' => 2,
		]) : [];

		return count($users) > 1;
	}

	public function getUsersCountByInvitationStatus(array $types = []): int
	{
		$filteredTypes = array_intersect($types, [
			UserDto::ACTIVE,
			UserDto::FIRED,
			UserDto::INVITED,
			UserDto::INVITE_AWAITING_APPROVE,
		]);

		if (count($filteredTypes) == 0)
		{
			return 0;
		}
		else if (count($filteredTypes) == 3)
		{
			return $this->getUsersCountFromTable([$filteredTypes[0]])
				+ $this->getUsersCountFromTable([$filteredTypes[1], $filteredTypes[2]]);
		}

		return $this->getUsersCountFromTable($filteredTypes);
	}

	private function getUsersCountFromTable(array $types = []): int
	{
		$filter = [
			'=IS_REAL_USER' => 'Y',
		];

		$hasActiveTypes = in_array(UserDto::ACTIVE, $types) || in_array(UserDto::INVITED, $types);
		$hasInactiveTypes = in_array(UserDto::FIRED, $types) || in_array(UserDto::INVITE_AWAITING_APPROVE, $types);
		$hasTypesWithCode = in_array(UserDto::INVITED, $types) || in_array(UserDto::INVITE_AWAITING_APPROVE, $types);
		$hasTypesWithNoCode = in_array(UserDto::ACTIVE, $types) || in_array(UserDto::FIRED, $types);

		if ($hasActiveTypes && !$hasInactiveTypes)
		{
			$filter['=ACTIVE'] = 'Y';
		}
		else if (!$hasActiveTypes && $hasInactiveTypes)
		{
			$filter['=ACTIVE'] = 'N';
		}

		if ($hasTypesWithCode && !$hasTypesWithNoCode)
		{
			$filter['!CONFIRM_CODE'] = false;
		}
		else if (!$hasTypesWithCode && $hasTypesWithNoCode)
		{
			$filter['=CONFIRM_CODE'] = false;
		}

		return UserTable::getCount($filter);
	}

	private function getSelect(): array
	{
		return [
			'ID',
			'NAME',
			'LAST_NAME',
			'SECOND_NAME',
			'LOGIN',
			'PERSONAL_PHOTO',
			'WORK_POSITION',
			'UF_DEPARTMENT',
			'EMAIL',
			'WORK_PHONE',
			'ACTIVE',
			'CONFIRM_CODE',
			'DATE_REGISTER',
			'PERSONAL_MOBILE',
			'PERSONAL_PHONE',
		];
	}
}
