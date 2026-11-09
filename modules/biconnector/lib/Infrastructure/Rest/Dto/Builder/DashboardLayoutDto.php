<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Dto\Builder;

use Bitrix\BIConnector\Infrastructure\Rest\Access\AgentSkillFieldAccessProvider;
use Bitrix\Main\Localization\LocalizableMessage;
use Bitrix\Rest\V3\Attribute\FieldAccess;
use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\Dto\Dto;

#[FieldAccess(AgentSkillFieldAccessProvider::class)]
final class DashboardLayoutDto extends Dto
{
	#[Title(new LocalizableMessage(
		code: 'BICONNECTOR_REST_BUILDER_DASHBOARD_LAYOUT_DTO_FIELD_ID_TITLE',
		phraseSrcFile: __FILE__,
	))]
	public int $id;

	#[Title(new LocalizableMessage(
		code: 'BICONNECTOR_REST_BUILDER_DASHBOARD_LAYOUT_DTO_FIELD_PUBLISHED_TITLE',
		phraseSrcFile: __FILE__,
	))]
	public bool $published;

	#[Title(new LocalizableMessage(
		code: 'BICONNECTOR_REST_BUILDER_DASHBOARD_LAYOUT_DTO_FIELD_VERSION_TITLE',
		phraseSrcFile: __FILE__,
	))]
	public string $version;

	#[Title(new LocalizableMessage(
		code: 'BICONNECTOR_REST_BUILDER_DASHBOARD_LAYOUT_DTO_FIELD_LAYOUT_TITLE',
		phraseSrcFile: __FILE__,
	))]
	public array $layout;

	#[Title(new LocalizableMessage(
		code: 'BICONNECTOR_REST_BUILDER_DASHBOARD_LAYOUT_DTO_FIELD_WARNINGS_TITLE',
		phraseSrcFile: __FILE__,
	))]
	public array $warnings;

	#[Title(new LocalizableMessage(
		code: 'BICONNECTOR_REST_BUILDER_DASHBOARD_LAYOUT_DTO_FIELD_DRYRUN_TITLE',
		phraseSrcFile: __FILE__,
	))]
	public ?bool $dryRun;

	#[Title(new LocalizableMessage(
		code: 'BICONNECTOR_REST_BUILDER_DASHBOARD_LAYOUT_DTO_FIELD_CHANGED_TITLE',
		phraseSrcFile: __FILE__,
	))]
	public ?bool $changed;

	#[Title(new LocalizableMessage(
		code: 'BICONNECTOR_REST_BUILDER_DASHBOARD_LAYOUT_DTO_FIELD_BASEVERSION_TITLE',
		phraseSrcFile: __FILE__,
	))]
	public ?string $baseVersion;

	#[Title(new LocalizableMessage(
		code: 'BICONNECTOR_REST_BUILDER_DASHBOARD_LAYOUT_DTO_FIELD_SUMMARY_TITLE',
		phraseSrcFile: __FILE__,
	))]
	public ?array $summary;
}
