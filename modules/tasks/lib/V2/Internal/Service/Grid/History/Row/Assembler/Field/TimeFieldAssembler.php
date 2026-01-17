<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Grid\History\Row\Assembler\Field;

use Bitrix\Main\Grid\Row\FieldAssembler;

class TimeFieldAssembler extends FieldAssembler
{
	/**
	 * @param string $value
	 * @return string
	 */
	protected function prepareColumn($value): string
	{
		$value = htmlspecialcharsbx($value);

		return <<<HTML
<div data-time="$value"></div>
HTML;
	}
}
