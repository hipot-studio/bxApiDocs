<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Grid\SystemHistory\Row\Assembler\Field;

use Bitrix\Main\Grid\Row\FieldAssembler;
use Bitrix\Tasks\V2\Internal\Service\Grid\Trait\EscapedJsonTrait;

class TypeFieldAssembler extends FieldAssembler
{
	use EscapedJsonTrait;

	/**
	 * @param string $value
	 * @return string
	 */
	protected function prepareColumn($value): string
	{
		return <<<HTML
<div data-system-log-type="{$this->toEscapedJson($value)}"></div>
HTML;
	}
}
