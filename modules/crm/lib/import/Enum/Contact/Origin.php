<?php

namespace Bitrix\Crm\Import\Enum\Contact;

use Bitrix\Crm\Import\Contract\Enum\HasTitleInterface;
use Bitrix\Main\Localization\Loc;

enum Origin: string implements HasTitleInterface
{
	case Custom = 'custom';
	case Gmail = 'gmail';
	case Outlook = 'outlook';
	case Yahoo = 'yahoo';
	case VCard = 'vcard';

	public function getTitle(): ?string
	{
		return match ($this) {
			self::Custom => Loc::getMessage('CRM_IMPORT_ENUM_CONTACT_ORIGIN_TITLE_CUSTOM'),
			self::Gmail => Loc::getMessage('CRM_IMPORT_ENUM_CONTACT_ORIGIN_TITLE_GMAIL'),
			self::Outlook => Loc::getMessage('CRM_IMPORT_ENUM_CONTACT_ORIGIN_TITLE_OUTLOOK'),
			self::Yahoo => Loc::getMessage('CRM_IMPORT_ENUM_CONTACT_ORIGIN_TITLE_YAHOO'),
			self::VCard => Loc::getMessage('CRM_IMPORT_ENUM_CONTACT_ORIGIN_TITLE_VCARD'),
		};
	}

	/**
	 * @return self[]
	 */
	public static function getContactImportList(): array
	{
		return [
			self::VCard,
			self::Gmail,
			self::Outlook,
			self::Yahoo,
			self::Custom,
		];
	}

	public static function has(mixed $value): bool
	{
		return self::tryFrom($value) !== null;
	}
}
