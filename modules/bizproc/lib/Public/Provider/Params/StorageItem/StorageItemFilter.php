<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Provider\Params\StorageItem;

use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\Provider\Params\FilterInterface;
use Bitrix\Bizproc\Public\Service\StorageField\FieldService;
use Bitrix\Bizproc\Internal\Service\StorageField\Converter\ValueFieldConverter;

class StorageItemFilter implements FilterInterface
{
	private array $filter;

	public function __construct(array $filter = [])
	{
		$this->filter = $filter;
	}

	public function getRawFilter(): array
	{
		return $this->filter;
	}

	public function prepareFilter(?array $fieldMap = null): ConditionTree
	{
		$result = new ConditionTree();

		if (isset($this->filter['ID']))
		{
			if (is_array($this->filter['ID']))
			{
				$result->whereIn('ID', array_map('intval', $this->filter['ID']));
			}
			else
			{
				$result->where('ID', '=', (int)$this->filter['ID']);
			}
		}

		if (isset($this->filter['DOCUMENT_ID']))
		{
			$result->where('MODULE_ID', '=', (string)$this->filter['DOCUMENT_ID'][0]);
			$result->where('ENTITY', '=', (string)$this->filter['DOCUMENT_ID'][0]);
			$result->where('DOCUMENT_TYPE', '=', (string)$this->filter['DOCUMENT_ID'][0]);
		}

		if (isset($this->filter['WORKFLOW_ID']))
		{
			$result->where('WORKFLOW_ID', '=', (string)$this->filter['WORKFLOW_ID']);
		}

		if (isset($this->filter['TEMPLATE_ID']))
		{
			$result->where('TEMPLATE_ID', '=', (int)$this->filter['TEMPLATE_ID']);
		}

		if (isset($this->filter['TITLE']))
		{
			$title = (string)$this->filter['TITLE'];
			$result->whereLike('TITLE', "%{$title}%");
		}

		if (isset($this->filter['CREATED_BY']))
		{
			if (is_array($this->filter['CREATED_BY']))
			{
				$result->whereIn('CREATED_BY', array_map('intval', $this->filter['CREATED_BY']));
			}
			else
			{
				$result->where('CREATED_BY', '=', (int)$this->filter['CREATED_BY']);
			}
		}

		if (isset($this->filter['UPDATED_BY']))
		{
			if (is_array($this->filter['UPDATED_BY']))
			{
				$result->whereIn('UPDATED_BY', array_map('intval', $this->filter['UPDATED_BY']));
			}
			else
			{
				$result->where('UPDATED_BY', '=', (int)$this->filter['UPDATED_BY']);
			}
		}

		if (
			isset($this->filter['CREATED_WITHIN']['FROM'])
			&& isset($this->filter['CREATED_WITHIN']['TO'])
		)
		{
			$result
				->where('CREATED_TIME', '>=', $this->filter['CREATED_WITHIN']['FROM'])
				->where('CREATED_TIME', '<', $this->filter['CREATED_WITHIN']['TO'])
			;
		}

		if (
			isset($this->filter['UPDATED_WITHIN']['FROM'])
			&& isset($this->filter['UPDATED_WITHIN']['TO'])
		)
		{
			$result
				->where('UPDATED_TIME', '>=', $this->filter['UPDATED_WITHIN']['FROM'])
				->where('UPDATED_TIME', '<', $this->filter['UPDATED_WITHIN']['TO'])
			;
		}

		$lowerFieldMap = [];
		if ($fieldMap)
		{
			foreach ($fieldMap as $code => $field)
			{
				$lowerFieldMap[mb_strtolower((string)$code)] = $field;
			}
		}

		$this->appendConditions($result, $this->filter, $lowerFieldMap);

		return $result;
	}

	private function appendConditions(ConditionTree $node, array $filter, array $lowerFieldMap): void
	{
		if (isset($filter['LOGIC']))
		{
			$node->logic($filter['LOGIC']);
		}

		foreach ($filter as $key => $value)
		{
			if ($key === 'LOGIC')
			{
				continue;
			}

			if (is_array($value) && is_numeric($key))
			{
				$subNode = new ConditionTree();
				$this->appendConditions($subNode, $value, $lowerFieldMap);
				$node->where($subNode);

				continue;
			}

			$this->applySingleCondition($node, (string)$key, $value, $lowerFieldMap);
		}
	}

	private function applySingleCondition(ConditionTree $node, string $key, mixed $value, array $lowerFieldMap): void
	{
		if (preg_match('/^([!<>=%@?]*)(.+)$/', $key, $matches))
		{
			$operator  = $matches[1];
			$fieldCode = $matches[2];
			$lowerKey  = mb_strtolower($fieldCode);

			if (isset($lowerFieldMap[$lowerKey]))
			{
				$field = $lowerFieldMap[$lowerKey];
				$fieldId = $field->getId();
				$valueColumn = FieldService::isNumericFieldType($field->getType()) ? 'VALUE_NUM' : 'VALUE';
				$alias = "FIELD_{$fieldId}.{$valueColumn}";

				$value = $this->convertFilterValue($value, $field->getType());

				if (($operator === '' || $operator === '=') && \CBPHelper::isEmptyValue($value))
				{
					$sub = new ConditionTree();
					$sub->logic('or');
					$sub->whereNull($alias);
					$sub->where($alias, '=', '');
					$node->where($sub);

					return;
				}

				if ($value !== null && $this->isNegationOperator($operator))
				{
					$sub = new ConditionTree();
					$sub->logic('or');
					$this->applyOperator($sub, $alias, $operator, $value);
					$sub->whereNull($alias);
					$node->where($sub);

					return;
				}

				$this->applyOperator($node, $alias, $operator, $value);

				return;
			}

			$this->applyOperator($node, $fieldCode, $operator, $value);

			return;
		}

		$node->where($key, $value);
	}

	private function applyOperator(ConditionTree $node, string $field, string $operator, mixed $value): void
	{
		match ($operator)
		{
			'%'  => $node->whereLike($field, '%' . \CBPHelper::stringify($value) . '%'),
			'!%' => $node->whereNotLike($field, '%' . \CBPHelper::stringify($value) . '%'),
			'?'  => $node->whereLike($field, \CBPHelper::stringify($value)),
			'!?' => $node->whereNotLike($field, \CBPHelper::stringify($value)),
			'@'  => $node->whereIn($field, \CBPHelper::flatten($value)),
			'!@' => $node->whereNotIn($field, \CBPHelper::flatten($value)),

			'!', '!=' => match (true)
			{
				is_array($value) => $node->whereNotIn($field, \CBPHelper::flatten($value)),
				is_null($value) => $node->whereNotNull($field),
				default => $node->where($field, '!=', $value),
			},

			'', '=' => match (true)
			{
				is_array($value) => $node->whereIn($field, \CBPHelper::flatten($value)),
				is_null($value) => $node->whereNull($field),
				default => $node->where($field, '=', $value),
			},

			default => is_array($value) ? null : $node->where($field, $operator, $value),
		};
	}

	private function isNegationOperator(string $operator): bool
	{
		return in_array($operator, ['!', '!=', '!%', '!?', '!@'], true);
	}

	private function convertFilterValue(mixed $value, string $type): mixed
	{
		if (is_array($value))
		{
			return \CBPHelper::flatten(
				array_map(
					static fn(mixed $v) => ValueFieldConverter::toStorage($v, $type),
					$value,
				),
			);
		}

		return ValueFieldConverter::toStorage($value, $type);
	}
}
