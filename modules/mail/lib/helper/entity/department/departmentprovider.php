<?php

namespace Bitrix\Mail\Helper\Entity\Department;

use Bitrix\HumanResources\Service\Container;
use Bitrix\Mail\Helper\Entity\BaseProvider;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;

final class DepartmentProvider extends BaseProvider
{
	protected function createEntityInstance(array $entityData): Department
	{
		return new Department($entityData);
	}

	/**
	 * @param array $entityIds
	 * @return array{
	 *     ID: string,
	 *     NAME: string,
	 * }
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	protected function getEntities(array $entityIds): array
	{
		$nodeCollection = Container::getNodeRepository()->findAllByAccessCodes($entityIds);

		$result = [];
		foreach ($nodeCollection as $node)
		{
			$nodeItem['ID'] = $node->id;
			$nodeItem['NAME'] = $node->name;
			$nodeItem['ACCESS_CODE'] = $node->accessCode;

			$result[$node->accessCode] = $nodeItem;
		}

		return $result;
	}
}
