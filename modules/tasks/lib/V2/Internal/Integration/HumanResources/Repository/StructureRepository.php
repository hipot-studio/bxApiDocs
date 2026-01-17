<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\HumanResources\Repository;

use Bitrix\HumanResources\Item\Structure;
use Bitrix\HumanResources\Service\Container;
use Bitrix\Main\Loader;
use Bitrix\Tasks\V2\Internal\Integration\HumanResources\Entity\Department;
use Bitrix\Tasks\V2\Internal\Integration\HumanResources\Entity\DepartmentCollection;
use Bitrix\Tasks\V2\Internal\Integration\HumanResources\Repository\Mapper\DepartmentMapper;

class StructureRepository implements StructureRepositoryInterface
{
	public const AVATAR_PATH = '/bitrix/js/intranet/entity-selector/src/images/department-option.svg';

	public function __construct(
		private readonly DepartmentMapper $departmentMapper,
	)
	{
	}

	public function getMainDepartment(): ?Department
	{
		if (!Loader::includeModule('humanresources'))
		{
			return null;
		}

		$structure = Container::getStructureRepository()->getByXmlId(Structure::DEFAULT_STRUCTURE_XML_ID);
		if ($structure === null)
		{
			return null;
		}

		$node = Container::getNodeRepository()->getRootNodeByStructureId($structure->id);
		if ($node === null)
		{
			return null;
		}

		$avatarPath = null;
		if (Loader::includeModule('intranet'))
		{
			$avatarPath = static::AVATAR_PATH;
		}

		return $this->departmentMapper->mapToEntity($node, $avatarPath);
	}

	public function getDepartmentsByAccessCodes(array $accessCodes): DepartmentCollection
	{
		if (!Loader::includeModule('humanresources'))
		{
			return new DepartmentCollection();
		}

		$nodes = Container::getNodeRepository()->findAllByAccessCodes($accessCodes);
		if ($nodes->empty())
		{
			return new DepartmentCollection();
		}

		$avatarPath = null;
		if (Loader::includeModule('intranet'))
		{
			$avatarPath = static::AVATAR_PATH;
		}

		return $this->departmentMapper->mapToCollection($nodes, $avatarPath);
	}
}
