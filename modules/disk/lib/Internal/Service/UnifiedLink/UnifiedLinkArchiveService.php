<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\UnifiedLink;

use Bitrix\Disk\Folder;
use Bitrix\Disk\Security\ParameterSigner;
use Bitrix\Disk\Type\ObjectCollection;
use Bitrix\Disk\ZipNginx\Archive;
use Bitrix\Main\ArgumentTypeException;

class UnifiedLinkArchiveService
{
	/**
	 * @param ObjectCollection $objectCollection
	 * @param int $userId
	 * @param bool $shouldCheckUnifiedLink
	 * @return bool
	 */
	public function isFileLimitExceeded(
		ObjectCollection $objectCollection,
		int $userId,
		bool $shouldCheckUnifiedLink = true,
	): bool
	{
		if ($shouldCheckUnifiedLink)
		{
			return Archive::isFileLimitExceededByUnifiedObjects($objectCollection);
		}

		return Archive::isFileLimitExceededByObjects($objectCollection, $userId);
	}

	/**
	 * @param Folder $parentFolder
	 * @param ObjectCollection $objectCollection
	 * @param bool $skipRootFolder
	 * @return array
	 * @throws ArgumentTypeException
	 */
	public function getSignedArchiveParameters(
		Folder $parentFolder,
		ObjectCollection $objectCollection,
		bool $skipRootFolder = false,
	): array
	{
		$parentFolderUniqueCode = $parentFolder->getUniqueCode();
		$objectUniqueCodes = $objectCollection->getUniqueCodes();

		return [
			'uniqueCode' => $parentFolderUniqueCode,
			'uniqueCodes' => $objectUniqueCodes,
			'skipRootFolder' => (int)$skipRootFolder,
			'signature' => ParameterSigner::getUnifiedArchiveSignature(
				parentUniqueCode: $parentFolderUniqueCode,
				uniqueCodes: $objectUniqueCodes,
				skipRootFolder: $skipRootFolder,
			),
		];
	}

	/**
	 * @param ObjectCollection $objectCollection
	 * @param int $userId
	 * @param bool $skipRootFolder
	 * @param bool $shouldCheckUnifiedLink
	 * @return Archive
	 */
	public function createArchive(
		ObjectCollection $objectCollection,
		int $userId,
		bool $skipRootFolder = false,
		bool $shouldCheckUnifiedLink = true,
	): Archive
	{
		$archiveName = 'archive' . date('y-m-d');

		if ($shouldCheckUnifiedLink)
		{
			return Archive::createByUnifiedObjects($archiveName, $objectCollection, $skipRootFolder);
		}

		return Archive::createByObjects($archiveName, $objectCollection, $userId, $skipRootFolder);
	}
}
