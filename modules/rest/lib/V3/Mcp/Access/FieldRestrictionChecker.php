<?php

namespace Bitrix\Rest\V3\Mcp\Access;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\Rest\V3\Schema\MethodDescription;

/**
 * Turns the hiddenFields and fieldWhitelist declarations of McpTool into a real restriction:
 * the published schema hides such fields, and a tool argument that still mentions one is rejected here.
 *
 * The walk repeats FilterStructure::fillStructure() and Condition::__construct() node by node, so every shape
 * that ends up as a column of the query is collected. A shape those two would not accept is rejected instead
 * of being skipped: an argument the checker cannot read must not reach the executor.
 */
final class FieldRestrictionChecker
{
	private const FIELD_PATH_SEPARATOR = '.';

	/**
	 * @throws McpException when tool arguments mention a field excluded from the export
	 */
	public function assertArgumentsAllowed(MethodDescription $methodDescription, array $arguments): void
	{
		$mcpTool = $methodDescription->mcpTool;
		if ($mcpTool === null || !$mcpTool->hasFieldRestrictions())
		{
			return;
		}

		foreach ($this->collectReferencedFields($arguments) as $fieldName)
		{
			if (!$mcpTool->isFieldAllowed($fieldName))
			{
				throw new McpException(sprintf('Field "%s" is not available for this tool.', $fieldName));
			}
		}
	}

	/**
	 * @return string[]
	 */
	private function collectReferencedFields(array $arguments): array
	{
		$fieldNames = [];

		foreach (['select', 'order', 'fields', 'aggregate'] as $key)
		{
			$this->collectFieldNames($arguments[$key] ?? null, $key, $fieldNames);
		}

		if (isset($arguments['cursor']) && is_array($arguments['cursor']))
		{
			$this->addFieldName($arguments['cursor']['field'] ?? null, 'cursor', $fieldNames);
		}

		$this->collectFilterFields($arguments['filter'] ?? null, $fieldNames);

		return array_values(array_unique($fieldNames));
	}

	/**
	 * @param string[] $fieldNames
	 */
	private function collectFieldNames(mixed $value, string $key, array &$fieldNames): void
	{
		if ($value === null)
		{
			return;
		}

		if (is_string($value))
		{
			// a scalar is cast to a single item list by the structure it feeds
			$this->addFieldName($value, $key, $fieldNames);

			return;
		}

		if (!is_array($value))
		{
			throw $this->unsupportedShape($key);
		}

		if ($key === 'aggregate')
		{
			// aggregate is a map of function name to the fields it is applied to
			foreach ($value as $aggregatedFields)
			{
				$this->collectAggregatedFieldNames($aggregatedFields, $fieldNames);
			}

			return;
		}

		foreach ($value as $itemKey => $item)
		{
			if ($key === 'select')
			{
				// SelectStructure::create() reads the value and ignores the key, so both positions are collected
				if (!is_int($itemKey))
				{
					$this->addFieldName($itemKey, $key, $fieldNames);
				}

				$this->addFieldName($item, $key, $fieldNames);

				continue;
			}

			// fields and order are keyed by field name
			$this->addFieldName(is_int($itemKey) ? $item : $itemKey, $key, $fieldNames);
		}
	}

	/**
	 * AggregationSelectStructure::create() takes the field name from the key and the value as its alias.
	 * An integer key means the opposite: the value itself is the field name.
	 *
	 * @param string[] $fieldNames
	 */
	private function collectAggregatedFieldNames(mixed $aggregatedFields, array &$fieldNames): void
	{
		if ($aggregatedFields === null)
		{
			return;
		}

		if (is_string($aggregatedFields))
		{
			// a scalar is cast to a single item list by the structure it feeds
			$this->addFieldName($aggregatedFields, 'aggregate', $fieldNames);

			return;
		}

		if (!is_array($aggregatedFields))
		{
			throw $this->unsupportedShape('aggregate');
		}

		foreach ($aggregatedFields as $fieldKey => $alias)
		{
			$this->addFieldName(is_int($fieldKey) ? $alias : $fieldKey, 'aggregate', $fieldNames);
		}
	}

	/**
	 * @param string[] $fieldNames
	 */
	private function collectFilterFields(mixed $filter, array &$fieldNames): void
	{
		if ($filter === null)
		{
			return;
		}

		if (!is_array($filter))
		{
			throw $this->unsupportedShape('filter');
		}

		if ($filter === [])
		{
			return;
		}

		$isStructured =
			isset($filter['type'])
			|| isset($filter['logic'])
			|| isset($filter['conditions'])
			|| isset($filter['negative'])
		;
		if (!$isStructured)
		{
			$this->collectPlainFilterFields($filter, $fieldNames);

			return;
		}

		if (($filter['type'] ?? null) === 'condition')
		{
			$this->addOperandField($filter['leftOperand'] ?? null, $fieldNames);
			$this->addValueOperandField($filter['rightOperand'] ?? null, $fieldNames);

			return;
		}

		if (!isset($filter['conditions']) || !is_array($filter['conditions']))
		{
			throw $this->unsupportedShape('filter');
		}

		foreach ($filter['conditions'] as $condition)
		{
			$this->collectFilterFields($condition, $fieldNames);
		}
	}

	/**
	 * @param string[] $fieldNames
	 */
	private function collectPlainFilterFields(array $filter, array &$fieldNames): void
	{
		if (is_array($filter[0] ?? null) && !isset($filter[0]['expression']))
		{
			foreach ($filter as $condition)
			{
				$this->collectFilterFields($condition, $fieldNames);
			}

			return;
		}

		$count = count($filter);
		if (!array_key_exists(0, $filter) || ($count !== 2 && $count !== 3))
		{
			throw $this->unsupportedShape('filter');
		}

		// a plain condition is [field, operator, value] or [field, value]
		$this->addOperandField($filter[0], $fieldNames);
		$this->addValueOperandField($count === 3 ? ($filter[2] ?? null) : ($filter[1] ?? null), $fieldNames);
	}

	/**
	 * A field position: either a name or an expression built over a name.
	 *
	 * @param string[] $fieldNames
	 */
	private function addOperandField(mixed $operand, array &$fieldNames): void
	{
		if (is_array($operand))
		{
			$this->addExpressionFields($operand, $fieldNames);

			return;
		}

		$this->addFieldName($operand, 'filter', $fieldNames);
	}

	/**
	 * A value position becomes a column only through an expression: Condition::__construct() converts an array
	 * carrying the expression key and keeps anything else as a plain value.
	 *
	 * @param string[] $fieldNames
	 */
	private function addValueOperandField(mixed $operand, array &$fieldNames): void
	{
		if (is_array($operand) && isset($operand['expression']))
		{
			$this->addExpressionFields($operand, $fieldNames);
		}
	}

	/**
	 * @param string[] $fieldNames
	 */
	private function addExpressionFields(array $operand, array &$fieldNames): void
	{
		if (!isset($operand['expression']))
		{
			throw $this->unsupportedShape('filter');
		}

		// Factory::createFromArray() casts parameters to an array and passes them to the expression as column names
		$parameters = (array)($operand['parameters'] ?? null);
		if ($parameters === [])
		{
			throw $this->unsupportedShape('filter');
		}

		foreach ($parameters as $parameter)
		{
			if (!is_string($parameter) || $parameter === '')
			{
				throw $this->unsupportedShape('filter');
			}

			$this->addFieldName($parameter, 'filter', $fieldNames);
		}
	}

	/**
	 * @param string[] $fieldNames
	 */
	private function addFieldName(mixed $fieldName, string $argumentName, array &$fieldNames): void
	{
		if ($fieldName === null)
		{
			return;
		}

		if (!is_string($fieldName) || $fieldName === '')
		{
			throw $this->unsupportedShape($argumentName);
		}

		// restrictions are declared for the entity own fields, so a path to a related entity is checked by its root
		$fieldNames[] = explode(self::FIELD_PATH_SEPARATOR, $fieldName, 2)[0];
	}

	private function unsupportedShape(string $argumentName): McpException
	{
		return new McpException(
			sprintf('Argument "%s" cannot be checked against the field restrictions of this tool.', $argumentName),
		);
	}
}
