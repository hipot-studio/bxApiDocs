<?php

declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Connector\Schema\Builder;

use Bitrix\Landing\Copilot\Connector\Schema\Dto\SchemaNodeDto;
use Bitrix\Landing\Copilot\Connector\Schema\Schema;
use Bitrix\Main\SystemException;

class JsonShape implements IBuilder
{
	protected Schema $schemaObject;
	protected array $schema;

	/**
	 * Definition to be transformed into JSON schema format.
	 *
	 * @param Schema $schema Root schema structure object.
 	*/
	public function __construct(Schema $schema)
	{
		$this->schemaObject = $schema;
		$this->schema = $this->schemaObject->getSchema();
	}

	/**
	 * Builds a final JSON Schema representation from the structured schema definition.
	 *
	 * @return array Fully built schema representation.
	 * @throws SystemException
	 */
	public function build(): array
	{
		if (!isset($this->schema['name']))
		{
			throw new SystemException('Schema must contain "name" key');
		}

		return [
			'name' => $this->schema['name'],
			'schema' => $this->buildStructure(),
			'strict' => true,
		];
	}

	/**
	 * Recursively builds a structured schema from a node structure.
	 *
	 * @return array Built structure schema.
	 * @throws SystemException
	 */
	private function buildStructure(): array
	{
		if (!isset($this->schema['structure']))
		{
			throw new SystemException('Schema must contain "structure" key');
		}

		return $this->buildStructureNode($this->schema['structure']);
	}

	/**
	 * Builds a schema for a single node based on its type and system flag.
	 *
	 * @param SchemaNodeDto|array $structureNode Node description with optional type information.
	 *
	 * @return array Schema representation of the node.
	 * @throws SystemException
	 */
	private function buildStructureNode(SchemaNodeDto|array $structureNode): array
	{
		if (!$structureNode instanceof SchemaNodeDto)
		{
			return $structureNode;
		}

		$type = $structureNode->type ?? null;

		if (!is_string($type) || $type === '')
		{
			throw new SystemException('Structure node type is required for system nodes');
		}

		return match ($type)
		{
			'string' => $this->buildStringNode($structureNode),
			'array' => $this->buildArrayNode($structureNode),
			'object' => $this->buildObjectNode($structureNode),
			'integer' => $this->buildIntegerNode($structureNode),
			default => throw new SystemException('Unknown structure node type: ' . $type),
		};
	}

	/**
	 * Returns a basic string type schema.
	 *
	 * @param SchemaNodeDto $structureNode Node describing string properties.
	 *
	 * @return array The string type schema.
	 */
	private function buildStringNode(SchemaNodeDto $structureNode): array
	{
		$schema = [
			'type' => 'string',
		];

		if (isset($structureNode->params['const']))
		{
			$schema['const'] = $structureNode->params['const'];
		}

		return $schema;
	}

	/**
	 * Builds an array schema with items and explicit min and max constraints.
	 *
	 * @param SchemaNodeDto $structureNode Node describing array items and constraints.
	 *
	 * @return array The constructed array schema.
	 * @throws SystemException
	 */
	private function buildArrayNode(SchemaNodeDto $structureNode): array
	{
		$count = (int)($structureNode->params['count'] ?? 0);
		$result = [
			'type' => 'array',
			'minItems' => $count,
			'maxItems' => $count,
			'items' => [],
		];

		if (!isset($structureNode->params['items']))
		{
			return $result;
		}

		$items = $structureNode->params['items'];

		if (
			isset($structureNode->params['isAnyOf'])
			&& $structureNode->params['isAnyOf'] === true
			&& is_array($items)
		)
		{
			$anyOfItems = [];
			foreach ($items as $childNode)
			{
				$anyOfItems[] = $this->buildStructureNode($childNode);
			}

			$result['items'] = [
				'anyOf' => $anyOfItems
			];
		}
		else
		{
			$result['items'] = $this->buildStructureNode($items);
		}

		return $result;
	}

	/**
	 * Builds an object schema with defined properties and required keys.
	 *
	 * @param SchemaNodeDto $structureNode Node describing object properties.
	 *
	 * @return array The constructed object schema.
	 * @throws SystemException
	 */
	private function buildObjectNode(SchemaNodeDto $structureNode): array
	{
		$objectProperties = [];

		if (isset($structureNode->params['properties']) && is_array($structureNode->params['properties']))
		{
			foreach ($structureNode->params['properties'] as $propertyName => $property)
			{
				if ($property instanceof SchemaNodeDto)
				{
					if (is_int($propertyName))
					{
						$propertyName = $property->key ?? null;
					}

					if ($propertyName !== null)
					{
						$objectProperties[$propertyName] = $this->buildStructureNode($property);
					}
				}
			}
		}

		$schema = [
			'type' => 'object',
			'properties' => $objectProperties,
			'additionalProperties' => false,
		];

		$schema['required'] = array_keys($objectProperties);

		return $schema;
	}

	/**
	 * Returns a basic integer type schema.
	 *
	 * @param SchemaNodeDto $structureNode Node describing integer properties.
	 *
	 * @return array The integer type schema.
	 */
	private function buildIntegerNode(SchemaNodeDto $structureNode): array
	{
		$schema = [
			'type' => 'integer',
		];

		if (isset($structureNode->params['const']))
		{
			$schema['const'] = $structureNode->params['const'];
		}

		return $schema;
	}
}
