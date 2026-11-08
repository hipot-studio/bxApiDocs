<?php

namespace Bitrix\BIConnector\Internal\Services\Scope;

use Bitrix\HumanResources\Compatibility\Utils\DepartmentBackwardAccessCode;
use Bitrix\HumanResources\Enum\NodeActiveFilter;
use Bitrix\HumanResources\Public\Service\Container as HumanResourcesContainer;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\UserTable;

/**
 * Names of company structure filter values (user_5, all_department_3, only_users_department_3) by the values,
 * the same ones the link restriction is built from, without the access check.
 */
class CompanyStructureNameProvider
{
	private const USER_PATTERN = '/^user_(\d+)$/';
	private const DEPARTMENT_PATTERN = '/^(?:all_|only_users_)?department_(\d+)$/';

	/**
	 * @param string[] $values
	 *
	 * @return array<string, string> value => name, not escaped; unknown values are absent.
	 */
	public function getNames(array $values): array
	{
		$userIds = [];
		$departmentIds = [];
		foreach (array_unique(array_filter($values, 'is_string')) as $value)
		{
			if (preg_match(self::USER_PATTERN, $value, $matches))
			{
				$userIds[$value] = (int)$matches[1];
			}
			elseif (preg_match(self::DEPARTMENT_PATTERN, $value, $matches))
			{
				$departmentIds[$value] = (int)$matches[1];
			}
		}

		$userNames = $this->getUserNames(array_filter($userIds));
		$departmentNames = $this->getDepartmentNames(array_filter($departmentIds));

		$names = [];
		foreach ($userIds as $value => $id)
		{
			if (isset($userNames[$id]))
			{
				$names[$value] = $userNames[$id];
			}
		}
		foreach ($departmentIds as $value => $id)
		{
			if (isset($departmentNames[$id]))
			{
				$names[$value] = $departmentNames[$id];
			}
		}

		return $names;
	}

	/**
	 * @param int[] $ids
	 * @return array<int, string>
	 */
	private function getUserNames(array $ids): array
	{
		if (empty($ids))
		{
			return [];
		}

		$users = UserTable::getList([
			'select' => ['ID', 'LOGIN', 'NAME', 'LAST_NAME', 'SECOND_NAME', 'EMAIL'],
			'filter' => ['@ID' => array_values(array_unique($ids))],
		]);

		$nameFormat = \CSite::GetNameFormat(false);
		$names = [];
		while ($user = $users->fetch())
		{
			$name = trim(\CUser::FormatName($nameFormat, $user, true, false));
			if ($name !== '')
			{
				$names[(int)$user['ID']] = $name;
			}
		}

		return $names;
	}

	/**
	 * @param int[] $ids Department ids of the old structure, the ones the filter values carry.
	 * @return array<int, string>
	 */
	private function getDepartmentNames(array $ids): array
	{
		if (empty($ids))
		{
			return [];
		}

		$ids = array_values(array_unique($ids));

		if (Loader::includeModule('humanresources'))
		{
			return $this->getNodeNames($ids);
		}

		return $this->getSectionNames($ids);
	}

	/**
	 * @param int[] $ids
	 * @return array<int, string>
	 */
	private function getNodeNames(array $ids): array
	{
		$nodes = HumanResourcesContainer::getNodeService()->findAllByAccessCodes(
			accessCodes: array_map(static fn(int $id) => DepartmentBackwardAccessCode::makeById($id), $ids),
			activeFilter: NodeActiveFilter::ALL,
		);

		$names = [];
		foreach ($nodes as $node)
		{
			$id = DepartmentBackwardAccessCode::extractIdFromCode($node->accessCode);
			$name = trim((string)$node->name);
			if ($id !== null && $name !== '')
			{
				$names[$id] = $name;
			}
		}

		return $names;
	}

	/**
	 * @param int[] $ids
	 * @return array<int, string>
	 */
	private function getSectionNames(array $ids): array
	{
		$iblockId = (int)Option::get('intranet', 'iblock_structure', 0);
		if ($iblockId <= 0 || !Loader::includeModule('iblock'))
		{
			return [];
		}

		$sections = \CIBlockSection::GetList(
			[],
			['IBLOCK_ID' => $iblockId, 'ID' => $ids],
			false,
			['ID', 'NAME'],
		);

		$names = [];
		while ($section = $sections->Fetch())
		{
			$name = trim((string)$section['NAME']);
			if ($name !== '')
			{
				$names[(int)$section['ID']] = $name;
			}
		}

		return $names;
	}
}
