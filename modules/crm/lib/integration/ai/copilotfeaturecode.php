<?php

namespace Bitrix\Crm\Integration\AI;

/**
 * Stable feature codes that crm copilot entry points pass next to the context ID.
 *
 * @internal
 */
final class CopilotFeatureCode
{
	public const TIMELINE_COMMENT_EDITOR = 'crm_timeline_comment_editor';
	public const TIMELINE_TODO_EDITOR_ADD_ITEM = 'crm_timeline_todo_editor_add_item';
	public const TIMELINE_TODO_EDITOR_UPDATE_ITEM = 'crm_timeline_todo_editor_update_item';
	public const CALL_ASSESSMENT_SETTINGS_PROMPT = 'crm_call_assessment_settings_prompt';
	public const REPEAT_SALE_SEGMENT_PROMPT = 'crm_repeat_sale_segment_prompt';
	public const USER_FIELD_RICH_TEXT = 'uf_richtext';

	private const MAX_LENGTH = 64;
	private const OUT_OF_ALPHABET_PATTERN = '/[^A-Za-z0-9_-]/';

	private function __construct()
	{
	}

	/**
	 * Builds the feature code of a text field in the entity details editor: crm_editor-{entity}-{field}.
	 *
	 * @param int $entityTypeId Entity type ID.
	 * @param string $fieldName Field name.
	 * @return string|null Null if the entity type has no name.
	 */
	public static function buildEditorFieldCode(int $entityTypeId, string $fieldName): ?string
	{
		$entityName = self::getEntityName($entityTypeId);
		if ($entityName === null)
		{
			return null;
		}

		return self::normalize('crm_editor-' . $entityName . '-' . strtolower($fieldName));
	}

	/**
	 * Builds the feature code of a comment editor in the crm.entity.editor template:
	 * crm_details_comment_editor_{entity}_{field}.
	 *
	 * @param int $entityTypeId Entity type ID.
	 * @param string $fieldName Field name.
	 * @return string|null Null if the entity type has no name.
	 */
	public static function buildDetailsCommentEditorCode(int $entityTypeId, string $fieldName): ?string
	{
		$entityName = self::getEntityName($entityTypeId);
		if ($entityName === null)
		{
			return null;
		}

		return self::normalize('crm_details_comment_editor_' . $entityName . '_' . strtolower($fieldName));
	}

	private static function getEntityName(int $entityTypeId): ?string
	{
		if (\CCrmOwnerType::isPossibleDynamicTypeId($entityTypeId))
		{
			return 'dynamic';
		}

		$entityName = strtolower(\CCrmOwnerType::ResolveName($entityTypeId));

		return $entityName === '' ? null : $entityName;
	}

	private static function normalize(string $code): string
	{
		// Bytewise without the u modifier: the limit counts bytes, and the cut may split a multibyte character.
		return (string)preg_replace(self::OUT_OF_ALPHABET_PATTERN, '_', substr($code, 0, self::MAX_LENGTH));
	}
}
