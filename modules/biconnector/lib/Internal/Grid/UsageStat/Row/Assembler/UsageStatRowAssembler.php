<?php

namespace Bitrix\BIConnector\Internal\Grid\UsageStat\Row\Assembler;

use Bitrix\BIConnector\Internal\Grid\UsageStat\Settings\UsageStatSettings;
use Bitrix\Main\Grid\Row\RowAssembler;

final class UsageStatRowAssembler extends RowAssembler
{
	private ?UsageStatSettings $settings;

	public function __construct(
		array $visibleColumnIds,
		?UsageStatSettings $settings = null,
	)
	{
		$this->settings = $settings;

		parent::__construct($visibleColumnIds);
	}

	protected function prepareFieldAssemblers(): array
	{
		return [
			new Field\TimestampFieldAssembler(['TIMESTAMP_X']),
			new Field\KeyIdFieldAssembler(['KEY_ID'], $this->settings),
			new Field\ServiceIdFieldAssembler(['SERVICE_ID']),
			new Field\SourceIdFieldAssembler(['SOURCE_ID']),
			new Field\RowNumFieldAssembler(['ROW_NUM']),
			new Field\DataSizeFieldAssembler(['DATA_SIZE']),
			new Field\RealTimeFieldAssembler(['REAL_TIME']),
			new Field\LoadLevelFieldAssembler(['LOAD_LEVEL']),
			new Field\FieldsFieldAssembler(['FIELDS']),
			new Field\DashboardFieldAssembler(['EXTERNAL_DASHBOARD']),
			new Field\ChartFieldAssembler(['EXTERNAL_CHART']),
			new Field\DatasetFieldAssembler(['EXTERNAL_DATASET']),
			new Field\DatasetTypeFieldAssembler(['DATASET_TYPE']),
		];
	}
}
