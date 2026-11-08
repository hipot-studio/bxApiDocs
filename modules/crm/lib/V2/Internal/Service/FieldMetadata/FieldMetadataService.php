<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\FieldMetadata;

use Bitrix\Crm\V2\Internal\Entity\FieldMetadata\FieldMetadata;
use Bitrix\Crm\V2\Internal\Repository\FieldMetadata\FieldMetadataSourceInterface;
use Bitrix\Crm\V2\Internal\Service\Item\CustomFieldRegistry;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Main\Localization\LocalizableMessage;

/**
 * @internal
 */
final class FieldMetadataService
{
	public function __construct(
		private readonly FieldMetadataSourceInterface $source,
		private readonly CustomFieldRegistry $customFieldRegistry,
	)
	{
	}

	/**
	 * @return FieldMetadata[]
	 */
	public function getAll(EntityType $entityType, int $userId, string $languageId): array
	{
		$fullFields = $this->source->getAll($entityType);
		[$userDefinitions, $visibleUserNames] = $this->getUserFieldContext($entityType, $userId, $languageId);
		$projectedFields = [];

		foreach ($fullFields as $field)
		{
			if (!$this->isFieldVisible($field, $visibleUserNames))
			{
				continue;
			}

			$projectedFields[] = $this->projectField($field, $userDefinitions, $languageId);
		}

		return $projectedFields;
	}

	public function getByName(
		EntityType $entityType,
		string $name,
		int $userId,
		string $languageId,
	): ?FieldMetadata
	{
		$field = $this->source->getByName($entityType, $name);
		if ($field === null)
		{
			return null;
		}

		if (!$field->isUserField && $field->sourceUserFieldName === null)
		{
			return $this->projectField($field, [], $languageId);
		}

		[$userDefinitions, $visibleUserNames] = $this->getUserFieldContext($entityType, $userId, $languageId);
		if (!$this->isFieldVisible($field, $visibleUserNames))
		{
			return null;
		}

		return $this->projectField($field, $userDefinitions, $languageId);
	}

	/**
	 * @return array{0: array<string, array<string, mixed>>, 1: array<string, array<string, mixed>>}
	 */
	private function getUserFieldContext(EntityType $entityType, int $userId, string $languageId): array
	{
		$userDefinitions = $this->customFieldRegistry->getUserFields($entityType, $languageId, $userId);
		$forbiddenNames = array_fill_keys(
			$this->customFieldRegistry->getHiddenFieldNames($entityType, $userId),
			true,
		);

		return [$userDefinitions, array_diff_key($userDefinitions, $forbiddenNames)];
	}

	/**
	 * @param array<string, array<string, mixed>> $visibleUserNames
	 */
	private function isFieldVisible(FieldMetadata $field, array $visibleUserNames): bool
	{
		if ($field->isUserField && !array_key_exists($field->name, $visibleUserNames))
		{
			return false;
		}

		return $field->sourceUserFieldName === null
			|| array_key_exists($field->sourceUserFieldName, $visibleUserNames);
	}

	/**
	 * @param array<string, array<string, mixed>> $userDefinitions
	 */
	private function projectField(
		FieldMetadata $field,
		array $userDefinitions,
		string $languageId,
	): FieldMetadata
	{
		$projectedField = $this->localizeSystemField($field, $languageId);

		if ($field->isUserField)
		{
			$definition = $userDefinitions[$field->name];
			$projectedField = $field->withTitleAndDescription(
				$this->getUserFieldTitle($definition, $field->name),
				$this->emptyToNull($definition['HELP_MESSAGE'] ?? null),
			);
		}

		if ($field->sourceUserFieldName !== null)
		{
			if (str_ends_with($field->name, '_data'))
			{
				$definition = $userDefinitions[$field->sourceUserFieldName] ?? null;
				if (is_array($definition))
				{
					$projectedField = $projectedField->withTitleAndDescription(
						$this->getUserFieldTitle($definition, $field->sourceUserFieldName),
						$this->emptyToNull($definition['HELP_MESSAGE'] ?? null),
					);
				}
			}
			else
			{
				$projectedField = $projectedField->withTitleAndDescription(null, null);
			}
		}

		return $projectedField;
	}

	private function localizeSystemField(
		FieldMetadata $field,
		string $languageId,
	): FieldMetadata
	{
		if ($field->isUserField || $field->sourceUserFieldName !== null || !$field->title instanceof LocalizableMessage)
		{
			return $field;
		}

		$title = $field->title->localize($languageId) ?? $field->name;

		return $field->withTitleAndDescription($title, $field->description);
	}

	/**
	 * @param array<string, mixed> $definition
	 */
	private function getUserFieldTitle(array $definition, string $fieldName): string
	{
		foreach (
			[
				$definition['EDIT_FORM_LABEL'] ?? null,
				$definition['LIST_COLUMN_LABEL'] ?? null,
				$definition['LIST_FILTER_LABEL'] ?? null,
				$fieldName,
			] as $value
		)
		{
			if ($value !== null && $value !== '')
			{
				return (string)$value;
			}
		}

		return $fieldName;
	}

	private function emptyToNull(mixed $value): ?string
	{
		return $value === null || $value === '' ? null : (string)$value;
	}
}
