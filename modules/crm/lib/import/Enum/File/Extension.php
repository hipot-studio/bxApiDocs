<?php

namespace Bitrix\Crm\Import\Enum\File;

use Bitrix\Main\IO\Path;

enum Extension: string
{
	case VCard = 'vcf';
	case CSV = 'csv';

	public static function tryFromType(string $type): ?self
	{
		return match ($type) {
			'text/plain',
			'text/csv',
			'application/vnd.ms-excel' => self::CSV,
			'text/vcard',
			'text/x-vcard' => self::VCard,
			default => null,
		};
	}

	public static function tryFromFilename(string $filename): ?self
	{
		$extension = Path::getExtension($filename);

		return match ($extension) {
			'csv' => self::CSV,
			'vcf' => self::VCard,
			default => null,
		};
	}
}
