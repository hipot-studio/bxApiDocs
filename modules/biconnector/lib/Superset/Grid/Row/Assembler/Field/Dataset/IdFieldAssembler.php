<?php

namespace Bitrix\BIConnector\Superset\Grid\Row\Assembler\Field\Dataset;

use Bitrix\BIConnector\Superset\Grid\Row\Assembler\Field\Base\DetailLinkFieldAssembler;
use Bitrix\BIConnector\Superset\Grid\TableGroupRepository;

class IdFieldAssembler extends DetailLinkFieldAssembler
{
	protected function prepareColumn($value): string
	{
		// The slider behind this column knows tables alone, so the identifier of a group stays a text:
		// a link of it would open a table that does not exist.
		if (($value['ENTITY_TYPE'] ?? '') === TableGroupRepository::TYPE_GROUP)
		{
			return (string)(int)$value['ID'];
		}

		if (!empty($value['IS_SYSTEM']))
		{
			return '';
		}

		$id = (int)$value['ID'];
		$type = $value['TYPE'];

		$link = "
				<a
					style='cursor: pointer'
					onclick='BX.BIConnector.DatasetImportV2.Slider.open(\"$type\", $id)'
				>
					{$id}
				</a>
			";

		return $link;
	}
}
