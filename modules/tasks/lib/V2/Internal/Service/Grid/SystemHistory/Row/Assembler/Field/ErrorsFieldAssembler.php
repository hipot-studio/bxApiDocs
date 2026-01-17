<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Grid\SystemHistory\Row\Assembler\Field;

use Bitrix\Main\Grid\Row\FieldAssembler;
use Bitrix\Main\Web\Json;
use Bitrix\Tasks\V2\Internal\Service\Grid\Trait\EscapedJsonTrait;

class ErrorsFieldAssembler extends FieldAssembler
{
	use EscapedJsonTrait;

	/**
	 * @param array $value
	 * @return string
	 */
	protected function prepareColumn($value): string
	{
		return <<<HTML
<div data-system-log-errors="{$this->toEscapedJson($value)}"></div>
HTML;
	}
}
