<?php

namespace Bitrix\BIConnector\Superset\Grid\Row\Assembler\Field\Dataset;

use Bitrix\BIConnector\ExternalSource\Type;
use Bitrix\BIConnector\Superset\Grid\ExternalSourceRepository;
use Bitrix\BIConnector\Superset\Grid\TableGroupRepository;
use Bitrix\Main\Grid\Row\FieldAssembler;
use Bitrix\Main\Localization\Loc;

class TypeFieldAssembler extends FieldAssembler
{
	protected function prepareColumn($value): ?string
	{
		// A group has no source: its TYPE tells a system group from a custom one, which is not what
		// this column speaks about. The cell is emptied rather than left unanswered: an unanswered cell
		// is filled by the grid with the raw value of the column, which is that very type.
		if (($value['ENTITY_TYPE'] ?? '') === TableGroupRepository::TYPE_GROUP)
		{
			return '';
		}

		$type = $value['TYPE'];

		if (Type::tryFrom($type) === Type::System)
		{
			$typeText = Loc::getMessage('BICONNECTOR_SUPERSET_DATASET_GRID_TYPE_SYSTEM');

			return <<<HTML
				<span class="biconnector-grid-username-cell biconnector-grid-source-cell">
					<span class="biconnector-grid-source-icon ui-icon" style="background-image: url(/bitrix/images/biconnector/database-connections/system.svg);"></span>
					<span class="biconnector-grid-username">{$typeText}</span>
				</span>
				HTML;
		}

		$listSource = ExternalSourceRepository::getStaticSourceList();
		$source = current(array_filter($listSource, static function($source) use ($value) {
			return $source['CODE'] === $value['TYPE'];
		}));
		$nameType = $source['NAME'] ?? strtoupper($value['TYPE']);

		if (Type::tryFrom($type) === Type::Rest)
		{
			$connectorOfSource = ExternalSourceRepository::getRestLogoBySourceId((int)$value['SOURCE']['ID']);
			$avatar = htmlspecialcharsbx($connectorOfSource['LOGO'] ?? '');
		}
		else
		{
			$avatar = "/bitrix/images/biconnector/database-connections/{$type}.svg";
		}

		return <<<HTML
			<span class="biconnector-grid-username-cell biconnector-grid-source-cell">
				<span class="biconnector-grid-source-icon ui-icon" style="background-image: url({$avatar});"></span>
				<span class="biconnector-grid-username">{$nameType}</span>
			</span>
			HTML;
	}

	protected function prepareRow(array $row): array
	{
		if (empty($this->getColumnIds()))
		{
			return $row;
		}

		$row['columns'] ??= [];

		foreach ($this->getColumnIds() as $columnId)
		{
			$value = $row['data'];
			$row['columns'][$columnId] = $this->prepareColumn($value);
		}

		return $row;
	}
}
