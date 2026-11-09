<?php

namespace Bitrix\BIConnector\Superset\Grid\Row\Assembler\Field\Dataset;

use Bitrix\BIConnector\Access\TableGroup\TableGroupTable;
use Bitrix\BIConnector\ExternalSource\Type;
use Bitrix\BIConnector\Superset\Grid\Row\Assembler\Field\Base\DetailLinkFieldAssembler;
use Bitrix\BIConnector\Superset\Grid\TableGroupRepository;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\UI\Extension;
use Bitrix\Main\Web\Json;

class NameFieldAssembler extends DetailLinkFieldAssembler
{
	protected function prepareColumn($value): string
	{
		$name = (string)($value['NAME'] ?? '');
		$title = htmlspecialcharsbx($name);

		if (($value['ENTITY_TYPE'] ?? '') === TableGroupRepository::TYPE_GROUP)
		{
			return $this->prepareGroupColumn($value, $title);
		}

		if (!empty($value['IS_SYSTEM']))
		{
			// The name of the table goes to the slider through the data attribute, never through the code.
			$onclick = 'BX.BIConnector.DatasetImportV2.Slider'
				. ".open('system', 0, {tableName: this.dataset.bicTableName})"
			;

			return <<<HTML
				<div class="dataset-title-wrapper">
					<button
						type="button"
						class="biconnector-grid-name-link-button"
						data-bic-focus-role="title"
						data-bic-table-name="{$title}"
						onclick="{$onclick}"
					>{$title}</button>
				</div>
			HTML;
		}

		$id = (int)$value['ID'];
		$type = Type::tryFrom((string)($value['TYPE'] ?? ''));

		// A type the product knows is a constant of the code; any other one is read from the data attribute.
		$typeAttribute = '';
		$typeArgument = 'this.dataset.bicType';
		if ($type !== null)
		{
			$typeArgument = "'{$type->value}'";
		}
		else
		{
			$typeAttribute = ' data-bic-type="' . htmlspecialcharsbx((string)($value['TYPE'] ?? '')) . '"';
		}

		return <<<HTML
			<div class="dataset-title-wrapper">
				<button
					type="button"
					class="biconnector-grid-name-link-button"
					data-bic-focus-role="title"{$typeAttribute}
					onclick="BX.BIConnector.DatasetImportV2.Slider.open({$typeArgument}, {$id})"
				>{$title}</button>
			</div>
		HTML;
	}

	/**
	 * A row of a group: the folder of the reports section, told apart by its own set of classes.
	 *
	 * The click carries the group the way the reports grid carries it: the level of the section is a
	 * condition of the filter, and the grid puts that condition on by itself. Rows of groups are never
	 * shown inside a group, so the button is never pressed.
	 */
	private function prepareGroupColumn(array $value, string $title): string
	{
		Extension::load('ui.icons.disk');

		$groupId = (int)$value['ID'];
		$tableCount = (int)($value['COUNT_TABLES'] ?? 0);
		$subtitle = htmlspecialcharsbx(
			(string)Loc::getMessagePlural('BICONNECTOR_TABLE_GROUP_ROW_SUBTITLE', $tableCount, [
				'#COUNT#' => $tableCount,
			]),
		);

		$iconClass =
			($value['TYPE'] ?? '') === TableGroupTable::GROUP_TYPE_SYSTEM
				? 'ui-icon-file-air-folder-24'
				: 'ui-icon-file-air-folder-person'
		;

		$payload = htmlspecialcharsbx(Json::encode([
			'ID' => $groupId,
			'TITLE' => (string)($value['NAME'] ?? ''),
		]));
		$onclick = 'BX.BIConnector.ExternalDatasetManager.Instance'
			. '.handleGroupTitleClick(JSON.parse(this.dataset.bicPayload))'
		;

		return <<<HTML
			<div class="bi-table-group-name">
				<div class="ui-icon {$iconClass} bi-table-group-icon"><i style="margin-left: 0;"></i></div>
				<div>
					<button
						type="button"
						class="bi-table-group-name-link biconnector-grid-name-link-button"
						data-testid="biconnector-table-group-row-name"
						data-bic-focus-role="group-enter"
						aria-pressed="false"
						data-bic-payload="{$payload}"
						onclick="{$onclick}"
					>{$title}</button>
					<div class="bi-table-group-name-subtitle">{$subtitle}</div>
				</div>
			</div>
		HTML;
	}
}
