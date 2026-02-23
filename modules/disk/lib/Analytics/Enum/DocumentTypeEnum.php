<?php
declare(strict_types=1);

namespace Bitrix\Disk\Analytics\Enum;

enum DocumentTypeEnum: string
{
	case Doc = 'doc';
	case Sheet = 'sheet';
	case Pres = 'pres';

	public static function getByExtension(string $extension): ?DocumentTypeEnum
	{
		return match ($extension)
		{
			'doc', 'docm', 'docx', 'dotm', 'dotx', 'rtf' => DocumentTypeEnum::Doc,
			'xls', 'xlam', 'xlsb', 'xlsm', 'xlsx', 'xltm', 'xltx' => DocumentTypeEnum::Sheet,
			'ppt', 'potm', 'potx', 'ppam', 'ppsm', 'ppsx', 'pptm', 'pptx' => DocumentTypeEnum::Pres,
			default => null,
		};
	}
}
