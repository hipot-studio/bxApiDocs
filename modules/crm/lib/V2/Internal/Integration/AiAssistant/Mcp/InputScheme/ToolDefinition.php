<?php

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme;

use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\AbstractProperty;

final class ToolDefinition
{
	/** @var AbstractProperty[] */
	private array $properties = [];
	private bool $isAdditionalProperties = false;

	public function __construct(
		private readonly string $name,
		private readonly string $description,
	)
	{
	}

	public function getName(): string
	{
		return $this->name;
	}

	public function getDescription(): string
	{
		return $this->description;
	}

	/**
	 * @param AbstractProperty[] $properties
	 * @return $this
	 */
	public function setProperties(array $properties): self
	{
		$this->properties = $properties;

		return $this;
	}

	public function setIsAdditionalProperties(bool $hasAdditionalProperties): self
	{
		$this->isAdditionalProperties = $hasAdditionalProperties;

		return $this;
	}

	public function getInputScheme(): array
	{
		$properties = [];
		$required = [];
		foreach ($this->properties as $property)
		{
			$properties[$property->getId()] = $property->toArray();
			if ($property->isRequired())
			{
				$required[] = $property->getId();
			}
		}

		return [
			'type' => 'object',
			'properties' => $properties,
			'additionalProperties' => $this->isAdditionalProperties,
			'required' => $required,
		];
	}
}
