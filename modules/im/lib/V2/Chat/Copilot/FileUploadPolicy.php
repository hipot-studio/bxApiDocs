<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Chat\Copilot;

final class FileUploadPolicy
{
	public const MAX_FILE_SIZE = 5 * 1024 * 1024;
	public const MAX_FILES_PER_MESSAGE = 10;

	public const ALLOWED_EXTENSIONS = [
		'txt',
		'md',
		'csv',
		'xlsx',
		'xls',
		'docx',
		'doc',
		'pptx',
		'pdf',
		'jpg',
		'jpeg',
		'png',
		'webp',
		'gif',
	];

	public static function toPublicData(): array
	{
		return [
			'allowedExtensions' => self::ALLOWED_EXTENSIONS,
			'maxFileSize' => self::MAX_FILE_SIZE,
			'maxFilesPerMessage' => self::MAX_FILES_PER_MESSAGE,
		];
	}
}
