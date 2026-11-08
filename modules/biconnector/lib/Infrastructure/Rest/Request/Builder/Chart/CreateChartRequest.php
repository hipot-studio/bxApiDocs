<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Request\Builder\Chart;

use Bitrix\BIConnector\Internal\Integration\AiAssistant\Chart\ChartParamsBuilder;
use Bitrix\Main\Validation\Rule\InArray;
use Bitrix\Main\Validation\Rule\Length;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Rest\V3\Interaction\Request\Request;

/**
 * Arguments of biconnector.builder.chart.add, named as create_chart names them.
 */
final class CreateChartRequest extends Request
{
	/** Chart display name. */
	#[Length(min: 1)]
	public string $name;

	/** Source dataset id from biconnector.builder.dataset.list. */
	#[PositiveNumber]
	public int $datasetId;

	/** Visualization type alias. */
	#[InArray(ChartParamsBuilder::VIZ_TYPES, strict: true)]
	public string $vizType;

	/** Date/datetime column of the dataset the chart binds to the dashboard date filter with. */
	public string $temporalColumn;

	/** Metric definitions, each with `column`, `aggregate` and an optional `label`. */
	public array $metrics = [];

	/** Dimension column names (group-by). */
	public array $dimensions = [];

	/** X-axis column of bar/line/scatter charts; blank takes the first dimension. */
	public string $xAxis = '';

	/** Filter expressions, each with `column`, `operator` and `value`. */
	public array $filters = [];

	/** Chart description shown in the UI. */
	public string $description = '';

	/** Row limit of the underlying query; omitted takes the default of the visualization type. */
	public ?int $rowLimit = null;

	/**
	 * The configuration the operation takes, with the keys of the tool arguments.
	 */
	public function toConfig(): array
	{
		return [
			'name' => $this->name,
			'datasetId' => $this->datasetId,
			'vizType' => $this->vizType,
			'temporalColumn' => $this->temporalColumn,
			'metrics' => $this->metrics,
			'dimensions' => $this->dimensions,
			'xAxis' => $this->xAxis,
			'filters' => $this->filters,
			'description' => $this->description,
			'rowLimit' => $this->rowLimit,
		];
	}
}
