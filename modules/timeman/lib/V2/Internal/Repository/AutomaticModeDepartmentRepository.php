<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Internal\Repository;

use Bitrix\HumanResources\Compatibility\Utils\DepartmentBackwardAccessCode;
use Bitrix\HumanResources\Config\Storage;
use Bitrix\HumanResources\Item\Structure;
use Bitrix\HumanResources\Model\NodeTable;
use Bitrix\HumanResources\Model\StructureTable;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\Main\Loader;

class AutomaticModeDepartmentRepository
{
	/** @return array<int, int> */
	public function getParentByDepartment(): array
	{
		if (
			Loader::includeModule('humanresources')
			&& Storage::instance()->isIntranetUtilsDisabled()
			&& Storage::instance()->isCompanyStructureConverted(false)
		)
		{
			$parents = $this->loadHumanResourcesParents();
			if ($parents !== [])
			{
				return $parents;
			}
		}

		return $this->loadIblockParents();
	}

	protected function loadIblockParents(): array
	{
		$iblockId = $this->getIblockId();
		if ($iblockId <= 0 || !Loader::includeModule('iblock'))
		{
			return [];
		}

		$rows = \CIBlockSection::GetList(
			['LEFT_MARGIN' => 'ASC'],
			['IBLOCK_ID' => $iblockId, 'ACTIVE' => 'Y'],
			false,
			['ID', 'IBLOCK_SECTION_ID'],
		);
		$result = [];
		while ($row = $rows->Fetch())
		{
			$result[(int)$row['ID']] = (int)$row['IBLOCK_SECTION_ID'];
		}

		return $result;
	}

	protected function loadHumanResourcesParents(): array
	{
		$structureId = $this->getStructureId();
		if ($structureId <= 0)
		{
			return [];
		}

		$root = NodeTable::query()
			->setSelect(['ID'])
			->where('STRUCTURE_ID', $structureId)
			->where('PARENT_ID', 0)
			->fetch()
		;
		if (!$root)
		{
			return [];
		}

		$nodes = NodeTable::query()
			->setSelect(['ID', 'PARENT_ID', 'DEPTH' => 'CHILD_NODES.DEPTH', 'CODE' => 'ACCESS_CODE.ACCESS_CODE'])
			->where('CHILD_NODES.PARENT_ID', (int)$root['ID'])
			->where('TYPE', NodeEntityType::DEPARTMENT->value)
			->where('GLOBAL_ACTIVE', true)
			->fetchAll()
		;
		$parentIds = array_values(array_unique(array_filter(array_map('intval', array_column($nodes, 'PARENT_ID')))));
		$codes = [];
		if ($parentIds !== [])
		{
			$parents = NodeTable::query()
				->setSelect(['ID', 'CODE' => 'ACCESS_CODE.ACCESS_CODE'])
				->whereIn('ID', $parentIds)
				->fetchAll()
			;
			foreach ($parents as $parent)
			{
				$id = DepartmentBackwardAccessCode::extractIdFromCode($parent['CODE']);
				if ($id !== null)
				{
					$codes[(int)$parent['ID']] = $id;
				}
			}
		}

		return $this->mapHumanResourcesParents($nodes, $codes);
	}

	protected function getIblockId(): int
	{
		return \COption::GetOptionInt('intranet', 'iblock_structure', 0);
	}

	protected function getStructureId(): int
	{
		$structure = StructureTable::query()
			->setSelect(['ID'])
			->where('XML_ID', Structure::DEFAULT_STRUCTURE_XML_ID)
			->fetch()
		;

		return (int)($structure['ID'] ?? 0);
	}

	protected function mapHumanResourcesParents(array $nodes, array $parentCodes): array
	{
		$result = [];
		foreach ($nodes as $node)
		{
			$id = DepartmentBackwardAccessCode::extractIdFromCode($node['CODE']);
			$parentId = $parentCodes[(int)$node['PARENT_ID']] ?? null;
			if ($id !== null && ($parentId !== null || (int)$node['DEPTH'] === 0))
			{
				$result[$id] = $parentId ?? 0;
			}
		}

		return $result;
	}
}
