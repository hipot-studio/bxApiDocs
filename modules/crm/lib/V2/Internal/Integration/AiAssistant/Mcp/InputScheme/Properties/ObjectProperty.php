<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties;

final class ObjectProperty extends AbstractProperty
{
	use Validation\DependentRequiredValidationTrait;
	use Validation\MaxPropertiesValidationTrait;
	use Validation\MinPropertiesValidationTrait;

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
	public function setProperties(array $properties): static
	{
		$this->properties = $properties;

		return $this;
	}

	public function setIsAdditionalProperties(bool $isAdditionalProperties): static
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
			...$this->getBaseSchema(),
			'properties' => $properties,
			'required' => $required,
			'additionalProperties' => $this->isAdditionalProperties,
			...$this->getCommonValidationSchema(),
			...$this->getMinPropertiesValidationSchema(),
			...$this->getMaxPropertiesValidationSchema(),
			...$this->getDependentRequiredValidationSchema(),
		];
	}
}
