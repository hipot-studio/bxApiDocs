<?php

namespace Bitrix\BIConnector\Superset\Grid\Row\Assembler\Field\Source;

use Bitrix\Main\Grid\Row\FieldAssembler;
use Bitrix\Main\Localization\Loc;
use Bitrix\UI\Public\System\Chip;

class ActiveFieldAssembler extends FieldAssembler
{
	protected function prepareColumn($value): string
	{
		$isActive = $value === 'Y';

		return Chip\Chip::create([
			'text' => Loc::getMessage(
					$isActive
						? 'BICONNECTOR_SUPERSET_SOURCE_GRID_ACTIVE_YES'
						: 'BICONNECTOR_SUPERSET_SOURCE_GRID_ACTIVE_NO'
				) ?? '',
			'design' => $isActive
				? Chip\Design::TINTED_SUCCESS
				: Chip\Design::TINTED_NO_ACCENT,
			'size' => Chip\Size::SM,
		])->render();
	}
}
