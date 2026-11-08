<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Dto\Builder;

use Bitrix\BIConnector\Infrastructure\Rest\Access\BuilderFieldAccessProvider;
use Bitrix\Main\Localization\LocalizableMessage;
use Bitrix\Rest\V3\Attribute\FieldAccess;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\Dto\Dto;

/**
 * Chart as GetChartTool answers it.
 */
#[FieldAccess(BuilderFieldAccessProvider::class)]
final class ChartDto extends Dto
{
	#[Title(new LocalizableMessage(
		code: 'BICONNECTOR_REST_BUILDER_CHART_DTO_FIELD_ID_TITLE',
		phraseSrcFile: __FILE__,
	))]
	#[Filterable, Sortable]
	public int $id;

	#[Title(new LocalizableMessage(
		code: 'BICONNECTOR_REST_BUILDER_CHART_DTO_FIELD_NAME_TITLE',
		phraseSrcFile: __FILE__,
	))]
	public string $name;

	#[Title(new LocalizableMessage(
		code: 'BICONNECTOR_REST_BUILDER_CHART_DTO_FIELD_DESCRIPTION_TITLE',
		phraseSrcFile: __FILE__,
	))]
	public string $description;

	#[Title(new LocalizableMessage(
		code: 'BICONNECTOR_REST_BUILDER_CHART_DTO_FIELD_VIZ_TYPE_TITLE',
		phraseSrcFile: __FILE__,
	))]
	public string $vizType;

	#[Title(new LocalizableMessage(
		code: 'BICONNECTOR_REST_BUILDER_CHART_DTO_FIELD_DATASET_ID_TITLE',
		phraseSrcFile: __FILE__,
	))]
	public int $datasetId;

	#[Title(new LocalizableMessage(
		code: 'BICONNECTOR_REST_BUILDER_CHART_DTO_FIELD_DATASOURCE_TYPE_TITLE',
		phraseSrcFile: __FILE__,
	))]
	public string $datasourceType;

	/**
	 * Chart params as GetChartTool answers them: the whitelisted ones, null when the chart has none, the raw
	 * string when Superset keeps JSON that does not parse. The schema has no union type, hence mixed.
	 */
	#[Title(new LocalizableMessage(
		code: 'BICONNECTOR_REST_BUILDER_CHART_DTO_FIELD_PARAMS_TITLE',
		phraseSrcFile: __FILE__,
	))]
	public mixed $params;

	#[Title(new LocalizableMessage(
		code: 'BICONNECTOR_REST_BUILDER_CHART_DTO_FIELD_OWNERS_TITLE',
		phraseSrcFile: __FILE__,
	))]
	public array $owners;

	#[Title(new LocalizableMessage(
		code: 'BICONNECTOR_REST_BUILDER_CHART_DTO_FIELD_DASHBOARDS_TITLE',
		phraseSrcFile: __FILE__,
	))]
	public array $dashboards;
}
