<?php

namespace Bitrix\StaffTrack\Internal\Integration\Disk;

use Bitrix\Disk\Driver;
use Bitrix\Disk\File;
use Bitrix\Disk\UrlManager;

class FileMapper
{
	private UrlManager $urlManager;

	public function __construct()
	{
		$this->urlManager = Driver::getInstance()->getUrlManager();
	}

	public function toArray(File $diskFile): array
	{
		$fileData = $diskFile->getFile();
		$contentType = $fileData['CONTENT_TYPE'] ?? 'application/octet-stream';

		$result = [
			'id' => $diskFile->getId(),
			'name' => $diskFile->getName(),
			'type' => $contentType,
			'url' => $this->urlManager->getUrlForShowFile($diskFile),
		];

		if (self::isImage($contentType) && is_array($fileData))
		{
			$result['width'] = (int)($fileData['WIDTH'] ?? 0);
			$result['height'] = (int)($fileData['HEIGHT'] ?? 0);

			$previewUrl = $this->urlManager->getUrlForShowFile($diskFile, ['width' => 640, 'height' => 640]);
			if ($previewUrl)
			{
				$result['previewUrl'] = $previewUrl;
			}
		}

		return $result;
	}

	private static function isImage(string $contentType): bool
	{
		return str_starts_with($contentType, 'image/');
	}
}