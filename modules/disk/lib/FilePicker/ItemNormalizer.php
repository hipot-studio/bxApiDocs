<?php

declare(strict_types=1);

namespace Bitrix\Disk\FilePicker;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\Driver;
use Bitrix\Disk\File;
use Bitrix\Disk\Folder;
use Bitrix\Disk\ProxyType;
use Bitrix\Disk\Storage;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type\DateTime;
use Bitrix\Socialnetwork\Item\Workgroup\Type;
use Bitrix\Socialnetwork\Provider\GroupProvider;

Loc::loadMessages(__FILE__);

final class ItemNormalizer
{
	public const IM_STORAGE_PROXY_TYPE = 'Bitrix\\Im\\Disk\\ProxyType\\Im';

	private array $storageTypeCache = [];
	private readonly \Closure $projectStorageChecker;

	public function __construct(
		private readonly FileTypeClassifier $fileTypeClassifier = new FileTypeClassifier(),
		private readonly ViewerSupport $viewerSupport = new ViewerSupport(),
		?\Closure $projectStorageChecker = null,
	)
	{
		$this->projectStorageChecker = $projectStorageChecker ?? $this->isProjectStorage(...);
	}

	/**
	 * Prepares the set for the fields whose answer lives outside the object row, so that normalizing an
	 * item of the set costs no query of its own.
	 *
	 * @param array<BaseObject> $objects
	 */
	public function warmUpItems(array $objects): void
	{
		$this->viewerSupport->warmUpLinkedFiles($objects);
	}

	public function normalize(BaseObject $object): ?array
	{
		$objectId = (int)$object->getId();
		$source = $this->normalizeSource($object->getStorage());
		if ($objectId <= 0 || $source === null)
		{
			return null;
		}

		$isFile = $object instanceof File;

		return [
			'objectId' => $objectId,
			'type' => $isFile ? 'file' : 'folder',
			'name' => $this->normalizeObjectName($object, $isFile),
			'extension' => $isFile ? $this->normalizeExtension($object->getExtension()) : null,
			'size' => $isFile ? (int)$object->getSize() : null,
			'updateTime' => $this->formatDateTime($object->getUpdateTime()),
			'createTime' => $this->formatDateTime($object->getCreateTime()),
			'recentTime' => null,
			'selectable' => $isFile,
			'fileType' => $isFile ? $this->fileTypeClassifier->classify($object) : null,
			'source' => $source,
			'preview' => $isFile ? $this->normalizePreview($object) : null,
			'previewable' => $isFile ? $this->viewerSupport->isPreviewable($object) : null,
		];
	}

	public function normalizeSelection(File $file, Folder $parentFolder): ?array
	{
		$parentId = (int)$file->getParentId();
		$item = $this->normalize($file);
		if (
			$item === null
			|| $parentId <= 0
			|| (int)$parentFolder->getId() !== $parentId
			|| (int)$parentFolder->getStorageId() !== $item['source']['storageId']
		)
		{
			return null;
		}

		$editorFileType = trim((string)$file->getView()->getEditorTypeFile());

		return [
			...$item,
			'parentFolderName' => $this->normalizeFolderName((string)$parentFolder->getName()),
			'editorFileType' => $editorFileType === '' ? null : $editorFileType,
		];
	}

	public function normalizeSourceDescriptor(
		Storage $storage,
		string $title,
		?string $avatarUrl = null,
	): ?array
	{
		$source = $this->normalizeSource($storage);
		$rootObject = $storage->getRootObject();
		$folderId = (int)($rootObject?->getId() ?? 0);
		if (
			$source === null
			|| !$rootObject instanceof Folder
			|| $folderId <= 0
			|| (int)$rootObject->getStorageId() !== $source['storageId']
		)
		{
			return null;
		}

		return [
			'storageId' => $source['storageId'],
			'folderId' => $folderId,
			'title' => $this->normalizeStorageTitle($title, $source['storageType']),
			'storageType' => $source['storageType'],
			'entityId' => $source['entityId'],
			'avatarUrl' => is_string($avatarUrl) && trim($avatarUrl) !== '' ? $avatarUrl : null,
		];
	}

	public function normalizeNavigationContext(
		Storage $storage,
		Folder $folder,
		array $parents,
		int $currentUserId,
	): ?array
	{
		$storageId = (int)$storage->getId();
		$folderId = (int)$folder->getId();
		if ($storageId <= 0 || $folderId <= 0 || (int)$folder->getStorageId() !== $storageId)
		{
			return null;
		}

		$breadcrumbs = [];
		$seenIds = [];
		foreach ($parents as $parent)
		{
			if (!$parent instanceof Folder || (int)$parent->getStorageId() !== $storageId)
			{
				continue;
			}

			$parentId = (int)$parent->getId();
			if ($parentId <= 0 || $parentId === $folderId || isset($seenIds[$parentId]))
			{
				continue;
			}

			$seenIds[$parentId] = true;
			$breadcrumbs[] = [
				'objectId' => $parentId,
				'name' => $this->normalizeNavigationFolderName($storage, $parent, $currentUserId),
			];
		}

		return [
			'storageId' => $storageId,
			'folderId' => $folderId,
			'currentFolder' => [
				'folderId' => $folderId,
				'name' => $this->normalizeNavigationFolderName($storage, $folder, $currentUserId),
			],
			'breadcrumbs' => $breadcrumbs,
		];
	}

	private function normalizeSource(?Storage $storage): ?array
	{
		if ($storage === null)
		{
			return null;
		}

		$storageId = (int)$storage->getId();
		$storageType = $this->resolveStorageType($storage);
		$entityId = $storage->getEntityId();
		if (
			$storageId <= 0
			|| $storageType === null
			|| (!is_int($entityId) && !is_string($entityId) && $entityId !== null)
		)
		{
			return null;
		}

		return [
			'storageId' => $storageId,
			'storageType' => $storageType,
			'title' => $this->normalizeStorageTitle((string)$storage->getName(), $storageType),
			'entityId' => $entityId,
		];
	}

	private function normalizeObjectName(BaseObject $object, bool $isFile): string
	{
		$name = (string)$object->getName();
		if (trim($name) !== '')
		{
			return $name;
		}

		return $this->getRequiredMessage(
			$isFile ? 'DISK_FILE_PICKER_FILE_WITHOUT_NAME' : 'DISK_FILE_PICKER_FOLDER_WITHOUT_NAME',
		);
	}

	private function normalizeFolderName(string $name): string
	{
		return trim($name) === ''
			? $this->getRequiredMessage('DISK_FILE_PICKER_FOLDER_WITHOUT_NAME')
			: $name;
	}

	private function normalizeNavigationFolderName(
		Storage $storage,
		Folder $folder,
		int $currentUserId,
	): string
	{
		$proxyType = $storage->getProxyType();
		if (
			$currentUserId > 0
			&& (int)$folder->getId() === (int)$storage->getRootObjectId()
			&& $proxyType instanceof ProxyType\User
			&& (int)$storage->getEntityId() === $currentUserId
		)
		{
			return $this->normalizeStorageTitle((string)$proxyType->getTitleForCurrentUser(), 'user');
		}

		return $this->normalizeFolderName($folder->getName());
	}

	private function normalizeStorageTitle(string $title, string $storageType): string
	{
		if (trim($title) !== '')
		{
			return $title;
		}

		return $this->getRequiredMessage('DISK_FILE_PICKER_STORAGE_' . mb_strtoupper($storageType));
	}

	private function getRequiredMessage(string $messageCode): string
	{
		$message = (string)Loc::getMessage($messageCode);

		return trim($message) === '' ? $messageCode : $message;
	}

	private function normalizeExtension(mixed $extension): ?string
	{
		$extension = mb_strtolower(ltrim(trim((string)$extension), '.'));

		return $extension === '' ? null : $extension;
	}

	private function normalizePreview(File $file): array
	{
		if (!$this->fileTypeClassifier->isImage($file))
		{
			return ['type' => 'none', 'url' => null];
		}

		$url = Driver::getInstance()->getUrlManager()->getUrlForShowFile(
			$file,
			[
				'width' => 400,
				'height' => 400,
			],
		);
		if (!is_string($url) || trim($url) === '')
		{
			return ['type' => 'none', 'url' => null];
		}

		return ['type' => 'image', 'url' => $url];
	}

	private function resolveStorageType(Storage $storage): ?string
	{
		$storageId = (int)$storage->getId();
		if (array_key_exists($storageId, $this->storageTypeCache))
		{
			return $this->storageTypeCache[$storageId];
		}
		if ($storage->getEntityType() === self::IM_STORAGE_PROXY_TYPE)
		{
			$this->storageTypeCache[$storageId] = 'im';

			return 'im';
		}

		$proxyType = $storage->getProxyType();
		$type = match (true)
		{
			$proxyType instanceof ProxyType\User => 'user',
			$proxyType instanceof ProxyType\Common => 'common',
			$proxyType instanceof ProxyType\Group && $proxyType->isCollab() => 'collab',
			$proxyType instanceof ProxyType\Group && ($this->projectStorageChecker)($storage) => 'project',
			$proxyType instanceof ProxyType\Group => 'group',
			default => null,
		};

		$this->storageTypeCache[$storageId] = $type;

		return $type;
	}

	private function isProjectStorage(Storage $storage): bool
	{
		$groupId = (int)$storage->getEntityId();
		if ($groupId <= 0 || !Loader::includeModule('socialnetwork'))
		{
			return false;
		}

		// scrum groups are projects for the picker: they are stored with PROJECT = Y
		return \in_array(
			GroupProvider::getInstance()->getGroupType($groupId),
			[Type::Project, Type::Scrum],
			true,
		);
	}

	private function formatDateTime(?DateTime $dateTime): ?string
	{
		return $dateTime?->format('c');
	}
}
