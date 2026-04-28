<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Builder\Structure\Filter;

use Bitrix\HumanResources\Access\Permission\PermissionVariablesDictionary;
use Bitrix\HumanResources\Builder\Structure\Filter\Column\IdFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\Column\Node\NodeNameFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\Column\Node\NodeTypeFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\SelectionCondition\Node\NodeAccessFilter;
use Bitrix\HumanResources\Enum\ConditionMode;
use Bitrix\HumanResources\Enum\DepthLevel;
use Bitrix\HumanResources\Enum\Direction;
use Bitrix\HumanResources\Enum\NodeActiveFilter;
use Bitrix\HumanResources\Exception\NodeAccessFilterException;
use Bitrix\HumanResources\Internals\Service\Container as InternalContainer;
use Bitrix\HumanResources\Service\Access\Structure\StructureAccessService;
use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Util\StructureHelper;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\SystemException;

final class NodeFilter extends BaseFilter
{
	public function __construct(
		public ?IdFilter $idFilter = null,
		public ?NodeTypeFilter $entityTypeFilter = null,
		public ?int $structureId = null,
		public ?Direction $direction = null,
		public null|int|DepthLevel $depthLevel = null,
		public bool|NodeActiveFilter $active = true,
		public ?NodeAccessFilter $accessFilter = null,
		public string|NodeNameFilter|null $name = null,
	)
	{
		$this->structureId ??= StructureHelper::getDefaultStructure()?->id;
	}

	public static function createWithNodeId(
		int $id,
	): self
	{
		return new self(
			idFilter: IdFilter::fromId($id),
		);
	}

	/**
	 * @return ConditionTree
	 * @throws ArgumentException
	 * @throws NodeAccessFilterException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function prepareFilter(): ConditionTree
	{
		$conditionTree = new ConditionTree();
		$conditionTree->where($this->getFieldByQueryContext('STRUCTURE_ID'), $this->structureId);
		$this->addConditionsForIdsFilter($conditionTree);
		$this->addActiveFilter($conditionTree);
		$this->addConditionForNameFilter($conditionTree);

		$additionalEntityTypeCondition = $this->applyNodeEntityTypeWithActionPermissionCheck();
		if ($additionalEntityTypeCondition)
		{
			return (new ConditionTree())
				->logic(ConditionTree::LOGIC_AND)
				->addCondition($conditionTree)
				->addCondition($additionalEntityTypeCondition)
			;
		}

		return $conditionTree;
	}

	private function addConditionsForIdsFilter(ConditionTree $conditionTree): void
	{
		if (is_null($this->idFilter) && is_int($this->depthLevel))
		{
			$permissionValue = PermissionVariablesDictionary::VARIABLE_NONE;
			if ($this->accessFilter)
			{
				$structureAccessService = new StructureAccessService();
				$structureAccessService->setAction($this->accessFilter->action);
				$permissionValue = $structureAccessService->getPermissionValue()->getFirst()?->value ?? PermissionVariablesDictionary::VARIABLE_NONE;
			}

			if (is_null($this->accessFilter) || $permissionValue === PermissionVariablesDictionary::VARIABLE_ALL)
			{
				if (!$this->structureId)
				{
					return;
				}

				$rootNode = InternalContainer::getNodeRepository()->getRootNodeByStructureId($this->structureId);
				if ($rootNode?->id)
				{
					$this->idFilter = IdFilter::fromId($rootNode->id);
				}
			}
		}

		if (is_null($this->idFilter))
		{
			return;
		}

		// If the mode is exclusion or the depth is not provided, there is no point in building a complex condition
		if (
			$this->idFilter->filterMode === ConditionMode::Exclusion
			|| in_array($this->depthLevel, [null, 0, DepthLevel::NONE], true)
		)
		{
			$idsFilter = $this->idFilter
				->setCurrentAlias($this->currentAlias)
				->prepareFilter()
			;

			$conditionTree->addCondition(
				$idsFilter,
			);

			return;
		}

		$idField = $this->direction === Direction::ROOT
			? 'PARENT_NODES.CHILD_ID'
			: 'CHILD_NODES.PARENT_ID'
		;

		$depthField = 'CHILD_NODES.DEPTH';

		// If the depth is FULL, there is no point in building a complex condition.
		if ($this->depthLevel === DepthLevel::FULL)
		{
			$conditionTree->whereIn(
				$this->getFieldByQueryContext($idField),
				$this->idFilter->ids->getItems(),
			);

			return;
		}

		// WITHOUT_PARENT returns only nodes with depth 1 relative to the specified parent.
		if ($this->depthLevel === DepthLevel::FIRST)
		{
			$operator = '=';
		}
		elseif ($this->depthLevel === DepthLevel::WITHOUT_PARENT)
		{
			$operator = $this->direction === Direction::ROOT ? '<' : '>' ;
		}
		else
		{
			$operator = $this->direction === Direction::ROOT ? '>=' : '<=' ;
		}


		$conditionDepthLevel = 0;
		if ($this->depthLevel === DepthLevel::FIRST)
		{
			$conditionDepthLevel = 1;
		}
		elseif (is_int($this->depthLevel))
		{
			$conditionDepthLevel = $this->depthLevel;
		}

		$subConditionTree = new ConditionTree();
		if ($this->direction === Direction::ROOT)
		{
			// When searching for parents, the expected depth for each specified node must be calculated individually:
			// expectedDepth = nodeDepth - expectedParentBranchLevel
			$subConditionTree->logic(ConditionTree::LOGIC_OR);
			foreach ($this->idFilter->ids->getItems() as $id)
			{
				$singleIdConditionTree = new ConditionTree();
				$singleIdConditionTree->logic(ConditionTree::LOGIC_AND);
				$singleIdConditionTree->where(
					$this->getFieldByQueryContext($idField),
					$id,
				);

				$node = InternalContainer::getNodeRepository()->getByIdWithDepth($id);
				$calculatedParentDepthLevel = $node->depth - $conditionDepthLevel;
				$singleIdConditionTree->where(
					$this->getFieldByQueryContext($depthField),
					$operator,
					$calculatedParentDepthLevel,
				);
				$subConditionTree->addCondition($singleIdConditionTree);
			}
		}
		else
		{
			$subConditionTree->logic(ConditionTree::LOGIC_AND);
			$subConditionTree->whereIn(
				$this->getFieldByQueryContext($idField),
				$this->idFilter->ids->getItems(),
			);
			$subConditionTree->where(
				$this->getFieldByQueryContext($depthField),
				$operator,
				$conditionDepthLevel,
			);
		}

		$conditionTree->addCondition($subConditionTree);
	}

	private function addActiveFilter(ConditionTree $conditionTree): void
	{
		if (is_bool($this->active))
		{
			$conditionTree->where($this->getFieldByQueryContext('GLOBAL_ACTIVE'), $this->active);

			return;
		}

		if ($this->active === NodeActiveFilter::ALL)
		{
			return;
		}

		if ($this->active === NodeActiveFilter::ONLY_ACTIVE)
		{
			$conditionTree->where($this->getFieldByQueryContext('GLOBAL_ACTIVE'), true);

			return;
		}

		$conditionTree->where($this->getFieldByQueryContext('ACTIVE'), true);
	}

	private function addConditionForNameFilter(ConditionTree $conditionTree): void
	{
		if (!$this->name)
		{
			return;
		}

		if (is_string($this->name))
		{
			$conditionTree->whereLike(
				$this->getFieldByQueryContext('NAME'),
				'%' . $this->name . '%',
			);

			return;
		}

		if (!($this->name instanceof NodeNameFilter))
		{
			return;
		}

		$nameFilter = $this->name
			->setCurrentAlias($this->currentAlias)
			->prepareFilter()
		;

		$conditionTree->addCondition($nameFilter);
	}

	/**
	 * @param ConditionTree $conditionTree
	 *
	 * @return ConditionTree
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @throws NodeAccessFilterException
	 */
	private function applyNodeEntityTypeWithActionPermissionCheck(): ?ConditionTree
	{
		if (is_null($this->entityTypeFilter) && is_null($this->accessFilter))
		{
			return null;
		}

		$conditionTree = new ConditionTree();
		if (is_null($this->accessFilter))
		{
			$conditionTree->addCondition(
				$this->entityTypeFilter->setCurrentAlias($this->currentAlias)->prepareFilter(),
			);

			return $conditionTree;
		}

		if (
			is_null($this->entityTypeFilter)
			|| is_null($this->entityTypeFilter->entityTypes)
			|| $this->entityTypeFilter->entityTypes->count() === 0
		)
		{
			throw new ArgumentException('Node entity types must be set for access permission check');
		}

		$entityTypes = $this->entityTypeFilter->entityTypes;
		$currentAlias = $this->getCurrentAlias();
		if (!empty($currentAlias))
		{
			$this->accessFilter->setCurrentAlias($currentAlias);
		}

		return $conditionTree->addCondition($this->accessFilter->setNodeEntityTypes($entityTypes)->prepareFilter());
	}
}
