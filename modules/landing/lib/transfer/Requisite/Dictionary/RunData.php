<?php
declare(strict_types=1);

namespace Bitrix\Landing\Transfer\Requisite\Dictionary;

/**
 * DTO for custom data, is need to be saved for one run
 * @extends Blank<RunDataPart>
 * @method Ratio set(RunDataPart $name, mixed $value)
 * @method mixed get(RunDataPart $name)
 */
class RunData extends Blank
{
	/**
	 * @return class-string<RunDataPart>
	 */
	public function getEnumPartClass(): string
	{
		return RunDataPart::class;
	}
}
