<?php

namespace Bitrix\Crm\Import\Enum\DuplicateControl;

use Bitrix\Crm\Import\Contract\Enum\HasHintInterface;
use Bitrix\Crm\Import\Contract\Enum\HasTitleInterface;
use Bitrix\Main\Localization\Loc;

enum DuplicateControlBehavior: string implements HasTitleInterface, HasHintInterface
{
	case NoControl = 'NO_CONTROL';
	case Replace = 'REPLACE';
	case Merge = 'MERGE';
	case Skip = 'SKIP';

	public function getTitle(): ?string
	{
		return match($this) {
			self::NoControl => Loc::getMessage('CRM_IMPORT_ENUM_DUPLICATE_CONTROL_BEHAVIOR_TITLE_NO_CONTROL'),
			self::Replace => Loc::getMessage('CRM_IMPORT_ENUM_DUPLICATE_CONTROL_BEHAVIOR_TITLE_REPLACE'),
			self::Merge => Loc::getMessage('CRM_IMPORT_ENUM_DUPLICATE_CONTROL_BEHAVIOR_TITLE_MERGE'),
			self::Skip => Loc::getMessage('CRM_IMPORT_ENUM_DUPLICATE_CONTROL_BEHAVIOR_TITLE_SKIP'),
		};
	}

	public function getHint(): ?string
	{
		return match($this) {
			self::NoControl => Loc::getMessage('CRM_IMPORT_ENUM_DUPLICATE_CONTROL_BEHAVIOR_HINT_NO_CONTROL'),
			self::Replace => Loc::getMessage('CRM_IMPORT_ENUM_DUPLICATE_CONTROL_BEHAVIOR_HINT_REPLACE'),
			self::Merge => Loc::getMessage('CRM_IMPORT_ENUM_DUPLICATE_CONTROL_BEHAVIOR_HINT_MERGE'),
			self::Skip => Loc::getMessage('CRM_IMPORT_ENUM_DUPLICATE_CONTROL_BEHAVIOR_HINT_SKIP'),
		};
	}
}
