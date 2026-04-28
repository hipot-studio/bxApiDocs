<?php

declare(strict_types=1);

namespace Bitrix\Disk\QuickAccess\FileInfo;

use Bitrix\Disk\Public\TypeFileMap;
use Bitrix\Disk\QuickAccess\Storage\ScopeStorage;
use Bitrix\Disk\TypeFile;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Web\Uri;

abstract class BaseProvider implements ProviderInterface
{
	protected ?FileInfoDto $fileInfo = null;

	abstract protected function __construct(mixed $file);

	abstract public static function create(mixed $file): ?static;

	/**
	 * Create DTO from data array
	 * @param array $fileData - @see CFile::getById());
	 * @return FileInfoDto
	 */
	protected static function createFileInfo(array $fileData): FileInfoDto
	{
		$cloudHandlerId = (int)($fileData['HANDLER_ID'] ?? 0);
		$fromClouds = $cloudHandlerId > 0;

		$filename = $fileData['SRC'];
		$filenameEncoded = Uri::urnEncode($filename, 'UTF-8');

		if ($fromClouds)
		{
			$filenameDisableProto = preg_replace('~^(https?)(\://)~i', '\\1.', $filenameEncoded);
			$cloudUploadPath = Option::get('main', 'bx_cloud_upload', '/upload/bx_cloud_upload/');
			$filePath = rawurlencode($cloudUploadPath . $filenameDisableProto);
		}
		else
		{
			$filePath = $filenameEncoded;
		}

		$normalizedMimeType = TypeFile::normalizeMimeType($fileData['CONTENT_TYPE'], $filePath);
		$fileExtension = TypeFile::getExtensionByMimeType($normalizedMimeType);
		return new FileInfoDto(
			id: (int)$fileData['ID'],
			handlerId: (int)$fileData['HANDLER_ID'],
			width: (int)$fileData['WIDTH'],
			height: (int)$fileData['HEIGHT'],
			path: $filePath,
			dir: $fileData['SUBDIR'],
			filename: $fileData['FILE_NAME'],
			contentType: $normalizedMimeType,
			expirationTime: time() + ScopeStorage::DEFAULT_FILE_METADATA_TTL,
			typeFile: TypeFileMap::fromTypeFileConstant(TypeFile::getByExtension($fileExtension)),
		);
	}
}