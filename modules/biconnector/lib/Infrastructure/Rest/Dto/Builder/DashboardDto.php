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
 * Report as the dashboard tools of MCP answer it: the item of ListDashboardsTool together with
 * the publication state CreateDashboardTool and UpdateDashboardMetadataTool report.
 */
#[FieldAccess(BuilderFieldAccessProvider::class)]
final class DashboardDto extends Dto
{
	#[Title(new LocalizableMessage(
		code: 'BICONNECTOR_REST_BUILDER_DASHBOARD_DTO_FIELD_ID_TITLE',
		phraseSrcFile: __FILE__,
	))]
	#[Filterable, Sortable]
	public int $id;

	#[Title(new LocalizableMessage(
		code: 'BICONNECTOR_REST_BUILDER_DASHBOARD_DTO_FIELD_TITLE_TITLE',
		phraseSrcFile: __FILE__,
	))]
	#[Filterable, Sortable]
	public string $title;

	#[Title(new LocalizableMessage(
		code: 'BICONNECTOR_REST_BUILDER_DASHBOARD_DTO_FIELD_TYPE_TITLE',
		phraseSrcFile: __FILE__,
	))]
	public ?string $type;

	#[Title(new LocalizableMessage(
		code: 'BICONNECTOR_REST_BUILDER_DASHBOARD_DTO_FIELD_DESCRIPTION_TITLE',
		phraseSrcFile: __FILE__,
	))]
	public ?string $description;

	#[Title(new LocalizableMessage(
		code: 'BICONNECTOR_REST_BUILDER_DASHBOARD_DTO_FIELD_REQUIRES_SCOPE_TITLE',
		phraseSrcFile: __FILE__,
	))]
	public bool $requiresScope;

	#[Title(new LocalizableMessage(
		code: 'BICONNECTOR_REST_BUILDER_DASHBOARD_DTO_FIELD_PUBLISHED_TITLE',
		phraseSrcFile: __FILE__,
	))]
	public bool $published;
}
