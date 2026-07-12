<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Infrastructure\Grid\Project\Row\Assembler\Field;

use Bitrix\Main\Grid\Row\FieldAssembler;

class EfficiencyFieldAssembler extends FieldAssembler
{
	protected function prepareColumn($value): string
	{
		$intValue = (int)$value;

		return '<div class="sonet-ui-grid-percent">' . $intValue . '%</div>';
	}
}
