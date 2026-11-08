<?php

namespace Bitrix\BIConnector\Superset\Grid\Row\Assembler\Field\Dataset;

use Bitrix\BIConnector\Access\TableGroup\TableGroupTable;
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
			$jsName = htmlspecialcharsbx(\CUtil::JSEscape($name));

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

	/**
	 * A row of a group: the folder of the reports section, told apart by its own set of classes.
	 *
	 * The click carries the group the way the reports grid carries it: the level of the section is a
	 * condition of the filter, and the grid puts that condition on by itself.
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

		// The quotes of the name are hex-escaped by the encoder, so the value stands in an attribute
		// quoted with an apostrophe the way the reports grid puts it there.
		$eventGroup = Json::encode([
			'ID' => $groupId,
			'TITLE' => (string)($value['NAME'] ?? ''),
		]);

		return <<<HTML
			<div class="bi-table-group-name">
				<div class="ui-icon {$iconClass} bi-table-group-icon"><i style="margin-left: 0;"></i></div>
				<div>
					<a
						class="bi-table-group-name-link"
						data-testid="biconnector-table-group-row-name"
						onclick='BX.BIConnector.ExternalDatasetManager.Instance.handleGroupTitleClick({$eventGroup})'
					>{$title}</a>
					<div class="bi-table-group-name-subtitle">{$subtitle}</div>
				</div>
			</div>
		HTML;
	}
}
