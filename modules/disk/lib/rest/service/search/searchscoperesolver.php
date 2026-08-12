<?php

declare(strict_types=1);

namespace Bitrix\Disk\Rest\Service\Search;

use Bitrix\Disk\Folder;
use Bitrix\Disk\FolderLink;
use Bitrix\Disk\Security\DiskSecurityContext;
use Bitrix\Disk\Storage;
use Bitrix\Main\Result;

final class SearchScopeResolver
{
	private DiskSecurityContext $securityContext;

	public function __construct(int $userId)
	{
		$this->securityContext = new DiskSecurityContext($userId);
	}

	public function resolve(SearchRequest $request): Result
	{
		$result = new Result();
		$storageId = $request->getStorageId();
		$folderId = $request->getFolderId();

		if ($storageId !== null)
		{
			$storage = Storage::loadById($storageId, ['ROOT_OBJECT']);
			if (!$this->isReadableStorage($storage))
			{
				return $result->addError(SearchError::notFound());
			}

			if ($folderId === null && !$storage->isUseInternalRights())
			{
				return $result->addError(SearchError::unsupportedStorage());
			}
		}

		if ($folderId !== null)
		{
			$folder = Folder::loadById($folderId, ['STORAGE']);
			if ($folder === null || !$folder->canRead($this->securityContext))
			{
				return $result->addError(SearchError::notFound());
			}

			$folderStorage = $folder->getStorage();
			if ($folderStorage === null || ($storageId !== null && (int)$folderStorage->getId() !== $storageId))
			{
				return $result->addError(SearchError::notFound());
			}

			$scopeStorage = $folderStorage;
			if ($folder instanceof FolderLink)
			{
				$realFolder = $folder->getRealObject();
				$scopeStorage = $realFolder?->getStorage();
				if ($scopeStorage === null)
				{
					return $result->addError(SearchError::notFound());
				}

				$folderId = (int)$folder->getRealObjectId();
				$storageId = (int)$scopeStorage->getId();
			}

			if (!$scopeStorage->isUseInternalRights())
			{
				return $result->addError(SearchError::unsupportedStorage());
			}
		}

		return $result->setData([
			'scope' => new SearchScope($storageId, $folderId, $request->getType()),
		]);
	}

	private function isReadableStorage(?Storage $storage): bool
	{
		return $storage !== null && $storage->canRead($this->securityContext);
	}
}
