<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\File;

use Bitrix\Main\Security\Random;
use Bitrix\Main\Web\MimeType;
use Bitrix\Main\IO\File as IOFile;
use CFile;
use CTempFile;

class File
{
	public static function createImageFromBase64(string $value): array
	{
		$value = base64_decode($value);

		$mime = MimeType::getByContent($value);
		if (!MimeType::isImage($mime))
		{
			return [];
		}

		[, $type] = explode('/', $mime);

		$fileName = Random::getString(32);
		$fileName = CTempFile::GetFileName($fileName . '.' . $type);

		if (!CheckDirPath($fileName))
		{
			return [];
		}

		(new IOFile($fileName))->putContents($value);
		$file = CFile::MakeFileArray($fileName);

		return is_array($file) ? $file : [];
	}
}
