<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties;

final class StringProperty extends AbstractProperty
{
	use Validation\MaxLengthValidationTrait;
	use Validation\MinLengthValidationTrait;
	use Validation\PatternValidationTrait;

	public function getType(): string
	{
		return 'string';
	}

	public function toArray(): array
	{
		return [
			...parent::toArray(),
			...$this->getMinLengthValidationSchema(),
			...$this->getMaxLengthValidationSchema(),
			...$this->getPatternValidationSchema(),
		];
	}
}
