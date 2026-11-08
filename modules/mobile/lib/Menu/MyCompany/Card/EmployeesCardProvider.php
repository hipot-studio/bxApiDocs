<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Menu\MyCompany\Card;

use Bitrix\Main\Loader;
use Bitrix\Mobile\Provider\CommonUserDto;
use Bitrix\Mobile\Provider\UserRepository;

/**
 * Data of the employees card: the preview of the company staff and the total headcount behind it.
 * The card shows colleagues of the user first and fills the rest of the preview with anyone else
 * of the portal, so a fresh department never leaves the card half empty.
 */
final class EmployeesCardProvider
{
	private const PREVIEW_SIZE = 4;

	/** Portal-wide fetch takes a surplus, so deduplication against the department still leaves a full preview. */
	private const PREVIEW_FETCH_LIMIT = self::PREVIEW_SIZE * 2;

	public function __construct(private readonly CommonUserDto $user)
	{
	}

	/**
	 * The invite signal is not here on purpose: it is a permission of the section rather than a fact
	 * of the staff, and MyCompanyProvider is the one place it is answered from.
	 *
	 * @return array{users: CommonUserDto[], totalUsersCount: int}|null
	 */
	public function getCompany(): ?array
	{
		if ($this->user->isExtranet || $this->user->isCollaber || $this->user->isGuest)
		{
			return null;
		}

		if (
			!Loader::includeModule('intranet')
			|| !Loader::includeModule('intranetmobile')
			|| !Loader::includeModule('humanresources')
		)
		{
			return null;
		}

		$departmentProvider = new \Bitrix\IntranetMobile\Provider\DepartmentProvider();

		$departmentUserIds = $departmentProvider->getEmployeesFromUserDepartments($this->user->id, self::PREVIEW_SIZE);

		if (count($departmentUserIds) < self::PREVIEW_SIZE)
		{
			$allUserIds = $departmentProvider->getAllEmployees(0, self::PREVIEW_FETCH_LIMIT);

			$userIds = array_unique(array_merge($departmentUserIds, $allUserIds));
			$userIds = array_slice($userIds, 0, self::PREVIEW_SIZE);
		}
		else
		{
			$userIds = $departmentUserIds;
		}

		$users = UserRepository::getByIds($userIds);

		$totalUsersCount = $departmentProvider->getTotalEmployeeCount();

		return [
			'users' => $users,
			'totalUsersCount' => $totalUsersCount,
		];
	}

	/**
	 * Whether the calls of the card may be placed by the current user: the parameter of the call
	 * intent, needed on the tap alone.
	 */
	public function canUseTelephony(): bool
	{
		return Loader::includeModule('voximplant')
			&& \Bitrix\Voximplant\Security\Helper::canCurrentUserPerformCalls()
		;
	}
}
