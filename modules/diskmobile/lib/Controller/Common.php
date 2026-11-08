<?php

namespace Bitrix\DiskMobile\Controller;

use Bitrix\Main\Engine\ActionFilter\CloseSession;
use Bitrix\Disk;
use Bitrix\Main\Error;

class Common extends Base
{
	public function configureActions(): array
	{
		return [
			'getByIdsWithRights' => [
				'+prefilters' => [
					new CloseSession(),
				],
			],
			'getTrackedByIdWithRights' => [
				'+prefilters' => [
					new CloseSession(),
				],
			],
			'resolveFileId' => [
				'+prefilters' => [
					new CloseSession(),
				],
			],
			'getFileById' => [
				'+prefilters' => [
					new CloseSession(),
				],
			],
			'getFolderByPath' => [
				'+prefilters' => [
					new CloseSession(),
				],
			],
		];
	}

	public function getByIdWithRightsAction(int $id): ?array
	{
		/** @var array $page */
		$page = $this->forward(
			\Bitrix\Disk\Controller\CommonActions::class,
			'getByIds',
			['objectCollection' => [$id]],
		);

		if (!$this->errorCollection->isEmpty())
		{
			return null;
		}

		$diskObject = $page['items'][0]['object'];

		$rightsResult = $this->forward(
			\Bitrix\Disk\Controller\CommonActions::class,
			'getRights',
			['objectId' => $id],
		);

		if (!$this->errorCollection->isEmpty())
		{
			return null;
		}

		$diskObject['rights'] = $rightsResult['rights'];

		$externalLinkResult = $this->forward(
			\Bitrix\Disk\Controller\CommonActions::class,
			'getExternalLink',
			['objectId' => $id],
		);

		if (!$this->errorCollection->isEmpty())
		{
			return null;
		}

		$diskObject['links']['external'] = $externalLinkResult['externalLink'];

		return [
			'diskObject'=> $diskObject,
		];
	}

	public function getTrackedByIdWithRightsAction(int $id, bool $showRights = true): array
	{
		$trackedDiskObject = $this->forward(
			\Bitrix\Disk\Controller\TrackedObject::class,
			'get',
			['id' => $id, 'showRights' => $showRights],
		);

		if (!isset($trackedDiskObject) || !$this->errorCollection->isEmpty())
		{
			return [];
		}

		$diskObject = $this->trackedToItem($trackedDiskObject);

		return [
			'diskObject'=> $diskObject,
		];
	}

	public function getFileByIdAction(int $id): ?array
	{
		$diskObject = $this->buildReadableFile($this->loadFileById($id));
		if (!$diskObject)
		{
			return $this->denyFilePath();
		}

		return ['diskObject' => $diskObject];
	}

	public function resolveFileIdAction(
		string $entityType,
		string $path,
		string $entityId = '',
		string $fileBasePath = '',
	): ?array
	{
		$storage = $this->getFileStorage($entityType, $entityId, $fileBasePath);
		if (!$storage)
		{
			return $this->denyFilePath();
		}

		$id = Disk\Driver::getInstance()->getUrlManager()->resolveFileIdFromPath($storage, rawurldecode($path));
		$diskObject = $this->buildReadableFile($id ? $this->loadFileById((int)$id) : null);
		if (!$diskObject)
		{
			return $this->denyFilePath();
		}

		return [
			'objectId' => (int)$diskObject['id'],
			'diskObject' => $diskObject,
		];
	}

	private function buildReadableFile(?Disk\File $file): ?array
	{
		$securityContext = $file ? $this->getFileSecurityContext($file) : null;
		if (!$file || $file->isDeleted() || !$file->canRead($securityContext))
		{
			return null;
		}

		return [
			'id' => $file->getId(),
			'name' => $file->getName(),
			'typeFile' => (int)$file->getTypeFile(),
			'canDownload' => $file->canDownload($securityContext),
		];
	}

	protected function loadFileById(int $id): ?Disk\File
	{
		return Disk\File::loadById($id);
	}

	protected function getFileSecurityContext(Disk\File $file): Disk\Security\SecurityContext
	{
		return $file->getStorage()->getSecurityContext($this->getCurrentUser());
	}

	protected function getFileStorage(string $entityType, string $entityId, string $fileBasePath): ?Disk\Storage
	{
		$driver = Disk\Driver::getInstance();
		if (in_array($entityType, [Disk\ProxyType\Type::USER->value, Disk\ProxyType\Type::GROUP->value], true))
		{
			$entityId = filter_var($entityId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
			if ($entityId === false)
			{
				return null;
			}

			return $entityType === Disk\ProxyType\Type::USER->value
				? $driver->getStorageByUserId($entityId)
				: $driver->getStorageByGroupId($entityId)
			;
		}

		if ($entityType !== Disk\ProxyType\Type::COMMON->value || $fileBasePath === '')
		{
			return null;
		}

		$storages = Disk\Storage::getModelList([
			'filter' => ['=MODULE_ID' => 'disk', '=ENTITY_TYPE' => Disk\ProxyType\Common::class],
			'select' => ['*'],
		]);
		foreach ($storages as $storage)
		{
			if ($storage && $fileBasePath === $storage->getProxyType()->getBaseUrlFileDetail())
			{
				return $storage;
			}
		}

		return null;
	}

	private function denyFilePath(): ?array
	{
		$this->addError(new Error('File is not available', 'DISKMOBILE_FILE_PATH_DENIED'));

		return null;
	}

	public function getFolderByPathAction(string $entityType, string $entityId, string $path): ?array
	{
		/** @var array $page */
		$resolvedPath = $this->forward(
			\Bitrix\Disk\Controller\CommonActions::class,
			'resolveFolderPath',
		);

		if (!$this->errorCollection->isEmpty() || !isset($resolvedPath['targetFolder']))
		{
			return null;
		}

		$targetFolderId = $resolvedPath['targetFolder']->getId();

		$targetFolder = $this->forward(
			self::class,
			'getByIdWithRights',
			['id' => $targetFolderId],
		);

		if ($this->errorCollection->isEmpty())
		{
			return $targetFolder;
		}

		return null;
	}
}
