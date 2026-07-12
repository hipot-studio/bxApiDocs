<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Enum;

enum ExternalLinkDisableReason: string
{
	case Feature = 'feature';
	case Option = 'option';
	case FileOption = 'fileOption';

	/**
	 * @return array
	 */
	public static function forExtension(): array
	{
		return [
			'feature' => ExternalLinkDisableReason::Feature->value,
			'option' => ExternalLinkDisableReason::Option->value,
			'fileOption' => ExternalLinkDisableReason::FileOption->value,
		];
	}
}
