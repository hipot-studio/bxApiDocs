<?php

namespace Bitrix\AI\Facade;

use Bitrix\AI\Config;
use Bitrix\AI\Image\Url\RuntimeInterface;
use Bitrix\AI\Image\Url\SecureImageUrlLoader;
use Bitrix\Main\Context;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\IO\File as FileCore;
use Bitrix\Main\Security\Random;
use CFile;

class File
{
	/**
	 * Retrieves external image and saves to DB. Returns local file id.
	 *
	 * @param string $pictureUrl Picture external URL.
	 * @param string $moduleId File's module id.
	 * @return int|null
	 */
	public static function saveImageByURL(string $pictureUrl, string $moduleId): ?int
	{
		if (!$pictureUrl)
		{
			return null;
		}

		if (!preg_match('/^[a-z]+$/i', $moduleId))
		{
			return null;
		}

		$forbiddenRanges = Config::getValue('image_url_forbidden_ranges');
		if (is_string($forbiddenRanges))
		{
			$forbiddenRanges = preg_split('/\s*,\s*/', $forbiddenRanges, -1, PREG_SPLIT_NO_EMPTY);
		}
		if (!is_array($forbiddenRanges))
		{
			$forbiddenRanges = [];
		}

		$runtime = ServiceLocator::getInstance()->get(RuntimeInterface::class);

		return (new SecureImageUrlLoader($runtime, $forbiddenRanges))->save($pictureUrl, $moduleId);
	}

	/**
	 * Save base64coded image.
	 *
	 * @param string $imageBase64
	 * @param string $ext
	 * @param string $moduleId
	 *
	 * @return int|null
	 */
	public static function saveImageByBase64Content(string $imageBase64, string $moduleId): ?int
	{
		$name = Random::getString(32, true);
		$filePath = \CTempFile::GetFileName($name);
		$file = new FileCore($filePath);
		$file->putContents(base64_decode($imageBase64));
		$mimeType = $file->getContentType();

		$allowedImageTypes = [
			'image/jpeg' => 'jpg',
			'image/png' => 'png',
			'image/gif' => 'gif',
			'image/webp' => 'webp',
		];

		if (!array_key_exists($mimeType, $allowedImageTypes))
		{
			return null;
		}
		$extension = $allowedImageTypes[$mimeType];

		$file = CFile::MakeFileArray($filePath);
		$file['MODULE_ID'] = $moduleId;
		$file['name'] = $name . '.' . $extension;
		if (CFile::CheckImageFile($file) !== null)
		{
			return null;
		}

		return CFile::SaveFile($file, $moduleId);
	}

	/**
	 * Returns file's content.
	 *
	 * @param string $path Local file path.
	 * @return string
	 */
	public static function getContents(string $path): string
	{
		return FileCore::getFileContents($path) ?: '';
	}

	public static function getAbsoluteUri(string $fileSrc): string
	{
		if (substr_count($fileSrc, '://'))
		{
			return $fileSrc;
		}

		$httpRequest = Context::getCurrent()->getRequest();
		$protocol = $httpRequest->isHttps() ? 'https' : 'http';

		return $protocol . '://' . $httpRequest->getHttpHost() . $fileSrc;
	}
}
