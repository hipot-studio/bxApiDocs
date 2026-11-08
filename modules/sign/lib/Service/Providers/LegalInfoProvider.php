<?php

namespace Bitrix\Sign\Service\Providers;

use Bitrix\Main\Localization\Loc;
use Bitrix\Sign\Config\LegalInfo;
use Bitrix\Sign\Item\B2e\LegalInfoField;
use Bitrix\Sign\Type\FieldType;

class LegalInfoProvider extends InfoProvider
{
	private array $legalFieldsByType;

	public const USER_FIELD_ENTITY_ID = LegalInfo::USER_FIELD_ENTITY_ID;
	public const VIRTUAL_FULL_NAME_FIELD = 'UF_LEGAL_FULL_NAME';
	public const SNILS_FIELD = 'UF_LEGAL_SNILS';
	public const INN_FIELD = 'UF_LEGAL_INN';
	public const LEGAL_USER_FIELD_DEFAULT = [
		'UF_LEGAL_ADDRESS',
		self::INN_FIELD,
		self::SNILS_FIELD,
		'UF_LEGAL_POSITION',
		'UF_LEGAL_PATRONYMIC_NAME',
		'UF_LEGAL_LAST_NAME',
		'UF_LEGAL_NAME',
	];
	private const NAME_PART_FIELDS = [
		'UF_LEGAL_LAST_NAME',
		'UF_LEGAL_NAME',
		'UF_LEGAL_PATRONYMIC_NAME',
	];

	/**
	 * Semantic type of a legal field the provider knows from the field itself. Read by getType(), so
	 * it also drives the lookup of a field by its type (getLegalInfoFieldByType()).
	 */
	private const PROVIDER_FIELD_TYPES = [
		'UF_LEGAL_NAME' => FieldType::FIRST_NAME,
		'UF_LEGAL_LAST_NAME' => FieldType::LAST_NAME,
		'UF_LEGAL_PATRONYMIC_NAME' => FieldType::PATRONYMIC,
		'UF_LEGAL_POSITION' => FieldType::POSITION,
		self::VIRTUAL_FULL_NAME_FIELD => FieldType::FULL_NAME,
	];

	/**
	 * Semantic type of a legal field only its field code tells: the user field behind it can be a
	 * plain string, or there is no user field at all. Read by the stage that publishes the type of
	 * the fields list, FieldService::resolveProfileFieldType(); the provider itself keeps reporting
	 * the user field type for these codes, because a field is looked up by type on stands where the
	 * user field carries the meaning.
	 */
	public const CONTRACT_FIELD_TYPES = [
		self::VIRTUAL_FULL_NAME_FIELD => FieldType::FULL_NAME,
		self::SNILS_FIELD => FieldType::SNILS,
		self::INN_FIELD => FieldType::INN,
	];

	/**
	 * @return array<LegalInfoField>
	 */
	public function getFieldsItems(): array
	{
		return array_map(static fn(array $field): LegalInfoField => new LegalInfoField(
			type: $field['type'],
			caption: $field['caption'],
			name: $field['sourceName'],
		), $this->getFieldsMap());
	}

	public function getFieldsForSelector(): array
	{
		$fields = parent::getFieldsForSelector();
		$position = $this->getPositionAfterNameParts($fields);
		array_splice($fields, $position, 0, [$this->getFullNameFieldForSelector()]);

		return $fields;
	}

	/**
	 * The full name is only meaningful next to the separate name parts it is assembled from,
	 * so it takes the slot right after the last of them; without any of them it goes last.
	 */
	private function getPositionAfterNameParts(array $fields): int
	{
		$position = count($fields);
		foreach ($fields as $index => $field)
		{
			if (in_array($field['name'] ?? '', self::NAME_PART_FIELDS, true))
			{
				$position = $index + 1;
			}
		}

		return $position;
	}

	/**
	 * The full name is a virtual field: it has no user field in the database and is
	 * assembled from the separate legal name parts, so its selector entry is injected
	 * manually next to the real legal fields.
	 */
	private function getFullNameFieldForSelector(): array
	{
		// The caption phrase lives in the ProfileProvider lang file, which is not auto-loaded here.
		Loc::loadMessages(__DIR__ . '/ProfileProvider.php');

		return [
			'type' => $this->getType(['FIELD_NAME' => self::VIRTUAL_FULL_NAME_FIELD]),
			'entity_name' => self::USER_FIELD_ENTITY_ID,
			'name' => self::VIRTUAL_FULL_NAME_FIELD,
			'caption' => (string)Loc::getMessage('SIGN_SERVICE_PROVIDER_PROFILE_FIELD_CAPTION_FULL_NAME'),
			'multiple' => false,
			'required' => false,
			'hidden' => false,
		];
	}

	protected function getType(array $field): string
	{
		return self::PROVIDER_FIELD_TYPES[$field['FIELD_NAME'] ?? ''] ?? parent::getType($field);
	}

	public function getFirstFieldNameByType(string $fieldType): ?string
	{
		return $this->getLegalInfoFieldByType($fieldType)?->name;
	}

	public function getLegalInfoFieldByType(string $type): ?LegalInfoField
	{
		if (!isset($this->legalFieldsByType))
		{
			$this->legalFieldsByType = [];
			foreach ($this->getFieldsItems() as $field)
			{
				$this->legalFieldsByType[$field->type] = $field;
			}
		}

		return $this->legalFieldsByType[$type] ?? null;
	}
}
