<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties;

final class IntegerProperty extends AbstractProperty
{
	use Validation\NumericValidationTrait;

	public function getType(): string
	{
		return 'integer';
	}

	public function toArray(): array
	{
		return [
			...parent::toArray(),
			...$this->getNumericValidationSchema(),
		];
	}
}
