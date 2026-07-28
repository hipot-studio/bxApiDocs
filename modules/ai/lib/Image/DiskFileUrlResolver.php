<?php declare(strict_types=1);

namespace Bitrix\AI\Image;

use Bitrix\Disk\ExternalLink;
use Bitrix\Disk\File;
use Bitrix\Disk\Internals\ExternalLinkTable;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;

class DiskFileUrlResolver
{
	private const LINK_TTL_SECONDS = 3600;

	public function resolve(int $fileId, int $userId): ?string
	{
		if (!Loader::includeModule('disk'))
		{
			return null;
		}

		$diskFile = File::getById($fileId);
		if ($diskFile === null)
		{
			return null;
		}

		$securityContext = $diskFile->getStorage()?->getSecurityContext($userId);
		if ($securityContext === null || !$diskFile->canRead($securityContext))
		{
			return null;
		}

		$extLink = $this->getOrCreateExternalLink($diskFile, $userId);
		if ($extLink === null)
		{
			return null;
		}

		return $this->buildDownloadUrl($extLink);
	}

	private function getOrCreateExternalLink(File $diskFile, int $userId): ?ExternalLink
	{
		$extLinks = $diskFile->getExternalLinks([
			'filter' => [
				'OBJECT_ID' => $diskFile->getId(),
				'CREATED_BY' => $userId,
				'TYPE' => ExternalLinkTable::TYPE_AUTO,
				'IS_EXPIRED' => false,
			],
			'limit' => 1,
		]);

		$extLink = array_pop($extLinks);
		if ($extLink)
		{
			return $extLink;
		}

		return $diskFile->addExternalLink([
			'CREATED_BY' => $userId,
			'TYPE' => ExternalLinkTable::TYPE_AUTO,
			'DEATH_TIME' => DateTime::createFromTimestamp(time() + self::LINK_TTL_SECONDS),
		]) ?: null;
	}

	private function buildDownloadUrl(ExternalLink $extLink): string
	{
		$url = \Bitrix\Disk\Driver::getInstance()
			->getUrlManager()
			->getUrlExternalLink([
				'hash' => $extLink->getHash(),
				'action' => 'download',
			], true);

		return rtrim($url, '?&');
	}
}
