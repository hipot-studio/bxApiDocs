<?php
namespace Bitrix\Disk\Uf\Integration;

use Bitrix\Disk;
use Bitrix\Disk\AttachedObject;
use Bitrix\Disk\Document\DocumentHandler;
use Bitrix\Disk\Driver;
use Bitrix\Disk\File;
use Bitrix\Disk\Uf\FileUserType;
use Bitrix\Disk\Ui\Text;
use Bitrix\Disk\UI\Viewer\Renderer\Board;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ArgumentNullException;
use Bitrix\Main\ArgumentOutOfRangeException;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\NotImplementedException;
use Bitrix\Main\SystemException;
use Bitrix\UI\FileUploader\Contracts\CustomLoad;
use Bitrix\UI\FileUploader\Contracts\CustomRemove;
use Bitrix\UI\FileUploader\FileData;
use Bitrix\UI\FileUploader\FileInfo;
use Bitrix\UI\FileUploader\FileOwnershipCollection;
use Bitrix\UI\FileUploader\Configuration;
use Bitrix\UI\FileUploader\LoadResult;
use Bitrix\UI\FileUploader\LoadResultCollection;
use Bitrix\UI\FileUploader\PreviewImage;
use Bitrix\UI\FileUploader\PreviewImageOptions;
use Bitrix\UI\FileUploader\RemoveResult;
use Bitrix\UI\FileUploader\RemoveResultCollection;
use Bitrix\UI\FileUploader\UploaderController;
use Bitrix\UI\FileUploader\UploadRequest;
use Bitrix\UI\FileUploader\UploadResult;
use Bitrix\Disk\QuickAccess\FileDataParameterService;
use Bitrix\Main\Engine;

Loader::requireModule('ui');

class DiskUploaderController extends UploaderController implements CustomLoad, CustomRemove
{
	private Disk\UrlManager $urlManager;

	/** @var array<int, Disk\ObjectLock|null> Lock map keyed by disk object ID; populated by preloadLocks(). */
	private array $lockMap = [];

	public function __construct(array $options)
	{
		$controllerOptions = [
			'folderId' => 0,
		];

		if (isset($options['folderId']) && is_int($options['folderId']))
		{
			$controllerOptions['folderId'] = $options['folderId'];
		}

		$this->urlManager = Driver::getInstance()->getUrlManager();

		parent::__construct($controllerOptions);
	}

	public function isAvailable(): bool
	{
		return $GLOBALS['USER']->isAuthorized() && Loader::includeModule('disk');
	}

	public function getConfiguration(): Configuration
	{
		$configuration = new Configuration();
		$configuration->setMaxFileSize(null);
		$configuration->setTreatOversizeImageAsFile(true);
		$configuration->setIgnoreUnknownImageTypes(true);

		return $configuration;
	}

	public function canUpload(UploadRequest $uploadRequest = null): bool
	{
		[$folder, $storage] = $this->getFolderAndStorage($uploadRequest ? $uploadRequest->getName() : '');

		if ($folder === null || $storage === null)
		{
			return false;
		}

		return $folder->canAdd($storage->getCurrentUserSecurityContext());
	}

	public function onUploadComplete(UploadResult $uploadResult): void
	{
		$tempFile = $uploadResult->getTempFile();
		[$folder, $storage] = $this->getFolderAndStorage($tempFile->getFilename());
		if ($folder === null || $storage === null || !$folder->canAdd($storage->getCurrentUserSecurityContext()))
		{
			$uploadResult->addError(new Error('Access denied'));

			return;
		}

		$tempFile = $uploadResult->getTempFile();
		$diskFile = $folder->addFile(
			[
				'NAME' => Text::correctFilename($tempFile->getFilename()),
				'FILE_ID' => $tempFile->getFileId(),
				'SIZE' => $tempFile->getSize(),
				'CREATED_BY' => $tempFile->getCreatedBy(),
			],
			[],
			true
		);

		if ($diskFile)
		{
			$fileInfo = $this->createFileInfo($diskFile);
			$uploadResult->setFileInfo($fileInfo);
			$tempFile->makePersistent();
		}
		else
		{
			if (is_array($folder->getErrors()))
			{
				$uploadResult->addErrors($folder->getErrors());
			}
			else
			{
				$uploadResult->addError(new Error('The file has not been saved'));
			}

			$tempFile->delete();
		}
	}

	public function load(array $ids): LoadResultCollection
	{
		$userId = CurrentUser::get()->getId();

		// Phase 1: resolve IDs to models, run permission checks, collect validated models.
		/** @var LoadResult[] $loadResultByIndex Keyed by sequential index matching $ids. */
		$loadResultByIndex = [];
		/** @var (Disk\File|Disk\AttachedObject)[] $pendingModels Validated models, same index as $loadResultByIndex. */
		$pendingModels = [];
		foreach ($ids as $index => $id)
		{
			$loadResult = new LoadResult($id);
			[$type, $realValue] = FileUserType::detectType($id);
			if ($type == FileUserType::TYPE_NEW_OBJECT)
			{
				$fileModel = Disk\File::loadById($realValue, ['STORAGE']);
				if (!$fileModel)
				{
					$loadResult->addError(new Error('Could not find file'));
				}
				elseif (!$fileModel->canRead($fileModel->getStorage()->getCurrentUserSecurityContext()))
				{
					$loadResult->addError(new Error('Bad permission. Could not read this file'));
				}
				else
				{
					$pendingModels[$index] = $fileModel;
				}
			}
			else
			{
				$attachedModel = Disk\AttachedObject::loadById($realValue, ['OBJECT', 'VERSION']);
				if (!$attachedModel)
				{
					$loadResult->addError(new Error('Could not find attached object'));
				}
				elseif (!$attachedModel->canRead($userId))
				{
					$loadResult->addError(new Error('Bad permission. Could not read this file'));
				}
				else
				{
					$pendingModels[$index] = $attachedModel;
				}
			}

			$loadResultByIndex[$index] = $loadResult;
		}

		// Phase 2: batch-load locks for all validated models in one query (when lock feature is on).
		if (!empty($pendingModels))
		{
			$this->preloadLocks($pendingModels);
		}

		// Phase 3: build FileInfo for each validated model using the preloaded lock map.
		// Reset the lock map in finally so a thrown createFileInfo() cannot leave stale
		// entries for the next load() call on this controller instance.
		try
		{
			foreach ($pendingModels as $index => $fileModel)
			{
				$loadResult = $loadResultByIndex[$index];

				if ($fileModel instanceof Disk\AttachedObject)
				{
					$fileInfo = $this->createFileInfo($fileModel);
					if ($fileInfo instanceof FileInfo)
					{
						$loadResult->setFile($fileInfo);
					}
					else
					{
						$loadResult->addError(new Error('File not found or failed to load'));
					}
				}
				else
				{
					$fileInfo = $this->createFileInfo($fileModel);
					$loadResult->setFile($fileInfo);
				}
			}
		}
		finally
		{
			$this->lockMap = [];
		}

		$results = new LoadResultCollection();
		foreach ($loadResultByIndex as $loadResult)
		{
			$results->add($loadResult);
		}

		return $results;
	}

	public function remove(array $ids): RemoveResultCollection
	{
		$results = new RemoveResultCollection();
		$userId = (int)CurrentUser::get()->getId();;
		foreach ($ids as $id)
		{
			$removeResult = new RemoveResult($id);
			[$type, $realValue] = FileUserType::detectType($id);
			if ($type === FileUserType::TYPE_NEW_OBJECT)
			{
				$file = Disk\File::loadById($realValue, ['STORAGE']);
				if (!$file)
				{
					$removeResult->addError(new Error('Could not find file'));
				}
				elseif (!$file->canDelete($file->getStorage()->getCurrentUserSecurityContext()))
				{
					$removeResult->addError(new Error('Bad permission. Could not read this file'));
				}
				else if ($file->countAttachedObjects() != 0)
				{
					$removeResult->addError(new Error('Could not delete file which attached to entities'));
				}
				else if ($file->getGlobalContentVersion() != 1)
				{
					$removeResult->addError(new Error('Could not delete file which has a few versions'));
				}
				else
				{
					/** @var Disk\Folder $folder */
					[$folder] = $this->getFolderAndStorage($file->getOriginalName());
					if (!$file->getParent() || !$folder || $file->getParentId() !== $folder->getId())
					{
						$removeResult->addError(new Error('Could not delete file which is not located in folder for uploaded files.'));
					}
					else if (!$file->delete($userId))
					{
						$removeResult->addErrors($file->getErrors());
					}
				}
			}
			else
			{
				$removeResult->addError(new Error('Could not delete attached object'));
			}

			$results->add($removeResult);
		}

		return $results;
	}

	public static function getFileInfo(array $ids): array
	{
		$result = [];
		$controller = new static([]);
		$loadResults = $controller->load(array_unique($ids));
		foreach ($loadResults as $loadResult)
		{
			if ($loadResult->isSuccess() && $loadResult->getFile() !== null)
			{
				$result[] = $loadResult->getFile()->jsonSerialize();
			}
		}

		return $result;
	}

	public static function shouldTreatImageAsFile(array $fileData): bool
	{
		if (empty($fileData['FILE_SIZE']))
		{
			return false;
		}

		$controller = new static([]);
		$config = $controller->getConfiguration();

		$imageData = new FileData($fileData['ORIGINAL_NAME'], $fileData['CONTENT_TYPE'], $fileData['FILE_SIZE']);
		$imageData->setWidth($fileData['WIDTH'] ?? 0);
		$imageData->setHeight($fileData['HEIGHT'] ?? 0);

		return $config->shouldTreatImageAsFile($imageData);
	}

	/**
	 * Batch-loads ObjectLock records for a set of file models and populates $this->lockMap.
	 * Skips the query when the portal lock feature is disabled (no DB hit, no behaviour change).
	 * Handles auto-unlock: models whose lock has expired are unlocked immediately and removed from the map.
	 *
	 * @param array<int, Disk\File|Disk\AttachedObject> $fileModels
	 */
	private function preloadLocks(array $fileModels): void
	{
		if (!\Bitrix\Disk\Configuration::isEnabledObjectLock())
		{
			return;
		}

		// Resolve each model to the disk object ID used in b_disk_object_lock.
		// Disk\File: objectId = file->getId()
		// Disk\AttachedObject: objectId = underlying Disk\File->getId() (via getFile())
		$objectIds = [];
		foreach ($fileModels as $model)
		{
			$diskFile = $model instanceof Disk\File ? $model : $model->getFile();
			if ($diskFile !== null)
			{
				$objectIds[] = (int)$diskFile->getRealObjectId();
			}
		}

		if (empty($objectIds))
		{
			return;
		}

		$objectIds = array_unique($objectIds);
		$rawLocks = [];
		foreach (array_chunk($objectIds, 500) as $objectIdChunk)
		{
			$locks = Disk\Internals\ObjectLockTable::query()
				->setSelect(['ID', 'TOKEN', 'OBJECT_ID', 'CREATED_BY', 'CREATE_TIME', 'EXPIRY_TIME', 'TYPE', 'IS_EXCLUSIVE'])
				->whereIn('OBJECT_ID', $objectIdChunk)
				->exec()
			;

			while ($row = $locks->fetch())
			{
				$rawLocks[(int)$row['OBJECT_ID']] = $row;
			}
		}

		// Build the lock map; handle auto-unlock inline.
		$this->lockMap = array_fill_keys($objectIds, null);
		foreach ($rawLocks as $objectId => $row)
		{
			$lock = Disk\ObjectLock::buildFromArray($row);
			if ($lock instanceof Disk\ObjectLock && $lock->shouldProcessAutoUnlock())
			{
				// Expired lock: unlock the file and treat it as unlocked.
				$diskFile = null;
				foreach ($fileModels as $model)
				{
					$candidate = $model instanceof Disk\File ? $model : $model->getFile();
					if ($candidate !== null && (int)$candidate->getRealObjectId() === $objectId)
					{
						$diskFile = $candidate;
						break;
					}
				}

				if ($diskFile !== null)
				{
					$diskFile->unlock(\Bitrix\Disk\SystemUser::SYSTEM_USER_ID);
				}

				$this->lockMap[$objectId] = null;
			}
			else
			{
				$this->lockMap[$objectId] = $lock;
			}
		}
	}

	/**
	 * @param AttachedObject | File $fileModel
	 *
	 * @return FileInfo|null
	 * @throws ArgumentException
	 * @throws ArgumentNullException
	 * @throws ArgumentOutOfRangeException
	 * @throws NotImplementedException
	 * @throws SystemException
	 */
	private function createFileInfo(Disk\File|Disk\AttachedObject $fileModel): ?FileInfo
	{
		$fileInfo = FileInfo::createFromBFile($fileModel->getFileId());
		if ($fileInfo === null)
		{
			return null;
		}

		$id =
			$fileModel instanceof Disk\File
				? FileUserType::NEW_FILE_PREFIX . $fileModel->getId()
				: (int)$fileModel->getId()
		;

		$fileInfo->setId($id);
		$fileInfo->setName($fileModel->getName());

		$customData = [
			'fileId' => (int)$fileModel->getId(),
			'fileType' => null,
			'canRename' => false,
			'canMove' => false,
			'storage' => null,
			'objectId' => null,
			'isEditable' => false,
			'viewLink' => '',
			'isLocked' => false,
			'isLockedBySelf' => false,
		];

		$attachedObjectId = 0;
		$quickAccessGetParam = [];
		if ($fileModel instanceof Disk\AttachedObject)
		{
			$encryptedFileDataForQuickAccess = $this->getEncryptedFileDataForQuickAccess($fileModel);
			if ($encryptedFileDataForQuickAccess !== null)
			{
				$quickAccessGetParam[FileDataParameterService::PARAMETER_NAME] = $encryptedFileDataForQuickAccess;
			}
		}

		if ($fileModel instanceof Disk\File)
		{
			// File Model
			$customData['canRename'] = true;
			$customData['canMove'] = true;
			$customData['objectId'] = (int)$fileModel->getId();
			$customData['allowEdit'] = \Bitrix\Disk\Configuration::isEnabledDefaultEditInUf();
			$customData['isEditable'] = DocumentHandler::isEditable($fileModel->getExtension());

			$downloadUrl = Engine\UrlManager::getInstance()->create(
				'disk.file.download',
				['fileId' => $fileModel->getId()]
			);

			$fileInfo->setDownloadUrl($downloadUrl);

			$storage = $fileModel->getStorage();
			$customData['canUpdate'] = $storage && $fileModel->canUpdate($storage->getCurrentUserSecurityContext());
		}
		else
		{
			// Attached Object
			$customData['objectId'] = (int)$fileModel->getObjectId();
			$customData['allowEdit'] = (bool)$fileModel->getAllowEdit();
			$customData['isEditable'] =
				$fileModel->getFile() && DocumentHandler::isEditable($fileModel->getFile()->getExtension())
			;

			$downloadUrl = Engine\UrlManager::getInstance()->create(
				'disk.attachedObject.download',
				['attachedObjectId' => $id]
			);

			$downloadUrl->addParams($quickAccessGetParam);

			$fileInfo->setDownloadUrl($downloadUrl);

			$user = CurrentUser::get();
			$userId = $user ? $user->getId() : \Bitrix\Disk\Security\SecurityContext::GUEST_USER;
			$customData['canUpdate'] = $fileModel->canUpdate($userId);

			$realFile = $fileModel->getFile();
			$realFileStorage = $realFile?->getStorage();
			$customData['canRename'] =
				$realFile && $realFileStorage && $realFile->canRename($realFileStorage->getSecurityContext($userId));

			$attachedObjectId = $fileModel->getId();
		}

		$file = $fileModel instanceof Disk\File ? $fileModel : $fileModel->getFile();
		$supportsUnifiedLink = false;
		$isBoard = false;
		if ($file)
		{
			$customData['fileType'] = $file->getView()->getEditorTypeFile() ?: null;

			$storage = $file->getStorage();
			$folder = $file->getParent();
			if ($storage && $folder)
			{
				$currentUserId = (int)CurrentUser::get()->getId();
				$isMyDisk =
					$storage->getProxyType() instanceof \Bitrix\Disk\ProxyType\User
					&& (int)$storage->getEntityId() === $currentUserId
				;

				$storageName =
					$isMyDisk
						? $storage->getProxyType()->getTitleForCurrentUser()
						: $storage->getProxyType()->getEntityTitle()
				;

				$customData['storage'] = $storageName . ' / ' . ($folder->isRoot() ? '' : $folder->getName());
				$customData['createdBy'] = $file->getCreatedBy();
			}

			$supportsUnifiedLink = $file->supportsUnifiedLink();
			$isBoard = (int)$file->getTypeFile() === Disk\TypeFile::FLIPCHART;

			// Lock state is resolved here for ALL paths (Disk\File and AttachedObject alike),
			// because $file is the underlying Disk\File in both cases.
			if (\Bitrix\Disk\Configuration::isEnabledObjectLock())
			{
				$objectId = (int)$file->getRealObjectId();
				$lock = array_key_exists($objectId, $this->lockMap)
					? $this->lockMap[$objectId]
					: $file->getLock();
				if ($lock)
				{
					$customData['isLocked'] = true;
					$customData['isLockedBySelf'] = ((int)$lock->getCreatedBy() === (int)CurrentUser::get()->getId());
				}
			}
		}

		if (!$isBoard && $supportsUnifiedLink)
		{
			$unifiedLinkOptions = [];
			if ($attachedObjectId > 0)
			{
				$unifiedLinkOptions['attachedId'] = $attachedObjectId;
			}
			$unifiedViewLink = $this->urlManager->getUnifiedLink($file, $unifiedLinkOptions);
			$customData['viewLink'] = $unifiedViewLink;
		}

		$fileInfo->setCustomData($customData);

		if ($fileInfo->isImage())
		{
			$config = $this->getConfiguration();
			if ($config->shouldTreatImageAsFile($fileInfo))
			{
				$fileInfo->setTreatImageAsFile(true);
			}
			else
			{
				$previewOptions = ['width' => 1200, 'height' => 1200, ...$quickAccessGetParam]; // double size (see edit.php and html-parser.js)
				if ($fileModel instanceof Disk\File)
				{
					$previewUrl = $this->urlManager->getUrlForShowFile($fileModel, $previewOptions);
				}
				else
				{
					$previewUrl = \Bitrix\Disk\UrlManager::getUrlToActionShowUfFile($fileModel->getId(), $previewOptions);
				}

				$rectangle = PreviewImage::getSize($fileInfo, new PreviewImageOptions($previewOptions));
				$fileInfo->setPreviewUrl($previewUrl, $rectangle->getWidth(), $rectangle->getHeight());
			}
		}

		$fileInfo->setViewerAttrs($this->prepareViewerAttrs($fileModel, $downloadUrl, $fileInfo));

		return $fileInfo;
	}

	private function getEncryptedFileDataForQuickAccess(AttachedObject $fileModel): ?string
	{
		$fileDataParameterService = ServiceLocator::getInstance()->get('disk.fileDataParameterService');

		return $fileDataParameterService->getEncryptedFileData($fileModel);
	}

	private function getFolderAndStorage(string $filename): array
	{
		$folder = null;
		$storage = null;

		$folderId = $this->getOption('folderId', 0);
		if ($folderId > 0)
		{
			$folder = Disk\Folder::load(['ID' => $folderId]);
			if ($folder !== null)
			{
				$storage = $folder->getStorage();
			}
		}
		else
		{
			$userId = (int)CurrentUser::get()->getId();
			$storage = Driver::getInstance()->getStorageByUserId($userId);
			if ($storage !== null)
			{
				if (mb_strpos($filename, 'videomessage') === 0)
				{
					$folder = $storage->getFolderForRecordedFiles();
				}
				else
				{
					$folder = $storage->getFolderForUploadedFiles();
				}
			}
		}

		return [$folder, $storage];
	}

	/**
	 * @param AttachedObject | File $fileModel
	 * @param string $downloadUrl
	 * @param FileInfo $fileInfo
	 *
	 * @return array
	 * @throws ArgumentException
	 * @throws NotImplementedException
	 */
	private function prepareViewerAttrs(Disk\File|Disk\AttachedObject $fileModel, string $downloadUrl, FileInfo $fileInfo): array
	{
		$file = $fileModel instanceof Disk\File ? $fileModel : $fileModel->getFile();
		$viewerAttrs = Disk\Ui\FileAttributes::buildByFileId($fileModel->getFileId(), $downloadUrl, $file)
			->setTitle($fileModel->getName())
			->setObjectId($fileModel->getId())
		;

		if ($fileInfo->getPreviewUrl() !== null)
		{
			$viewerAttrs->setAttribute('data-viewer-preview', $fileInfo->getPreviewUrl());
		}

		if ($fileModel instanceof Disk\AttachedObject)
		{
			$viewerAttrs
				->setObjectId($fileModel->getObjectId())
				->setAttachedObjectId($fileModel->getId())
				->addAction([
					'type' => 'copyToMe',
					'text' => Loc::getMessage('DISK_UF_UPLOADER_CONTROLLER_ACTION_SAVE_TO_OWN_FILES'),
					'action' => 'BX.Disk.Viewer.Actions.runActionCopyToMe',
					'params' => [
						'attachedObjectId' => $fileModel->getId(),
					],
					'extension' => 'disk.viewer.actions',
					'buttonIconClass' => 'ui-btn-icon-cloud',
				])
			;
		}

		if ($viewerAttrs->getViewerType() === Board::JS_TYPE_BOARD && Disk\Document\Flipchart\Configuration::isBoardsEnabled())
		{
			/** @var Disk\File $file */
			$file = $fileModel instanceof Disk\AttachedObject ? $fileModel->getFile() : $fileModel;

			$uri = $this->urlManager->getUrlForViewBoard($file);

			$viewerAttrs->addAction([
				'type' => 'open',
				'buttonIconClass' => ' ',
				'action' => 'BX.Disk.Viewer.Actions.openInNewTab',
				'params' => [
					'objectId' => $file->getId(),
					'url' => $uri,
				],
			]);
		}

		return $viewerAttrs->toDataSet();
	}

	public function canView(): bool
	{
		return false;
	}

	public function canRemove(): bool
	{
		return false;
	}

	public function verifyFileOwner(FileOwnershipCollection $files): void
	{

	}
}
