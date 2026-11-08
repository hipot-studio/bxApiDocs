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
 * Dataset as GetDatasetTool answers it.
 */
#[FieldAccess(BuilderFieldAccessProvider::class)]
final class DatasetDto extends Dto
{
	#[Title(new LocalizableMessage(
		code: 'BICONNECTOR_REST_BUILDER_DATASET_DTO_FIELD_ID_TITLE',
		phraseSrcFile: __FILE__,
	))]
	#[Filterable, Sortable]
	public int $id;

	#[Title(new LocalizableMessage(
		code: 'BICONNECTOR_REST_BUILDER_DATASET_DTO_FIELD_TABLE_NAME_TITLE',
		phraseSrcFile: __FILE__,
	))]
	public string $tableName;

	#[Title(new LocalizableMessage(
		code: 'BICONNECTOR_REST_BUILDER_DATASET_DTO_FIELD_DESCRIPTION_TITLE',
		phraseSrcFile: __FILE__,
	))]
	public string $description;

	#[Title(new LocalizableMessage(
		code: 'BICONNECTOR_REST_BUILDER_DATASET_DTO_FIELD_IS_VIRTUAL_TITLE',
		phraseSrcFile: __FILE__,
	))]
	public bool $isVirtual;

	#[Title(new LocalizableMessage(
		code: 'BICONNECTOR_REST_BUILDER_DATASET_DTO_FIELD_SQL_TITLE',
		phraseSrcFile: __FILE__,
	))]
	public string $sql;

	#[Title(new LocalizableMessage(
		code: 'BICONNECTOR_REST_BUILDER_DATASET_DTO_FIELD_COLUMNS_TITLE',
		phraseSrcFile: __FILE__,
	))]
	public array $columns;
}
