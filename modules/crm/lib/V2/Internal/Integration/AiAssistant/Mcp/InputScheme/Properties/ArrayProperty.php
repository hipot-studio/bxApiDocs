<?php

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties;

final class ArrayProperty extends AbstractProperty
{
	private AbstractProperty $arrayItemProperty;

	public function setArrayItemProperty(AbstractProperty $property): self
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
		return [
			...parent::toArray(),
			'items' => $this->arrayItemProperty->toArray(),
		];
	}
}
