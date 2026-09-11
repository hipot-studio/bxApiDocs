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
	public const LEGAL_USER_FIELD_DEFAULT = [
		'UF_LEGAL_ADDRESS',
		'UF_LEGAL_INN',
		'UF_LEGAL_SNILS',
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
		return match ($field['FIELD_NAME'] ?? '')
		{
			'UF_LEGAL_NAME' => FieldType::FIRST_NAME,
			'UF_LEGAL_LAST_NAME' => FieldType::LAST_NAME,
			'UF_LEGAL_PATRONYMIC_NAME' => FieldType::PATRONYMIC,
			'UF_LEGAL_POSITION' => FieldType::POSITION,
			self::VIRTUAL_FULL_NAME_FIELD => FieldType::FULL_NAME,
			default => parent::getType($field),
		};
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
