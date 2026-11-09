<?php

namespace Bitrix\BIConnector\Superset\Grid\Row\Assembler\Field\Dataset;

use Bitrix\Main\Grid\Row\FieldAssembler;
use Bitrix\Main\Web\Json;

class SourceFieldAssembler extends FieldAssembler
{
	protected function prepareColumn($value): ?string
	{
		if (!$value)
		{
			return null;
		}

		$id = (int)$value['ID'];
		$sourceTitle = (string)($value['TITLE'] ?? '');
		$title = htmlspecialcharsbx($sourceTitle);

		$ormFilter = $this->getSettings()->getOrmFilter();
		$isFiltered = isset($ormFilter['SOURCE.ID']) && in_array($id, $ormFilter['SOURCE.ID']);

		$payload = htmlspecialcharsbx(Json::encode([
			'ID' => $id,
			'TITLE' => $sourceTitle,
			'IS_FILTERED' => $isFiltered,
		]));
		$isPressed = $isFiltered ? 'true' : 'false';
		$onclick = 'BX.BIConnector.ExternalDatasetManager.Instance'
			. '.handleSourceClick(JSON.parse(this.dataset.bicPayload))'
		;

		$selector = 'biconnector-grid-username-cell';
		$closeIcon = '';
		if ($isFiltered)
		{
			$selector .= ' biconnector-grid-username-cell-active';
			$closeIcon = '<span class="biconnector-grid-filter-close ui-icon-set --cross-20"'
				. ' aria-hidden="true"></span>'
			;
		}

		return <<<HTML
				<button
					type="button"
					class="$selector"
					data-bic-focus-role="source-filter"
					aria-pressed="{$isPressed}"
					data-bic-payload="{$payload}"
					onclick="{$onclick}"
				>
					<span class="biconnector-grid-username">$title</span>
					$closeIcon
				</button>
			HTML;
	}
}
