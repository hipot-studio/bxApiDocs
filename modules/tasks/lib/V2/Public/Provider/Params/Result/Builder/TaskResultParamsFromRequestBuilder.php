<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Provider\Params\Result\Builder;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\ORM\Query\Filter\Condition;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\Provider\Params\Pager;
use Bitrix\Main\Provider\Params\PagerInterface;
use Bitrix\Rest\V3\Dto\DtoField;
use Bitrix\Rest\V3\Interaction\Request\ListRequest;
use Bitrix\Rest\V3\Structure\Filtering;
use Bitrix\Tasks\V2\Infrastructure\Rest\Dto\Task\ResultDto;
use Bitrix\Tasks\V2\Public\Provider\Params\Result\TaskResultFilter;
use Bitrix\Tasks\V2\Public\Provider\Params\Result\TaskResultSelect;
use Bitrix\Tasks\V2\Public\Provider\Params\Result\TaskResultSort;

class TaskResultParamsFromRequestBuilder implements TaskResultParamsBuilderInterface
{
	private const SELECT_MAPPING = [
		'author' => 'authorId',
	];

	public function __construct(
		public readonly ListRequest $request,
	)
	{
	}

	/**
	 * @throws ArgumentException
	 */
	public function buildPager(): PagerInterface
	{
		$pagination = $this->request?->pagination;

		$pager = new Pager();
		if ($pagination === null)
		{
			return $pager;
		}

		$limit = $this->request->pagination->getLimit();
		$offset = $this->request->pagination->getOffset();

		return $pager->setLimit($limit)->setOffset($offset);
	}

	/**
	 * @throws ArgumentException
	 */
	public function buildFilter(): ?TaskResultFilter
	{
		$filterCondition = new ConditionTree();

		$filter = $this->request?->filter;
		if ($filter !== null)
		{
			$filterCondition = $this->convertFilterStructure($filter);
		}

		return new TaskResultFilter($filterCondition);
	}

	/**
	 * @throws ArgumentException
	 */
	private function convertFilterStructure(Filtering\FilterStructure $filter): ?ConditionTree
	{
		if ($filter->getConditions() === null)
		{
			return null;
		}

		$conditionTree = new ConditionTree();

		$conditionTree->logic($filter->logic()->value);
		$conditionTree->negative($filter->isNegative());

		foreach ($filter->getConditions() as $object)
		{
			$convertedObject = match(true)
			{
				$object instanceof Filtering\FilterStructure => $this->convertFilterStructure($object),
				$object instanceof Filtering\Condition => $this->convertFilterStructureCondition($object),
				default => null,
			};

			if ($convertedObject !== null)
			{
				$conditionTree->where($convertedObject);
			}
		}

		return $conditionTree;
	}

	private function convertFilterStructureCondition(Filtering\Condition $condition): ?Condition
	{
		$column = $condition->getLeftOperand();
		$operator = $condition->getOperator()->value;
		$value = $condition->getRightOperand();

		return new Condition($column, $operator, $value);
	}

	public function buildSort(): ?TaskResultSort
	{
		$order = $this->request?->order;
		if ($order === null)
		{
			return null;
		}

		$conditions = [];
		foreach ($order->getItems() as $item)
		{
			$property = $item->getProperty();
			$value = $item->getOrder()->value;

			$conditions[$property] = $value;
		}

		return new TaskResultSort($conditions);
	}

	public function buildSelect(): ?TaskResultSelect
	{
		$select = $this->request?->select;
		if ($select === null)
		{
			return new TaskResultSelect(
				$this->getSelectDefaultFieldNames(),
			);
		}

		$selectCondition = array_merge($select->getList(), $select->getRelationFields());
		if (empty($selectCondition))
		{
			return new TaskResultSelect(
				$this->getSelectDefaultFieldNames(),
			);
		}

		$selectCondition = array_map(
			static fn ($item) => !empty(static::SELECT_MAPPING[$item]) ? static::SELECT_MAPPING[$item] : $item,
			$selectCondition,
		);

		$selectCondition = array_unique($selectCondition);
		$selectCondition = array_values($selectCondition);

		return new TaskResultSelect($selectCondition);
	}

	private function getSelectDefaultFieldNames(): array
	{
		$result = [];

		$fields = ResultDto::create()->getFields();

		/** @var DtoField $field */
		foreach ($fields as $field)
		{
			if ($field->getRelation())
			{
				continue;
			}

			$result[] = $field->getPropertyName();
		}

		return $result;
	}
}
