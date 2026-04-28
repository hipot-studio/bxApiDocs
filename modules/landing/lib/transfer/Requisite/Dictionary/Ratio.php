<?php
declare(strict_types=1);

namespace Bitrix\Landing\Transfer\Requisite\Dictionary;

/**
 * DTO for ratio data, to use in context
 * @extends Blank<RatioPart>
 * @method Ratio set(RatioPart $name, mixed $value)
 * @method mixed get(RatioPart $name)
 */
class Ratio extends Blank
{
	/**
	 * @return class-string<RatioPart>
	 */
	public function getEnumPartClass(): string
	{
		return RatioPart::class;
	}
}
