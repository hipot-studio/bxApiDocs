<?php

namespace Bitrix\HumanResources\Compatibility\Adapter;

use Bitrix\HumanResources\Compatibility\Utils\DepartmentBackwardAccessCode;
use Bitrix\HumanResources\Item\NodeMember;
use Bitrix\HumanResources\Item\Structure;
use Bitrix\HumanResources\Item\Node;
use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Enum\DepthLevel;
use Bitrix\HumanResources\Config;

class StructureBackwardAdapter
{
	private const INTRANET_DEPARTMENT = '^(D)(\d+)$';
	private static array $structureWithoutEmployee = [];
	private static ?int $headRole = null;
	private static array $nodeHeads = [];

	private static array $structureCache = [];

	public static function getStructure(?int $fromIblockSectionId = null, ?int $depth = 0): array
	{
		if (!Config\Storage::instance()->isIntranetUtilsDisabled())
		{
			return [];
		}

		if (!empty(self::$structureCache))
		{
			return self::$structureCache;
		}

		$structure = self::getStructureWithoutEmployee($fromIblockSectionId, $depth);

		if (empty($structure))
		{
			return [];
		}

		$headRole = Container::getRoleRepository()->findByXmlId(NodeMember::DEFAULT_ROLE_XML_ID['HEAD'])->id;

		$employees = Container::getNodeMemberService()->getAllEmployees($structure['ROOT']['ID'], true);

		foreach ($employees as $employee)
		{
			$department = $structure['DATA'][$structure['COMPATIBILITY'][$employee->nodeId]] ?? false;
			if (!$department)
			{
				continue;
			}
			if (!$department['EMPLOYEES'])
			{
				$structure['DATA'][$structure['COMPATIBILITY'][$employee->nodeId]]['EMPLOYEES'] = [];
			}

			$structure['DATA'][$structure['COMPATIBILITY'][$employee->nodeId]]['EMPLOYEES'][] = $employee->entityId;

			if (in_array($headRole, $employee->roles))
			{
				$structure['DATA'][$structure['COMPATIBILITY'][$employee->nodeId]]['UF_HEAD'] = $employee->entityId;
			}
		}
		self::$structureCache = $structure;

		return $structure;
	}

	public static function getStructureWithoutEmployee(?int $fromIblockSectionId = null, ?int $depth = 0): array
	{
		if (!Config\Storage::instance()->isIntranetUtilsDisabled())
		{
			return [];
		}

		if (!empty(self::$structureWithoutEmployee))
		{
			return self::$structureWithoutEmployee;
		}

		if (!Config\Storage::instance()->isCompanyStructureConverted(false))
		{
			return [];
		}

		$nodeRepository = Container::getNodeRepository();
		$structureRepository = Container::getStructureRepository();

		$structure = $structureRepository->getByXmlId(Structure::DEFAULT_STRUCTURE_XML_ID);
		if (!$structure)
		{
			return [];
		}

		try
		{
			if (!$fromIblockSectionId)
			{
				$rootNode = $nodeRepository
					->getRootNodeByStructureId($structure->id)
				;
			}
			else
			{
				$rootNode = $nodeRepository->getByAccessCode(
					DepartmentBackwardAccessCode::makeById($fromIblockSectionId)
				);
			}
		}
		catch (\Exception $exception)
		{
			return [];
		}

		if (!$rootNode)
		{
			return [];
		}

		$children = $nodeRepository->getChildOf($rootNode, !$depth ? DepthLevel::FULL : $depth);

		$structureArray = [
			'TREE' => [],
			'DATA' => [],
			'ROOT' => ['ID' => $rootNode->id,],
			'COMPATIBILITY' => [],
		];

		$parentNodes = [];
		foreach ($children as $child)
		{
			if (isset($parentNodes[$child->parentId]))
			{
				$parentId = $parentNodes[$child->parentId];
			}
			else
			{
				$parent = $children->getItemById($child->parentId);
				$parentId = DepartmentBackwardAccessCode::extractIdFromCode(
					$parent !== null
						? $parent->accessCode
						: $nodeRepository->getById($child->parentId)?->accessCode
				);
			}

			if ($parentId === null)
			{
				continue;
			}

			$parentNodes[$child->parentId] ??= $parentId;

			$id = DepartmentBackwardAccessCode::extractIdFromCode($child->accessCode);
			$structureArray['TREE'][$parentId][] = $id;

			$structureArray['DATA'][$id] =  [
				'ID' => $id,
				'NAME' => $child->name,
				'IBLOCK_SECTION_ID' => $parentId,
				'UF_HEAD' => self::getHeadPersonValue($child),
				'SECTION_PAGE_URL' => '#SITE_DIR#company/structure.php?set_filter_structure=Y&structure_UF_DEPARTMENT=#ID#',
				'DEPTH_LEVEL' => $child->depth + 1,
				'EMPLOYEES' => [],
				'STRUCTURE_NODE_ID' => $child->id,
			];

			$structureArray['COMPATIBILITY'][$child->id] = $id;

		}

		self::$structureWithoutEmployee = $structureArray;
		return self::$structureWithoutEmployee;
	}

	public static function extractId(?string $accessCode): ?int
	{
		if (!$accessCode)
		{
			return null;
		}

		if (preg_match('/'. self::INTRANET_DEPARTMENT .'/', $accessCode, $matches))
		{
			if (array_key_exists('2', $matches))
			{
				return (int) $matches[2];
			}
		}
		return null;
	}

	private static function getHeadPersonValue(Node $node): ?int
	{
		if (!static::$headRole)
		{
			static::$headRole = Container::getRoleRepository()
				->findByXmlId(NodeMember::DEFAULT_ROLE_XML_ID['HEAD'])->id;
		}

		if (!empty(static::$nodeHeads))
		{
			return static::$nodeHeads[$node->id] ?? null;
		}
		$headMembers = Container::getNodeMemberRepository()
			->findAllByRoleIdAndStructureId(static::$headRole, $node->structureId)
		;

		foreach ($headMembers as $headMember)
		{
			static::$nodeHeads[$headMember->nodeId] = $headMember->entityId;
		}

		if (empty(static::$nodeHeads))
		{
			static::$nodeHeads[] = 0;
		}

		return static::$nodeHeads[$node->id] ?? null;
	}
}