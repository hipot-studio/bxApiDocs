<?php

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties;

final class ObjectProperty extends AbstractProperty
{
	/** @var AbstractProperty[] */
	private array $properties = [];
	private bool $isAdditionalProperties = false;

	public function getType(): string
	{
		return 'object';
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

	public function setIsAdditionalProperties(bool $isAdditionalProperties): self
	{
		$this->isAdditionalProperties = $isAdditionalProperties;

		return $this;
	}

	public function toArray(): array
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
			...parent::toArray(),
			'properties' => $properties,
			'required' => $required,
			'additionalProperties' => $this->isAdditionalProperties,
		];
	}
}
