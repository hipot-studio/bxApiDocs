<?php

namespace Bitrix\Crm\Import\Enum;

use Bitrix\Crm\Import\Contract\Enum\HasTitleInterface;
use Bitrix\Main\Localization\Loc;

enum Delimiter: string implements HasTitleInterface
{
	case Semicolon = 'semicolon';
	case Comma = 'comma';
	case Tab = 'tab';
	case Space = 'space';

	public function getTitle(): string
	{
		return match ($this) {
			self::Semicolon => Loc::getMessage('CRM_IMPORT_ENUM_DELIMITER_TITLE_SEMICOLON'),
			self::Comma => Loc::getMessage('CRM_IMPORT_ENUM_DELIMITER_TITLE_COMMA'),
			self::Tab => Loc::getMessage('CRM_IMPORT_ENUM_DELIMITER_TITLE_TAB'),
			self::Space => Loc::getMessage('CRM_IMPORT_ENUM_DELIMITER_TITLE_SPACE'),
		};
	}

	public function getSymbol(): string
	{
		return match ($this) {
			self::Semicolon => ";",
			self::Comma => ",",
			self::Tab => "\t",
			self::Space => " ",
		};
	}
}
