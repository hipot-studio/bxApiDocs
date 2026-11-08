<?php

namespace Bitrix\Mobile\Tab\Preset\Enum;

enum AssignmentTargetType: string
{
	case User = 'USER';
	case HrNode = 'HR_NODE';
	case HrNodeFlat = 'HR_NODE_FLAT';

	public static function values(): array
	{
		return array_column(self::cases(), 'value');
	}

	public function isDepartment(): bool
	{
		return in_array($this, [self::HrNode, self::HrNodeFlat], true);
	}

	public function isRecursiveDepartment(): bool
	{
		return $this === self::HrNode;
	}
}
