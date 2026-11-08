<?php

namespace Bitrix\BIConnector\Superset\Grid\Row\Assembler;

use Bitrix\BIConnector\Superset\Grid\Settings\ExternalDatasetSettings;
use Bitrix\BIConnector\Superset\Grid\TableGroupRepository;
use Bitrix\Main\Grid\Row\RowAssembler;
use Bitrix\Main\Grid\Row\Assembler\Field\StringFieldAssembler;

class ExternalDatasetRowAssembler extends RowAssembler
{
	private ?ExternalDatasetSettings $settings;

	public function __construct(array $visibleColumnIds, ?ExternalDatasetSettings $settings = null)
	{
		$this->settings = $settings;

		parent::__construct($visibleColumnIds);
	}

	protected function prepareFieldAssemblers(): array
	{
		return [
			new Field\Dataset\IdFieldAssembler(
				[
					'ID',
				]),
			new Field\Dataset\TypeFieldAssembler(
				[
					'TYPE',
				],
			),
			new Field\Dataset\NameFieldAssembler(
				[
					'NAME',
				],
			),
			new Field\Dataset\SourceFieldAssembler(
				[
					'SOURCE',
				],
				$this->settings,
			),
			new StringFieldAssembler(
				[
					'VERBOSE_NAME',
					'DESCRIPTION',
				],
			),
			new Field\Base\DateFieldAssembler(
				[
					'DATE_CREATE',
				],
				timeFormat: Field\Base\DateFieldAssembler::TIME_FORMAT_SHORT,
			),
			new Field\Base\DateFieldAssembler(
				[
					'DATE_UPDATE',
				],
				timeFormat: Field\Base\DateFieldAssembler::TIME_FORMAT_SHORT,
			),
			new Field\Dataset\CreatedByFieldAssembler(
				[
					'CREATED_BY_ID',
				],
				$this->settings,
			),
			new Field\Dataset\UpdatedByFieldAssembler(
				[
					'UPDATED_BY_ID',
				],
				$this->settings,
			),
		];
	}

	/**
	 * @param array $rowsList
	 *
	 * @return array[]
	 */
	public function prepareRows(array $rowsList): array
	{
		foreach ($rowsList as &$row)
		{
			if (($row['data']['ENTITY_TYPE'] ?? '') !== TableGroupRepository::TYPE_GROUP)
			{
				continue;
			}

			// Groups and custom tables are numbered apart, and the grid keys the row and its menu by this id.
			$groupId = (int)$row['data']['ID'];
			$row['id'] = $groupId . '_' . TableGroupRepository::TYPE_GROUP;
			$row['attrs'] = ($row['attrs'] ?? []) + ['data-group-id' => $groupId];
		}
		unset($row);

		return parent::prepareRows($rowsList);
	}
}
