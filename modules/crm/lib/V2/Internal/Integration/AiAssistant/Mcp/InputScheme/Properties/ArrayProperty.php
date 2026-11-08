<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties;

final class ArrayProperty extends AbstractProperty
{
	use Validation\MaxItemsValidationTrait;
	use Validation\MinItemsValidationTrait;
	use Validation\UniqueItemsValidationTrait;

	private ?AbstractProperty $arrayItemProperty = null;

	public function setArrayItemProperty(AbstractProperty $property): static
	{
		$this->arrayItemProperty = $property;

		return $this;
	}

	public function getType(): string
	{
		return 'array';
	}

	public function toArray(): array
	{
		if ($this->arrayItemProperty === null)
		{
			throw new \LogicException('Array item property must be set before serializing array schema.');
		}

		return [
			...$this->getBaseSchema(),
			'items' => $this->arrayItemProperty->toArray(),
			...$this->getCommonValidationSchema(),
			...$this->getMinItemsValidationSchema(),
			...$this->getMaxItemsValidationSchema(),
			...$this->getUniqueItemsValidationSchema(),
		];
	}
}
