<?php

namespace Bitrix\BIConnector\Superset\Grid\Row\Assembler\Field\Base;

use Bitrix\Main\Localization\Loc;

trait FieldAssemblerPencilTrait
{
	private function addPencil(
		string $fieldName,
		string $escapedValue,
		int|string $rowId,
		string $fieldLabel,
		?string $emptyLabel = null,
	): string
	{
		$fieldName = \CUtil::JSEscape($fieldName);
		$rowId = \CUtil::JSEscape((string)$rowId);

		$ariaTemplate = htmlspecialcharsbx((string)Loc::getMessage('BICONNECTOR_GRID_PENCIL_EDIT_ARIA'));
		$ariaEmpty = $emptyLabel === null
			? str_replace('#VALUE#', '', $ariaTemplate)
			: htmlspecialcharsbx($emptyLabel)
		;
		$ariaLabel = trim($escapedValue) === ''
			? $ariaEmpty
			: str_replace('#VALUE#', $escapedValue, $ariaTemplate)
		;
		$fieldLabel = htmlspecialcharsbx($fieldLabel);

		return <<<HTML
			<div class="editable-column-wrapper">
				<div class="editable-column-wrapper__item editable-column-preview">
					<span class="editable-column-content">{$escapedValue}</span>
					<div class="editable-column-buttons">
						<button
							type="button"
							class="editable-column-pencil"
							data-bic-focus-role="pencil"
							aria-label="{$ariaLabel}"
							data-bic-aria-template="{$ariaTemplate}"
							data-bic-aria-empty="{$ariaEmpty}"
							data-bic-field-label="{$fieldLabel}"
							onclick="event.stopPropagation(); BX.BIConnector.Grid.EditableColumnManager.editCell('{$fieldName}', '{$rowId}')"
						>
							<i
								class="ui-icon-set --pencil-60"
								aria-hidden="true"
								style="--ui-icon-set__icon-size: 21px; --ui-icon-set__icon-color: none"
							></i>
						</button>
					</div>
				</div>
			</div>
		HTML;
	}
}
