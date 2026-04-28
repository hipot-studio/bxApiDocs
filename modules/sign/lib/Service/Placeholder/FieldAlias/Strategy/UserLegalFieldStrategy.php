<?php

namespace Bitrix\Sign\Service\Placeholder\FieldAlias\Strategy;

use Bitrix\Sign\Service\Placeholder\FieldAlias\AliasContext;
use Bitrix\Sign\Type\BlockCode;
use Bitrix\Sign\Type\BlockParty;
use Bitrix\Sign\Type\FieldType;

class UserLegalFieldStrategy extends AbstractAliasStrategy
{
	private const ALIAS_PREFIX = 'User';
	private const BLOCK_CODES = [BlockCode::B2E_REFERENCE, BlockCode::B2E_MY_REFERENCE];
	private const UF_PREFIX = 'USER_UF_USER_LEGAL_';
	private const UF_LEGAL_PREFIX = 'UF_USER_LEGAL_';

	private const STATIC_FIELDS = [
		'Name' => ['fieldCode' => 'USER_UF_LEGAL_NAME', 'fieldType' => FieldType::FIRST_NAME],
		'LastName' => ['fieldCode' => 'USER_UF_LEGAL_LAST_NAME', 'fieldType' => FieldType::LAST_NAME],
		'Patronymic' => ['fieldCode' => 'USER_UF_LEGAL_PATRONYMIC_NAME', 'fieldType' => FieldType::PATRONYMIC],
		'Pos' => ['fieldCode' => 'USER_UF_LEGAL_POSITION', 'fieldType' => FieldType::POSITION],
		'Addr' => ['fieldCode' => 'USER_UF_LEGAL_ADDRESS', 'fieldType' => FieldType::STRING],
		'Snils' => ['fieldCode' => 'USER_UF_LEGAL_SNILS', 'fieldType' => FieldType::SNILS],
		'Inn' => ['fieldCode' => 'USER_UF_LEGAL_INN', 'fieldType' => FieldType::STRING],
	];

	private const STATIC_REVERSE = [
		'USER_UF_LEGAL_NAME' => 'Name',
		'USER_UF_LEGAL_LAST_NAME' => 'LastName',
		'USER_UF_LEGAL_PATRONYMIC_NAME' => 'Patronymic',
		'USER_UF_LEGAL_POSITION' => 'Pos',
		'USER_UF_LEGAL_ADDRESS' => 'Addr',
		'USER_UF_LEGAL_SNILS' => 'Snils',
		'USER_UF_LEGAL_INN' => 'Inn',
	];

	public function getAliasPrefixes(): array
	{
		return [self::ALIAS_PREFIX];
	}

	public function supportsFieldName(string $fieldName): bool
	{
		$parsed = $this->parseFieldName($fieldName);

		if (!$this->hasRequiredParsedValues($parsed, ['fieldCode', 'blockCode']))
		{
			return false;
		}

		$fieldCode = $parsed['fieldCode'];

		if (isset(self::STATIC_REVERSE[$fieldCode]) || str_starts_with($fieldCode, self::UF_PREFIX))
		{
			return in_array($parsed['blockCode'], self::BLOCK_CODES, true);
		}

		return false;
	}

	public function supportsAlias(string $alias): bool
	{
		return str_starts_with($alias, self::ALIAS_PREFIX . '.');
	}

	public function fieldNameToAlias(string $fieldName, AliasContext $context): ?string
	{
		$parsed = $this->parseFieldName($fieldName);

		if (!$this->hasRequiredParsedValues($parsed, ['fieldCode'], ['party']))
		{
			return null;
		}

		$fieldCode = $parsed['fieldCode'];

		if (isset(self::STATIC_REVERSE[$fieldCode]))
		{
			$shortName = self::STATIC_REVERSE[$fieldCode];
			$roleName = $this->getRoleName($context, $parsed['party']);

			return self::ALIAS_PREFIX . ".{$roleName}.{$shortName}";
		}

		if (
			str_starts_with($fieldCode, self::UF_PREFIX)
			|| str_starts_with($fieldCode, self::UF_LEGAL_PREFIX)
		)
		{
			return $this->buildDynamicAliasFromParsedField($parsed, $context, self::ALIAS_PREFIX);
		}

		return null;
	}

	public function aliasToFieldName(string $alias, AliasContext $context): ?string
	{
		$aliasData = $this->parseAliasFieldPartWithParty($alias, $context, self::ALIAS_PREFIX);
		if ($aliasData === null)
		{
			return null;
		}

		$fieldPart = $aliasData['fieldPart'];
		$party = $aliasData['party'];
		$blockCode = $party === BlockParty::NOT_LAST_PARTY ? BlockCode::B2E_MY_REFERENCE : BlockCode::B2E_REFERENCE;

		if (isset(self::STATIC_FIELDS[$fieldPart]))
		{
			$fieldData = self::STATIC_FIELDS[$fieldPart];
			return $this->createFieldName(
				blockCode: $blockCode,
				fieldType: $fieldData['fieldType'],
				party: $party,
				fieldCode: $fieldData['fieldCode'],
			);
		}

		if (preg_match('/^(\w+?)(\d{' . self::DYNAMIC_FIELD_TIMESTAMP_LENGTH . '})$/', $fieldPart, $matches))
		{
			[, $typeShortcut, $timestamp] = $matches;

			$fieldType = array_search($typeShortcut, self::TYPE_SHORTCUTS, true) ?: 'string';
			$fieldCode = self::UF_PREFIX . $timestamp;

			return $this->createFieldName(
				blockCode: $blockCode,
				fieldType: $fieldType,
				party: $party,
				fieldCode: $fieldCode,
			);
		}

		return null;
	}
}
