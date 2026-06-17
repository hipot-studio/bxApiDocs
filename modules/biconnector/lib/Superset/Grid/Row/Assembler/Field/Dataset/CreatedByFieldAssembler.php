<?php

namespace Bitrix\BIConnector\Superset\Grid\Row\Assembler\Field\Dataset;

use Bitrix\BIConnector\Superset\Grid\Row\Assembler\Field\Base\UserFieldAssembler;
use Bitrix\Main\Localization\Loc;

class CreatedByFieldAssembler extends UserFieldAssembler
{
	protected function prepareColumn($value): string
	{
		if (!empty($value['IS_SYSTEM']))
		{
			$authorText = Loc::getMessage('BICONNECTOR_SUPERSET_DATASET_GRID_CREATED_BY_SYSTEM');

			return <<<HTML
				<span class="biconnector-grid-market-cell">
					<img src="/bitrix/images/biconnector/database-connections/system.png" width="24" height="24" alt="{$authorText}">
					<span class="biconnector-grid-username">{$authorText}</span>
				</span>
			HTML;
		}

		return $this->prepareColumnWithParams(
			$value,
			'CREATED_BY_ID',
			'BX.BIConnector.ExternalDatasetManager.Instance.handleCreatedByClick',
		);
	}
}
