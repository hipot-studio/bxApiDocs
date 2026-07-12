<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\HumanResources\Facade;

use Bitrix\HumanResources\Compatibility\Utils\DepartmentBackwardAccessCode;
use Bitrix\HumanResources\Item\Node;
use Bitrix\HumanResources\Public\Service\Container;
use Bitrix\Main\Loader;

class DepartmentsFacade
{
	/**
	 * @param int[] $oldIds
	 * @return int[]
	 */
	public function getByOldIds(array $oldIds): array
	{
		if (empty($oldIds))
		{
			return [];
		}

		if (!Loader::includeModule('humanresources'))
		{
			return [];
		}

		$oldIds = array_values(array_unique($oldIds));

		$accessCodes = $this->convertOldIdsToAccessCodes($oldIds);

		return $this->getByAccessCodes($accessCodes);
	}

	/**
	 * @param int[] $oldIds
	 * @return string[]
	 */
	private function convertOldIdsToAccessCodes(array $oldIds): array
	{
		$result = [];

		foreach ($oldIds as $oldId)
		{
			$result[] = DepartmentBackwardAccessCode::makeById($oldId);
		}

		return $result;
	}

	/**
	 * @param string[] $accessCodes
	 * @return int[]
	 */
	private function getByAccessCodes(array $accessCodes): array
	{
		$result = [];

		$departments = Container::getNodeService()->findAllByAccessCodes($accessCodes);

		/**	@var Node $department */
		foreach ($departments as $department)
		{
			$result[$department->id] = true;
		}

		return array_keys(
			$result,
		);
	}
}
