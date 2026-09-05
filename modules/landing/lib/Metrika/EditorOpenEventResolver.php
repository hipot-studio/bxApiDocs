<?php
declare(strict_types=1);

namespace Bitrix\Landing\Metrika;

/**
 * Decides how the opening of the editor is marked.
 *
 * Sites created by the AI scenario have their own event: the event itself means an AI site,
 * so it carries no type, its sub-sections tell apart the way the user got into the editor,
 * and any failed opening is a portal error - the reason is told apart by the error code.
 * Every other site keeps the common editor event with its own sub-sections and statuses.
 */
class EditorOpenEventResolver
{
	/**
	 * The user came into the editor right after the AI generation.
	 */
	public const SUB_SECTION_FROM_AI_GENERATOR = 'from_ai_generator';

	/**
	 * The user opened the editor from the list of pages.
	 */
	public const SUB_SECTION_FROM_PAGE_LIST = 'from_page_list';

	/**
	 * GET param of the editor address, added by the redirect after the AI generation.
	 */
	public const FROM_GENERATOR_PARAM = 'st_from_generator';

	public const FROM_GENERATOR_PARAM_VALUE = 'Y';

	public static function resolveEvent(bool $isAiSite): Events
	{
		return $isAiSite ? Events::openAiEditor : Events::openEditor;
	}

	public static function resolveAiSubSection(bool $isFromGenerator): string
	{
		return $isFromGenerator
			? self::SUB_SECTION_FROM_AI_GENERATOR
			: self::SUB_SECTION_FROM_PAGE_LIST
		;
	}

	/**
	 * Status of a failed opening, null means the default status of the error.
	 */
	public static function resolveErrorStatus(bool $isAiSite): ?Statuses
	{
		return $isAiSite ? Statuses::ErrorB24 : null;
	}
}
