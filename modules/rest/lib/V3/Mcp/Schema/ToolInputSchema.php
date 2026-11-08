<?php

namespace Bitrix\Rest\V3\Mcp\Schema;

use JsonSerializable;

final class ToolInputSchema implements JsonSerializable
{
	public function __construct(private readonly array $schema)
	{
	}

	public function toArray(): array
	{
		return $this->schema;
	}

	public function jsonSerialize(): object
	{
		return $this->serializeSchema($this->schema);
	}

	private function serializeSchema(array $schema): object
	{
		if (array_key_exists('properties', $schema) && is_array($schema['properties']))
		{
			$properties = [];
			foreach ($schema['properties'] as $name => $propertySchema)
			{
				$properties[$name] = is_array($propertySchema)
					? $this->serializeSchema($propertySchema)
					: $propertySchema;
			}

			$schema['properties'] = (object)$properties;
		}

		foreach (['items', 'additionalProperties'] as $keyword)
		{
			if (array_key_exists($keyword, $schema) && is_array($schema[$keyword]))
			{
				$schema[$keyword] = $this->serializeSchema($schema[$keyword]);
			}
		}

		return (object)$schema;
	}
}
