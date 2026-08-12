<?php

namespace Bitrix\BIConnector\Superset\Grid\Row\Assembler\Field\Dataset;

use Bitrix\BIConnector\Superset\Grid\Row\Assembler\Field\Base\DetailLinkFieldAssembler;

class NameFieldAssembler extends DetailLinkFieldAssembler
{
	protected function prepareColumn($value): string
	{
		$title = htmlspecialcharsbx($value['NAME']);

		if (!empty($value['IS_SYSTEM']))
		{
			$jsName = htmlspecialcharsbx(\CUtil::JSEscape($value['NAME']));

			return <<<HTML
				<div class="dataset-title-wrapper">
					<a
						style="cursor: pointer"
						onclick="BX.BIConnector.DatasetImportV2.Slider.open('system', 0, {tableName: '{$jsName}'})"
					>{$title}</a>
				</div>
			HTML;
		}

		$id = (int)$value['ID'];
		$type = $value['TYPE'];

		$link = "
				<a
					style='cursor: pointer'
					onclick='BX.BIConnector.DatasetImportV2.Slider.open(\"$type\", $id)'
				>{$title}</a>
			";

		return <<<HTML
			<div class="dataset-title-wrapper">
				{$link}
			</div>
		HTML;
	}
}
