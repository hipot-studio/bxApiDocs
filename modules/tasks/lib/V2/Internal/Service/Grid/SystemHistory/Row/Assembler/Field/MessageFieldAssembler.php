<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Grid\SystemHistory\Row\Assembler\Field;

use Bitrix\Main\Grid\Row\FieldAssembler;
use Bitrix\Tasks\V2\Internal\Service\Grid\Trait\EscapedJsonTrait;

class MessageFieldAssembler extends FieldAssembler
{
	use EscapedJsonTrait;

	/**
	 * @param array $value
	 * @return string
	 */
	protected function prepareColumn($value): string
	{
		return <<<HTML
<div data-system-log-message="{$this->toEscapedJson($value)}"></div>
HTML;
	}
}
