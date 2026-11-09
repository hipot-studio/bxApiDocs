<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Dto\Builder;

use Bitrix\BIConnector\Infrastructure\Rest\Access\AgentSkillFieldAccessProvider;
use Bitrix\Main\Localization\LocalizableMessage;
use Bitrix\Rest\V3\Attribute\FieldAccess;
use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\Dto\Dto;

/**
 * Agent guide as GetGuideOperation answers it: the table of contents (`rules`, `start`, `sections` of `id`,
 * `title`, `bytes`) or one section (`id`, `title`, `text`, `next`, null after the last section). Both carry
 * `revision` and `language`.
 */
#[FieldAccess(AgentSkillFieldAccessProvider::class)]
final class GuideDto extends Dto
{
	#[Title(new LocalizableMessage(
		code: 'BICONNECTOR_REST_BUILDER_GUIDE_DTO_FIELD_REVISION_TITLE',
		phraseSrcFile: __FILE__,
	))]
	public string $revision;

	#[Title(new LocalizableMessage(
		code: 'BICONNECTOR_REST_BUILDER_GUIDE_DTO_FIELD_LANGUAGE_TITLE',
		phraseSrcFile: __FILE__,
	))]
	public string $language;

	#[Title(new LocalizableMessage(
		code: 'BICONNECTOR_REST_BUILDER_GUIDE_DTO_FIELD_RULES_TITLE',
		phraseSrcFile: __FILE__,
	))]
	public ?string $rules;

	#[Title(new LocalizableMessage(
		code: 'BICONNECTOR_REST_BUILDER_GUIDE_DTO_FIELD_START_TITLE',
		phraseSrcFile: __FILE__,
	))]
	public ?string $start;

	#[Title(new LocalizableMessage(
		code: 'BICONNECTOR_REST_BUILDER_GUIDE_DTO_FIELD_SECTIONS_TITLE',
		phraseSrcFile: __FILE__,
	))]
	public ?array $sections;

	#[Title(new LocalizableMessage(
		code: 'BICONNECTOR_REST_BUILDER_GUIDE_DTO_FIELD_ID_TITLE',
		phraseSrcFile: __FILE__,
	))]
	public ?string $id;

	#[Title(new LocalizableMessage(
		code: 'BICONNECTOR_REST_BUILDER_GUIDE_DTO_FIELD_TITLE_TITLE',
		phraseSrcFile: __FILE__,
	))]
	public ?string $title;

	#[Title(new LocalizableMessage(
		code: 'BICONNECTOR_REST_BUILDER_GUIDE_DTO_FIELD_TEXT_TITLE',
		phraseSrcFile: __FILE__,
	))]
	public ?string $text;

	#[Title(new LocalizableMessage(
		code: 'BICONNECTOR_REST_BUILDER_GUIDE_DTO_FIELD_NEXT_TITLE',
		phraseSrcFile: __FILE__,
	))]
	public ?string $next;
}
