<?php

namespace Bitrix\Mobile\Tab\Preset\Enum;

enum PresetSource: string
{
	case Builtin = 'builtin';
	case Intent = 'intent';
	case Manual = 'manual';
	case Managed = 'managed';
	case AppliedDeleted = 'applied_deleted';

	public static function values(): array
	{
		return array_column(self::cases(), 'value');
	}
}
