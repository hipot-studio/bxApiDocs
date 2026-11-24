<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\MarkdownEditor;

use Bitrix\Disk\Configuration;
use Bitrix\Disk\File;
use Bitrix\Main\Data\Cache;

/**
 * Whether the bytes of the head revision of a Markdown file are valid UTF-8 (a BOM is valid UTF-8 too): the
 * editor writes the bytes back as they are, so a file in another encoding is not offered for editing.
 *
 * A blob never changes, so the verdict is kept by the id of the blob and a new version is read anew. The caller
 * judges the size first; a file over {@see Configuration::getMaxSizeForMarkdownRender()} is never read here.
 */
final class Utf8Inspector
{
	private const CACHE_TTL = 86400;
	private const CACHE_DIR = '/disk/markdown_editor/utf8/';
	private const CACHE_SHARDS = 256;

	public function isUtf8(File $file): bool
	{
		if ((int)$file->getSize() > Configuration::getMaxSizeForMarkdownRender())
		{
			return false;
		}

		$blobId = (int)$file->getFileId();
		if ($blobId <= 0)
		{
			return false;
		}

		$cache = Cache::createInstance();
		$cacheDir = self::CACHE_DIR . sprintf('%02x', $blobId % self::CACHE_SHARDS);
		if ($cache->initCache(self::CACHE_TTL, 'blob_' . $blobId, $cacheDir))
		{
			$vars = $cache->getVars();
			if (is_array($vars) && is_bool($vars['isUtf8'] ?? null))
			{
				return $vars['isUtf8'];
			}
		}

		$bytes = $this->readBytes($blobId);
		if ($bytes === null)
		{
			return false;
		}

		$isUtf8 = mb_check_encoding($bytes, 'UTF-8');
		if ($cache->startDataCache())
		{
			$cache->endDataCache(['isUtf8' => $isUtf8]);
		}

		return $isUtf8;
	}

	private function readBytes(int $blobId): ?string
	{
		$fileArray = \CFile::makeFileArray($blobId);
		if (empty($fileArray['tmp_name']) || !is_file($fileArray['tmp_name']))
		{
			return null;
		}
		$bytes = file_get_contents($fileArray['tmp_name']);

		return $bytes === false ? null : $bytes;
	}
}
