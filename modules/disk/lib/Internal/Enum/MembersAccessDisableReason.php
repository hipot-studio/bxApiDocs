<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Enum;

enum MembersAccessDisableReason: string
{
	case Feature = 'feature';

	/**
	 * @return array
	 */
	public static function forExtension(): array
	{
		return [
			'feature' => MembersAccessDisableReason::Feature->value,
		];
	}
}
