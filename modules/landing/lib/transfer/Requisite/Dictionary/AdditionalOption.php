<?php
declare(strict_types=1);

namespace Bitrix\Landing\Transfer\Requisite\Dictionary;

/**
 * DTO for additional context fields
 * @extends Blank<AdditionalOptionPart>
 * @method AdditionalOption set(AdditionalOptionPart $name, mixed $value)
 * @method mixed get(AdditionalOptionPart $name)
 */
class AdditionalOption extends Blank
{
	/**
	 * @return class-string<AdditionalOptionPart>
	 */
	public function getEnumPartClass(): string
	{
		return AdditionalOptionPart::class;
	}
}
