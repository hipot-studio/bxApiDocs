<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Public\Provider\UsageStat\Params;

use Bitrix\Main\Provider\Params\SelectInterface;

final class UsageStatSelect implements SelectInterface
{
	public function __construct(
		public array $select = [],
	)
	{
	}

	public function prepareSelect(): array
	{
		$select = [];

		if (in_array('ID', $this->select, true))
		{
			$select[] = 'ID';
		}

		if (in_array('TIMESTAMP_X', $this->select, true))
		{
			$select[] = 'TIMESTAMP_X';
		}

		if (in_array('FILTERS', $this->select, true))
		{
			$select[] = 'FILTERS';
		}

		if (in_array('FIELDS', $this->select, true))
		{
			$select[] = 'FIELDS';
		}

		if (in_array('SOURCE_ID', $this->select, true))
		{
			$select[] = 'SOURCE_ID';
		}

		if (in_array('ROW_NUM', $this->select, true))
		{
			$select[] = 'ROW_NUM';
		}

		if (in_array('DATA_SIZE', $this->select, true))
		{
			$select[] = 'DATA_SIZE';
		}

		if (in_array('REAL_TIME', $this->select, true))
		{
			$select[] = 'REAL_TIME';
		}

		if (in_array('LOAD_LEVEL', $this->select, true))
		{
			$select[] = 'REAL_TIME';
			$select[] = 'FIELDS';
			$select[] = 'FILTERS';
			$select[] = 'INPUT';
			$select[] = 'ROW_NUM';
			$select[] = 'DATA_SIZE';
			$select[] = 'IS_OVER_LIMIT';
			$select[] = 'SERVICE_ID';
			$select[] = 'SOURCE_ID';
		}

		if (in_array('EXTERNAL_DASHBOARD', $this->select, true))
		{
			$select[] = 'EXTERNAL_DASHBOARD_ID';
			$select[] = 'EXTERNAL_DASHBOARD_NAME';
			$select[] = 'SOURCE';
		}

		if (in_array('EXTERNAL_CHART', $this->select, true))
		{
			$select[] = 'EXTERNAL_CHART_ID';
			$select[] = 'EXTERNAL_CHART_NAME';
			$select[] = 'SOURCE';
		}

		if (in_array('EXTERNAL_DATASET', $this->select, true))
		{
			$select[] = 'EXTERNAL_DATASET_ID';
			$select[] = 'EXTERNAL_DATASET_NAME';
			$select[] = 'SOURCE';
		}

		if (in_array('DATASET_TYPE', $this->select, true))
		{
			$select[] = 'SOURCE_ID';
		}

		if (in_array('KEY_ID', $this->select, true))
		{
			$select[] = 'KEY_ID';
		}

		return array_unique($select);
	}
}
