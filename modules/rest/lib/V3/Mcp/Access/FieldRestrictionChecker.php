<?php

namespace Bitrix\Rest\V3\Mcp\Access;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\Rest\V3\Exception\RestException;
use Bitrix\Rest\V3\Mcp\Schema\AggregateSelectResolver;
use Bitrix\Rest\V3\Schema\MethodDescription;
use Bitrix\Rest\V3\Structure\Aggregation\AggregationSelectStructure;
use Bitrix\Rest\V3\Structure\CursorStructure;
use Bitrix\Rest\V3\Structure\FieldsStructure;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;
use Bitrix\Rest\V3\Structure\Ordering\OrderStructure;
use Bitrix\Rest\V3\Structure\ReferencesDtoFields;

/**
 * Turns the hiddenFields and fieldWhitelist declarations of McpTool into a real restriction:
 * the published schema hides such fields, and a tool argument that still mentions one is rejected here.
 *
 * Fields of filter, order, cursor, fields and of an aggregation select are read by the structures those arguments
 * feed, so the checker follows the same grammar as the request. A shape the structure would not accept is rejected
 * instead of being skipped: an argument the checker cannot read must not reach the executor.
 */
final class FieldRestrictionChecker
{
	private const FIELD_PATH_SEPARATOR = '.';

	/**
	 * @var array<string, class-string<ReferencesDtoFields>>
	 */
	private const STRUCTURE_ARGUMENTS = [
		'order' => OrderStructure::class,
		'fields' => FieldsStructure::class,
	];

	public function __construct(private readonly ?AggregateSelectResolver $aggregateSelectResolver = null)
	{
	}

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

		foreach ($this->collectReferencedFields($methodDescription, $arguments) as $fieldName)
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
	private function collectReferencedFields(MethodDescription $methodDescription, array $arguments): array
	{
		$fieldNames = [];

		if ($this->getAggregateSelectResolver()->isAggregateSelect($methodDescription))
		{
			$this->collectStructureFieldNames(
				AggregationSelectStructure::class,
				$arguments['select'] ?? null,
				'select',
				$fieldNames,
			);
		}
		else
		{
			$this->collectSelectFieldNames($arguments['select'] ?? null, $fieldNames);
		}

		foreach (self::STRUCTURE_ARGUMENTS as $argumentName => $structureClass)
		{
			$this->collectStructureFieldNames(
				$structureClass,
				$arguments[$argumentName] ?? null,
				$argumentName,
				$fieldNames,
			);
		}

		if (isset($arguments['cursor']) && is_array($arguments['cursor']))
		{
			$this->collectStructureFieldNames(CursorStructure::class, $arguments['cursor'], 'cursor', $fieldNames);
		}

		$this->collectStructureFieldNames(FilterStructure::class, $arguments['filter'] ?? null, 'filter', $fieldNames);

		return array_values(array_unique($fieldNames));
	}

	/**
	 * @param string[] $fieldNames
	 */
	private function collectSelectFieldNames(mixed $select, array &$fieldNames): void
	{
		if ($select === null)
		{
			return;
		}

		if (is_string($select))
		{
			// a scalar is cast to a single item list by the structure it feeds
			$this->addFieldName($select, 'select', $fieldNames);

			return;
		}

		if (!is_array($select))
		{
			throw $this->unsupportedShape('select');
		}

		foreach ($select as $itemKey => $item)
		{
			// SelectStructure::create() reads the value and ignores the key, so both positions are collected
			if (!is_int($itemKey))
			{
				$this->addFieldName($itemKey, 'select', $fieldNames);
			}

			$this->addFieldName($item, 'select', $fieldNames);
		}
	}

	/**
	 * @param class-string<ReferencesDtoFields> $structureClass
	 * @param string[] $fieldNames
	 */
	private function collectStructureFieldNames(
		string $structureClass,
		mixed $value,
		string $argumentName,
		array &$fieldNames,
	): void
	{
		if ($value === null)
		{
			return;
		}

		try
		{
			$referencedFields = $structureClass::getReferencedFields($value);
		}
		catch (RestException)
		{
			throw $this->unsupportedShape($argumentName);
		}

		foreach ($referencedFields as $fieldName)
		{
			$this->addFieldName($fieldName, $argumentName, $fieldNames);
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

	private function getAggregateSelectResolver(): AggregateSelectResolver
	{
		return $this->aggregateSelectResolver ?? new AggregateSelectResolver();
	}

	private function unsupportedShape(string $argumentName): McpException
	{
		return new McpException(
			sprintf('Argument "%s" cannot be checked against the field restrictions of this tool.', $argumentName),
		);
	}
}
