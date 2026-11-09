<?php

namespace Bitrix\BiConnector\Settings\Grid\Row\Assembler\Field;

use Bitrix\Main\Grid\Row\FieldAssembler;
use Bitrix\Main\Localization\Loc;

class KeyFieldAssembler extends FieldAssembler
{
	protected function prepareRow(array $row): array
	{
		if (empty($this->getColumnIds()))
		{
			return $row;
		}

		$row['columns'] ??= [];

		foreach ($this->getColumnIds() as $columnId)
		{
			if (!isset($row['data'][$columnId]))
			{
				$row['columns'][$columnId] = null;
				continue;
			}

			$key = '';
			$text = htmlspecialcharsbx((string)Loc::getMessage('BICONNECTOR_SETTINGS_GRID_RIW_ASSEMBLER_KEY_FIELD'));

			if (isset($row['data']['ACCESS_KEY']))
			{
				$key = htmlspecialcharsbx((string)$row['data']['ACCESS_KEY']);
			}

			$result = '
				<button
					type="button"
					class="ui-btn ui-btn-xs ui-btn-light-border ui-btn-round"
					data-bic-focus-role="copy-key"
					onclick="BX.BIConnector.KeysGrid.copyKey(this)"
				>
					' . $text . '
					<input data-key-id="" type="hidden" value="' . $key . '"/>
				</button>
			';

			$row['columns'][$columnId] = $result;
		}

		return $row;
	}
}
