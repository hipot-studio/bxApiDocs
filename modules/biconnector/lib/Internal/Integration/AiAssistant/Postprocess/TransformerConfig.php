<?php

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\Postprocess;

class TransformerConfig
{
	public bool $normalizeDates = true;
	public bool $roundNumbers = true;
	public bool $transposeDictData = true;
	public bool $removeEmptyFields = true;

	public bool $computeStats = true;
	public bool $generateColumnDescriptions = true;
	public bool $includeMeta = true;

	public bool $preserveChartIds = false;
	public bool $includeRows = true;

	public int $defaultDecimalPlaces = 2;
	public int $fractionDecimalPlaces = 4;

	public array $columnDescriptionsOverride = [];
	public array $metaOverrides = [];
	public array $appliedFilters = [];
}
