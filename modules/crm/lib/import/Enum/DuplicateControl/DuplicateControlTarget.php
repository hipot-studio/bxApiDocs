<?php

namespace Bitrix\Crm\Import\Enum\DuplicateControl;

use Bitrix\Crm\Import\Contract\Enum\HasTitleInterface;
use Bitrix\Crm\Item;
use Bitrix\Main\Localization\Loc;
use CCrmOwnerType;

enum DuplicateControlTarget: string implements HasTitleInterface
{
	case FullName = 'FULL_NAME';
	case CompanyTitle = 'COMPANY_TITLE';
	case Title = 'TITLE';
	case Phone = 'PHONE';
	case Email = 'EMAIL';

	public function getTitle(): ?string
	{
		return match ($this) {
			self::FullName => Loc::getMessage('CRM_IMPORT_ENUM_DUPLICATE_CONTROL_TARGET_TITLE_FULL_NAME'),
			self::CompanyTitle => Loc::getMessage('CRM_IMPORT_ENUM_DUPLICATE_CONTROL_TARGET_TITLE_COMPANY_TITLE'),
			self::Title => Loc::getMessage('CRM_IMPORT_ENUM_DUPLICATE_CONTROL_TARGET_TITLE_TITLE'),
			self::Phone => Loc::getMessage('CRM_IMPORT_ENUM_DUPLICATE_CONTROL_TARGET_TITLE_PHONE'),
			self::Email => Loc::getMessage('CRM_IMPORT_ENUM_DUPLICATE_CONTROL_TARGET_TITLE_EMAIL'),
		};
	}

	/**
	 * @param self[] $cases
	 * @return string[]
	 */
	public static function collectFieldNames(array $cases): array
	{
		$fieldNames = [];
		foreach ($cases as $case)
		{
			$fieldNames[] = match ($case) {
				self::FullName => [
					Item::FIELD_NAME_NAME,
					Item::FIELD_NAME_SECOND_NAME,
					Item::FIELD_NAME_LAST_NAME,
				],
				self::CompanyTitle => [
					Item\Lead::FIELD_NAME_COMPANY_TITLE,
				],
				self::Title => [
					Item::FIELD_NAME_TITLE,
				],
				self::Phone => [
					'FM.PHONE'
				],
				self::Email => [
					'FM.EMAIL',
				],
			};
		}

		return array_unique(array_merge(...$fieldNames));
	}

	public static function getCasesForEntity(int $entityTypeId): array
	{
		if ($entityTypeId === CCrmOwnerType::Lead)
		{
			return [
				self::FullName,
				self::CompanyTitle,
				self::Email,
				self::Phone,
			];
		}

		if ($entityTypeId === CCrmOwnerType::Contact)
		{
			return [
				self::FullName,
				self::Email,
				self::Phone,
			];
		}

		if ($entityTypeId === CCrmOwnerType::Company)
		{
			return [
				self::Title,
				self::Phone,
				self::Email,
			];
		}

		return [];
	}
}
