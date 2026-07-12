<?php

namespace Bitrix\Disk\Type;

use Bitrix\Disk\Internals\ExternalLinkTable;

enum AccessRight: string
{

	case Read = 'read';
	case Edit = 'edit';

	public function toExternalLinkRight(): ?int
	{
		return match ($this) {
			self::Read => ExternalLinkTable::ACCESS_RIGHT_VIEW,
			self::Edit => ExternalLinkTable::ACCESS_RIGHT_EDIT,
		};
	}

	public static function tryFromExternalLinkRights($val): ?self
	{
		return match ($val) {
			ExternalLinkTable::ACCESS_RIGHT_VIEW => self::Read,
			ExternalLinkTable::ACCESS_RIGHT_EDIT => self::Edit,
			default => null,
		};
	}

}
