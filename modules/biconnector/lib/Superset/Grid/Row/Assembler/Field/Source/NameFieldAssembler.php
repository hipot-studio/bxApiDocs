<?php

namespace Bitrix\BIConnector\Superset\Grid\Row\Assembler\Field\Source;

use Bitrix\BIConnector\Superset\Grid\Row\Assembler\Field\Base\DetailLinkFieldAssembler;

class NameFieldAssembler extends DetailLinkFieldAssembler
{
	protected function prepareColumn($value): string
	{
		$id = (int)($value['ID'] ?? 0);
		$title = htmlspecialcharsbx((string)($value['TITLE'] ?? ''));
		// The module reaches the handler through the data attribute, never through the code.
		$moduleId = htmlspecialcharsbx((string)($value['MODULE'] ?? ''));
		$onclick = 'BX.BIConnector.ExternalSourceManager.Instance'
			. ".openSourceDetail({$id}, this.dataset.bicModule)"
		;

		return <<<HTML
			<div class="source-title-wrapper">
				<button
					type="button"
					class="biconnector-grid-name-link-button"
					data-bic-focus-role="title"
					data-bic-entity-id="{$id}"
					data-bic-module="{$moduleId}"
					onclick="{$onclick}"
				>{$title}</button>
			</div>
		HTML;
	}
}
