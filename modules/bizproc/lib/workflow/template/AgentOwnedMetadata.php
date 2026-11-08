<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Workflow\Template;

/** Stores which template metadata fields are owned by a draft. */
final class AgentOwnedMetadata
{
	public const KEY = '~AI_AGENT_OWNED_META';

	public const FIELD_NAME = 'NAME';
	public const FIELD_DESCRIPTION = 'DESCRIPTION';

	private const FIELDS = [self::FIELD_NAME, self::FIELD_DESCRIPTION];

	/**
	 * @param array<string, mixed> $templateData
	 * @return list<string>
	 */
	public static function readFrom(array $templateData): array
	{
		$stored = $templateData[self::KEY] ?? null;

		return is_array($stored) ? self::onlyKnownFields($stored) : [];
	}

	/** @param array<string, mixed> $templateData */
	public static function isFieldOwned(array $templateData, string $field, bool $isRestoredDraft = false): bool
	{
		return $isRestoredDraft || in_array($field, self::readFrom($templateData), true);
	}

	/**
	 * @param array<string, mixed> $boundDraftData
	 * @param array<string, mixed> $writtenFields
	 * @param list<string> $fieldsToClaim
	 * @return list<string>
	 */
	public static function nextOwnedFields(
		array $boundDraftData,
		array $writtenFields,
		array $fieldsToClaim,
	): array
	{
		$claimed = self::onlyKnownFields($fieldsToClaim);

		$carried = [];
		foreach (self::readFrom($boundDraftData) as $field)
		{
			if (
				!in_array($field, $claimed, true)
				&& ($writtenFields[$field] ?? null) === ($boundDraftData[$field] ?? null)
			)
			{
				$carried[] = $field;
			}
		}

		return array_values(array_unique([...$carried, ...$claimed]));
	}

	/**
	 * @param array<string, mixed> $templateData
	 * @param list<string> $ownedFields
	 * @return array<string, mixed>
	 */
	public static function applyTo(array $templateData, array $ownedFields): array
	{
		$fields = self::onlyKnownFields($ownedFields);
		if ($fields === [])
		{
			unset($templateData[self::KEY]);

			return $templateData;
		}

		$templateData[self::KEY] = $fields;

		return $templateData;
	}

	/**
	 * @param array<string, mixed> $templateData
	 * @return array<string, mixed>
	 */
	public static function stripFrom(array $templateData): array
	{
		unset($templateData[self::KEY]);

		return $templateData;
	}

	/**
	 * @param array<array-key, mixed> $fields
	 * @return list<string>
	 */
	public static function onlyKnownFields(array $fields): array
	{
		$known = [];
		foreach ($fields as $field)
		{
			if (is_string($field) && in_array($field, self::FIELDS, true) && !in_array($field, $known, true))
			{
				$known[] = $field;
			}
		}

		return $known;
	}
}
