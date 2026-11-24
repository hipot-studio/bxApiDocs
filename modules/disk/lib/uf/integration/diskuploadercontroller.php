<?php
namespace Bitrix\Disk\Uf\Integration;

use Bitrix\Disk;
use Bitrix\Disk\AttachedObject;
use Bitrix\Disk\Document\DocumentHandler;
use Bitrix\Disk\Driver;
use Bitrix\Disk\File;
use Bitrix\Disk\Internal\Service\DockedUpload\FolderResolverService;
use Bitrix\Disk\Internal\Enum\WriteOperationContext;
use Bitrix\Disk\Internal\Service\Quota\PersonalQuotaService;
use Bitrix\Disk\Public\Service\Restrictions\WriteErrorTransportMap;
use Bitrix\Disk\Internal\Service\Quota\UploadQuotaScenario;
use Bitrix\Disk\Internal\Service\UploadCleanup\CleanupGuard;
use Bitrix\Disk\Internal\Service\UploadCleanup\MarkerService;
use Bitrix\Disk\Uf\FileUserType;
use Bitrix\Disk\Ui\Text;
use Bitrix\Disk\UI\Viewer\Renderer\Board;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ArgumentNullException;
use Bitrix\Main\ArgumentOutOfRangeException;
use Bitrix\Main\Context;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\File\Image;
use Bitrix\Main\File\Image\Rectangle;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\NotImplementedException;
use Bitrix\Main\ObjectException;
use Bitrix\Main\SystemException;
use Bitrix\UI\FileUploader\CanUploadResult;
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
use Bitrix\UI\FileUploader\UploaderError;
use Bitrix\UI\FileUploader\UploadRequest;
use Bitrix\UI\FileUploader\UploadResult;
use Bitrix\Disk\QuickAccess\FileDataParameterService;
use Bitrix\Disk\Security\SecurityContext;
use Bitrix\Main\Engine;
use CFile;

Loader::requireModule('ui');

class DiskUploaderController extends UploaderController implements CustomLoad, CustomRemove
{
	public const ERROR_DISK_QUOTA_EXCEEDED = 'DISK_QUOTA_EXCEEDED';

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

		if (isset($options['cleanupTokens']) && is_array($options['cleanupTokens']))
		{
			$cleanupTokens = [];
			foreach ($options['cleanupTokens'] as $wireId => $token)
			{
				if (is_string($wireId) && is_string($token))
				{
					$cleanupTokens[$wireId] = $token;
				}
			}

			if (!empty($cleanupTokens))
			{
				$controllerOptions['cleanupTokens'] = $cleanupTokens;
			}
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

	public function canUpload(UploadRequest $uploadRequest = null): CanUploadResult|bool
	{
		[$folder, $storage] = $this->getFolderAndStorage($uploadRequest ? $uploadRequest->getName() : '');

		if ($folder === null || $storage === null)
		{
			return false;
		}

		if (!$folder->canAdd($storage->getCurrentUserSecurityContext()))
		{
			return false;
		}

		if ($uploadRequest !== null)
		{
			// A format nothing can save is named before a quota the user can free up, and the name
			// is judged before the counter of the storage is read: the same order the write node keeps.
			$formatRefusal = $this->findFormatRefusal($uploadRequest->getName());
			if ($formatRefusal !== null)
			{
				return $formatRefusal;
			}

			$quotaRefusal = $this->findPersonalQuotaRefusal(
				$this->findStorageOfWrite($folder, $storage),
				$uploadRequest->getSize(),
			);
			if ($quotaRefusal !== null)
			{
				return $quotaRefusal;
			}
		}

		return true;
	}

	/**
	 * Refuses a restricted format by its name alone, before the content is accepted. The verdict
	 * of the write node stands whatever this one says: the early answer only spares the transfer
	 * of a file that would be refused anyway, so a format given away by the content only is
	 * refused later, on the same transport.
	 *
	 * The file is judged by the policy of the user uploading it.
	 *
	 * @param string $fileName Name the file arrives under.
	 * @return CanUploadResult|null
	 */
	private function findFormatRefusal(string $fileName): ?CanUploadResult
	{
		$options = [];
		$error = File::findFormatPolicyErrorForUpload(
			[],
			Text::correctFilename($fileName),
			(int)CurrentUser::get()->getId(),
			$options,
		);
		if ($error === null)
		{
			return null;
		}

		$refusal = new CanUploadResult();
		$refusal->addErrors($this->makeTransportErrors([$error]));

		return $refusal;
	}

	/**
	 * Refuses a file that would not fit the personal quota by the size the upload declares, before
	 * the content is accepted. As with the format, the verdict of the write node stands whatever
	 * this one says: a size declared short of the one that arrives - a chunked upload declares the
	 * first chunk alone - is let through here and refused there.
	 *
	 * An attachment usually lands in the personal storage of the employee uploading it, so the
	 * refusal is told in words of this uploader rather than in those of the write node.
	 *
	 * @param Disk\Storage|null $storage Storage the file lands in.
	 * @param int $fileSize Size the upload declares, bytes.
	 * @return CanUploadResult|null
	 */
	private function findPersonalQuotaRefusal(?Disk\Storage $storage, int $fileSize): ?CanUploadResult
	{
		/** @var PersonalQuotaService $quotaService */
		$quotaService = ServiceLocator::getInstance()->get(PersonalQuotaService::class);
		$result = $quotaService->canWriteToStorage($storage, $fileSize, WriteOperationContext::UserUpload);
		if ($result->isSuccess())
		{
			return null;
		}

		$refusalData = ($result->getErrors()[0] ?? null)?->getCustomData();
		$error = new Error(
			$this->makePersonalQuotaMessage($storage, $refusalData),
			File::ERROR_PERSONAL_QUOTA_EXCEEDED,
		);

		$refusal = new CanUploadResult();
		$refusal->addErrors($this->makeTransportErrors([$error]));

		return $refusal;
	}

	/**
	 * The disk the upload actually lands in. A link is a folder of its own only to the one it was
	 * shared with: the content goes to the folder it points at ({@see Disk\FolderLink::uploadFile()})
	 * and spends the disk that folder lies in. A folder that is no link lies in the storage the
	 * caller has already resolved, so the common path costs no look-up at all.
	 *
	 * A link whose target is gone, or is no folder at all, is left to the write node.
	 *
	 * @param Disk\Folder|null $folder Folder the upload is addressed to.
	 * @param Disk\Storage|null $storage Storage that folder is seen in.
	 * @return Disk\Storage|null
	 */
	private function findStorageOfWrite(?Disk\Folder $folder, ?Disk\Storage $storage): ?Disk\Storage
	{
		if ($folder === null || !$folder->isLink())
		{
			return $storage;
		}

		try
		{
			return $folder->getRealObject()?->getStorage();
		}
		catch (ObjectException)
		{
			return null;
		}
	}

	/**
	 * The words the refusal carries. The employee the disk belongs to is told how much of it is
	 * spent and what to free up; everyone else uploading into a disk of somebody else is told who
	 * to ask, and nothing about the state of a disk that is not theirs to know.
	 *
	 * @param Disk\Storage|null $storage Storage the file lands in.
	 * @param array|null $refusalData Sizes the refusal was measured by.
	 * @return string
	 */
	private function makePersonalQuotaMessage(?Disk\Storage $storage, ?array $refusalData): string
	{
		$ownerId = (int)$storage?->getEntityId();
		if ($ownerId <= 0 || $ownerId !== (int)CurrentUser::get()->getId())
		{
			return (string)Loc::getMessage('DISK_UF_UPLOADER_CONTROLLER_ERROR_PERSONAL_QUOTA_EXCEEDED_FOREIGN');
		}

		return (string)Loc::getMessage('DISK_UF_UPLOADER_CONTROLLER_ERROR_PERSONAL_QUOTA_EXCEEDED', [
			'#USED#' => CFile::formatSize((int)($refusalData[PersonalQuotaService::REFUSAL_DATA_USED] ?? 0)),
			'#LIMIT#' => CFile::formatSize((int)($refusalData[PersonalQuotaService::REFUSAL_DATA_LIMIT] ?? 0)),
		]);
	}

	/**
	 * Domain refusals of the write, each one in the form its transport knows. A refusal the
	 * transport map says nothing about goes to the client as it is.
	 *
	 * @param Error[] $errors
	 * @return Error[]
	 */
	private function makeTransportErrors(array $errors): array
	{
		$transportErrors = [];
		foreach ($errors as $error)
		{
			$code = WriteErrorTransportMap::getUploaderErrorCode((string)$error->getCode());
			$transportErrors[] = $code === null
				? $error
				: new UploaderError($code, $error->getMessage())
			;
		}

		return $transportErrors;
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
				'DISABLE_RECENTLY_USED' => true,
			],
			[],
			true
		);

		if ($diskFile)
		{
			$currentUserId = (int)CurrentUser::get()->getId();
			$fileInfo = null;
			$cleanupToken = null;
			$finalizationFailed = false;
			try
			{
				$cleanupMarkerRequired = (new MarkerService())->isAvailable();
				if ($cleanupMarkerRequired)
				{
					$cleanupToken = $this->issueCleanupToken($diskFile, $currentUserId);
					$finalizationFailed = $cleanupToken === null;
				}

				if (!$finalizationFailed)
				{
					$fileInfo = $this->createFileInfo($diskFile);
					$finalizationFailed = $fileInfo === null;
				}
			}
			catch (\Throwable)
			{
				$finalizationFailed = true;
			}

			if ($finalizationFailed)
			{
				$this->rollbackIncompleteUpload(
					$uploadResult,
					$tempFile,
					$diskFile,
					$currentUserId,
				);

				return;
			}
			if ($cleanupToken !== null)
			{
				$fileInfo->getCustomData()->set('cleanupToken', $cleanupToken);
			}

			$uploadResult->setFileInfo($fileInfo);
			$tempFile->makePersistent();
		}
		else
		{
			if (is_array($folder->getErrors()))
			{
				$uploadResult->addErrors($this->makeTransportErrors($folder->getErrors()));
			}
			else
			{
				$uploadResult->addError(new Error('The file has not been saved'));
			}

			$tempFile->delete();
		}
	}

	public function onUploadError(UploadResult $uploadResult): void
	{
		$errors = $uploadResult->getErrors();
		if (
			count($errors) !== 1
			|| $errors[0]->getCode() !== UploaderError::CHECK_FILE_FAILED
			|| ($errors[0]->getCustomData()['reason'] ?? null) !== UploaderError::FILE_VALIDATION_REASON_DISK_QUOTA
		)
		{
			return;
		}

		if (!$this->isDiskQuotaExceeded($uploadResult))
		{
			return;
		}

		// Only the portal quota is known here, but a portal-wide limit set by a box admin has no tariff
		// behind it: the scenario decides whether there is a limit popup to offer at all. The wire code
		// stays the one the client recognizes as a shortage.
		$stop = (new UploadQuotaScenario())->classify(
			storageRestrictionHit: false,
			portalQuotaHit: true,
		);

		$uploadResult->getErrorCollection()->clear();
		$uploadResult->addError(new UploaderError(
			self::ERROR_DISK_QUOTA_EXCEEDED,
			Loc::getMessage('DISK_UF_UPLOADER_CONTROLLER_ERROR_QUOTA_EXCEEDED'),
			['infoHelperCode' => $stop?->getInfoHelperCode()],
		));
	}

	protected function isDiskQuotaExceeded(UploadResult $uploadResult): bool
	{
		$size = (int)($uploadResult->getTempFile()?->getSize() ?? 0);
		if ($size <= 0)
		{
			$size = (new UploadRequestSizeResolver())->resolve(Context::getCurrent()->getRequest());
		}

		return $size > 0 && !$this->hasDiskQuota($size);
	}

	protected function hasDiskQuota(int $size): bool
	{
		return (new \CDiskQuota())->checkDiskQuota(['size' => $size]);
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
		$currentUserId = (int)CurrentUser::get()->getId();
		$results = new RemoveResultCollection();
		$claimedFiles = [];
		foreach ($ids as $id)
		{
			$removeResult = new RemoveResult($id);
			[$type, $realValue] = FileUserType::detectType($id);
			if ($type === FileUserType::TYPE_NEW_OBJECT)
			{
				$file = Disk\File::loadById($realValue, ['STORAGE']);
				if (!$file)
				{
					if (!$this->isConsumedCleanupReplay((string)$id, (int)$realValue, $currentUserId))
					{
						$removeResult->addError(new Error('Could not find file'));
					}
				}
				else
				{
					$claimToken = $this->claimCleanup((string)$id, $file, $currentUserId);
					if ($claimToken !== null)
					{
						$claimedFiles[(int)$realValue] = [
							'fileId' => (int)$realValue,
							'claimToken' => $claimToken,
							'removeResult' => $removeResult,
						];
					}
					elseif (isset($claimedFiles[(int)$realValue]))
					{
						$removeResult->addError(new Error('Could not delete file which is already in use'));
					}
					else
					{
						$this->removeLegacyFile($file, $currentUserId, $removeResult);
					}
				}
			}
			else
			{
				$removeResult->addError(new Error('Could not delete attached object'));
			}

			$results->add($removeResult);
		}
		$this->removeClaimedFiles($claimedFiles, $currentUserId);

		return $results;
	}

	protected function issueCleanupToken(Disk\File $file, int $currentUserId): ?string
	{
		$parent = $file->getParent();
		if ($parent === null || (int)$file->getCreatedBy() !== $currentUserId)
		{
			return null;
		}

		try
		{
			$token = UploadCleanupGrant::issueFor(
				(int)$file->getId(),
				$currentUserId,
				(int)$parent->getId(),
			);
			$carrier = UploadCleanupGrant::openV2($token);
			if ($carrier === null || !(new MarkerService())->issue($carrier, $token))
			{
				return null;
			}

			return $token;
		}
		catch (\Throwable)
		{
			return null;
		}
	}

	private function rollbackIncompleteUpload(
		UploadResult $uploadResult,
		\Bitrix\UI\FileUploader\TempFile $tempFile,
		Disk\File $diskFile,
		int $currentUserId,
	): void
	{
		$uploadResult->addError(new UploaderError(UploaderError::SAVE_FILE_FAILED));
		$uploadResult->setFileInfo(null);

		try
		{
			$claimToken = (new MarkerService())->claimOrIssueRecovery(
				(int)$diskFile->getId(),
				$currentUserId,
				(int)$diskFile->getParentId(),
			);
			if ($claimToken !== null)
			{
				$wireId = FileUserType::NEW_FILE_PREFIX . $diskFile->getId();
				$removeResult = new RemoveResult($wireId);
				$this->removeClaimedFiles([
					(int)$diskFile->getId() => [
						'fileId' => (int)$diskFile->getId(),
						'claimToken' => $claimToken,
						'removeResult' => $removeResult,
					],
				], $currentUserId);
				$uploadResult->addErrors($removeResult->getErrors());
			}
		}
		catch (\Throwable)
		{
			// The upload is not exposed; deleting it without a recovery claim would be unsafe.
		}

		try
		{
			$tempFile->customData->set('deleteBFile', false);
			$tempFileDeleteResult = $tempFile->delete();
			if (!$tempFileDeleteResult->isSuccess())
			{
				$uploadResult->addErrors($tempFileDeleteResult->getErrors());
			}
		}
		catch (\Throwable)
		{
			// The recovery claim remains authoritative when temporary metadata cleanup fails.
		}
	}

	private function claimCleanup(string $wireId, Disk\File $file, int $currentUserId): ?string
	{
		if (preg_match('/^n[1-9][0-9]*$/D', $wireId) !== 1)
		{
			return null;
		}

		$cleanupTokens = $this->getOption('cleanupTokens', []);
		$token = $cleanupTokens[$wireId] ?? null;
		if (!is_string($token))
		{
			return null;
		}

		$carrier = UploadCleanupGrant::openV2($token);
		if (
			$carrier === null
			|| $carrier['f'] !== (int)$file->getId()
			|| $carrier['u'] !== $currentUserId
			|| $carrier['d'] !== (int)$file->getParentId()
		)
		{
			return null;
		}

		return (new MarkerService())->claim($carrier, $token);
	}

	private function isConsumedCleanupReplay(string $wireId, int $fileId, int $currentUserId): bool
	{
		if (preg_match('/^n[1-9][0-9]*$/D', $wireId) !== 1)
		{
			return false;
		}

		$cleanupTokens = $this->getOption('cleanupTokens', []);
		$token = $cleanupTokens[$wireId] ?? null;
		if (!is_string($token))
		{
			return false;
		}

		$carrier = UploadCleanupGrant::openV2($token);
		if (
			$carrier === null
			|| $carrier['f'] !== $fileId
			|| $carrier['u'] !== $currentUserId
		)
		{
			return false;
		}

		return (new MarkerService())->matchesConsumedProof($carrier, $token);
	}

	private function resolveDeleteActor(
		Disk\File $file,
		SecurityContext $securityContext,
		bool $hasProof,
		int $currentUserId,
		RemoveResult $removeResult,
	): ?int
	{
		if ($hasProof)
		{
			if (!$file->canMarkDeleted($securityContext))
			{
				$removeResult->addError(new Error('Bad permission. Could not read this file'));

				return null;
			}

			if ((int)$file->getCreatedBy() !== $currentUserId)
			{
				$removeResult->addError(new Error('Could not delete file created by another user'));

				return null;
			}

			return $currentUserId;
		}

		if (!$file->canDelete($securityContext))
		{
			$removeResult->addError(new Error('Bad permission. Could not read this file'));

			return null;
		}

		return $currentUserId;
	}

	private function passesRemovalGuards(
		Disk\File $file,
		?Disk\Folder $folder,
		RemoveResult $removeResult,
	): bool
	{
		$hasAttachedObjects = $file->countAttachedObjects() != 0;
		if ($hasAttachedObjects)
		{
			$removeResult->addError(new Error('Could not delete file which attached to entities'));

			return false;
		}

		if ($file->getGlobalContentVersion() != 1)
		{
			$removeResult->addError(new Error('Could not delete file which has a few versions'));

			return false;
		}

		if (!$file->getParent() || !$folder || $file->getParentId() !== $folder->getId())
		{
			$removeResult->addError(new Error('Could not delete file which is not located in folder for uploaded files.'));

			return false;
		}

		return true;
	}

	/**
	 * @param array<int, array{fileId: int, claimToken: string, removeResult: RemoveResult}> $claimedFiles
	 */
	protected function removeClaimedFiles(
		array $claimedFiles,
		int $currentUserId,
	): void
	{
		if ($claimedFiles === [])
		{
			return;
		}

		$markerService = new MarkerService();
		$ready = [];
		$filesById = [];
		$expectedParentIds = [];
		foreach ($claimedFiles as $fileId => $claimed)
		{
			$file = Disk\File::loadById($fileId, ['STORAGE']);
			if (!$file)
			{
				$markerService->consumeClaim($fileId, $claimed['claimToken']);

				continue;
			}

			$deleteActor = $this->resolveDeleteActor(
				$file,
				$file->getStorage()->getSecurityContext($currentUserId),
				true,
				$currentUserId,
				$claimed['removeResult'],
			);
			if ($deleteActor === null)
			{
				$markerService->revokeClaim($fileId, $claimed['claimToken']);

				continue;
			}

			/** @var Disk\Folder|null $folder */
			[$folder] = $this->getFolderAndStorage($file->getOriginalName());
			$ready[$fileId] = $claimed + [
				'file' => $file,
				'deleteActor' => $deleteActor,
			];
			$filesById[$fileId] = $file;
			$expectedParentIds[$fileId] = (int)($folder?->getId() ?? 0);
		}

		try
		{
			$guardFailures = (new CleanupGuard())->checkBatch($filesById, $currentUserId, $expectedParentIds);
		}
		catch (\Throwable $exception)
		{
			// Without a verdict the file cannot be proven orphaned. Leave every claim as it is:
			// revoking here would also deny the cleanup agent its later attempt.
			foreach ($ready as $claimed)
			{
				$claimed['removeResult']->addError(new Error('Could not verify the file is safe to delete'));
			}

			return;
		}

		foreach ($ready as $fileId => $claimed)
		{
			$failure = array_key_exists($fileId, $guardFailures)
				? $guardFailures[$fileId]
				: CleanupGuard::IN_USE
			;
			if ($failure !== null)
			{
				$this->addCleanupGuardError($claimed['removeResult'], $failure);
				$markerService->revokeClaim($fileId, $claimed['claimToken']);

				continue;
			}

			if ($claimed['file']->delete($claimed['deleteActor']))
			{
				$markerService->consumeClaim($fileId, $claimed['claimToken']);

				continue;
			}

			$claimed['removeResult']->addErrors($claimed['file']->getErrors());
		}
	}

	private function addCleanupGuardError(RemoveResult $removeResult, string $failure): void
	{
		$message = match ($failure)
		{
			CleanupGuard::ATTACHED => 'Could not delete file which attached to entities',
			CleanupGuard::VERSIONED => 'Could not delete file which has a few versions',
			CleanupGuard::MOVED => 'Could not delete file which is not located in folder for uploaded files.',
			default => 'Could not delete file which is already in use',
		};
		$removeResult->addError(new Error($message));
	}

	private function removeLegacyFile(
		Disk\File $file,
		int $currentUserId,
		RemoveResult $removeResult,
	): void
	{
		$deleteActor = $this->resolveDeleteActor(
			$file,
			$file->getStorage()->getCurrentUserSecurityContext(),
			false,
			$currentUserId,
			$removeResult,
		);
		if ($deleteActor === null)
		{
			return;
		}

		/** @var Disk\Folder|null $folder */
		[$folder] = $this->getFolderAndStorage($file->getOriginalName());
		if (
			$this->passesRemovalGuards($file, $folder, $removeResult)
			&& !$file->delete($deleteActor)
		)
		{
			$removeResult->addErrors($file->getErrors());
		}
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
	protected function createFileInfo(Disk\File|Disk\AttachedObject $fileModel): ?FileInfo
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

			$storage = $fileModel->getStorage();
			$securityContext = $storage?->getCurrentUserSecurityContext();
			$canDownload = $securityContext && $fileModel->canDownload($securityContext);

			if ($canDownload)
			{
				$fileInfo->setDownloadUrl((string)$downloadUrl);
			}

			$customData['canUpdate'] = $storage && $securityContext && $fileModel->canUpdate($securityContext);
			$viewerSourceUrl = $canDownload ? (string)$downloadUrl : $this->urlManager->getUrlForShowFile($fileModel);
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

			$user = CurrentUser::get();
			$userId = $user ? $user->getId() : \Bitrix\Disk\Security\SecurityContext::GUEST_USER;
			$canDownload = $fileModel->canDownload($userId);

			if ($canDownload)
			{
				$fileInfo->setDownloadUrl((string)$downloadUrl);
			}

			$customData['canUpdate'] = $fileModel->canUpdate($userId);

			$realFile = $fileModel->getFile();
			$realFileStorage = $realFile?->getStorage();
			$customData['canRename'] =
				$realFile && $realFileStorage && $realFile->canRename($realFileStorage->getSecurityContext($userId));

			$attachedObjectId = $fileModel->getId();

			$viewerSourceUrl = $canDownload
				? (string)$downloadUrl
				: \Bitrix\Disk\UrlManager::getUrlToActionShowUfFile($fileModel->getId(), $quickAccessGetParam)
			;
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
		elseif ($file)
		{
			$this->setVideoPreview($fileInfo, $fileModel, $file);
		}

		$fileInfo->setViewerAttrs($this->prepareViewerAttrs($fileModel, $viewerSourceUrl, $fileInfo, $canDownload));

		return $fileInfo;
	}

	private function setVideoPreview(
		FileInfo $fileInfo,
		Disk\File|Disk\AttachedObject $fileModel,
		Disk\File $file,
	): void
	{
		if (!Disk\TypeFile::isVideo($file) || (int)$file->getPreviewId() <= 0)
		{
			return;
		}

		$view = $file->getView();
		$previewData = $view->getPreviewData();
		$width = (int)($previewData['WIDTH'] ?? 0);
		$height = (int)($previewData['HEIGHT'] ?? 0);
		if ($width <= 0 || $height <= 0)
		{
			return;
		}

		if ($fileModel instanceof Disk\File)
		{
			$previewUrl = $this->urlManager->getUrlForShowPreview($fileModel);
		}
		else
		{
			$previewUrl = \Bitrix\Disk\UrlManager::getUrlUfController(
				'showPreview',
				['attachedId' => $fileModel->getId(), 'viewId' => $view->getId()]
			);
		}

		$sourceRectangle = new Rectangle($width, $height);
		$destinationRectangle = new Rectangle(1200, 1200);
		if ($sourceRectangle->resize($destinationRectangle, Image::RESIZE_PROPORTIONAL))
		{
			$sourceRectangle = $destinationRectangle;
		}

		$fileInfo->setPreviewUrl($previewUrl, $sourceRectangle->getWidth(), $sourceRectangle->getHeight());
	}

	private function getEncryptedFileDataForQuickAccess(AttachedObject $fileModel): ?string
	{
		$fileDataParameterService = ServiceLocator::getInstance()->get('disk.fileDataParameterService');

		return $fileDataParameterService->getEncryptedFileData($fileModel);
	}

	protected function getFolderAndStorage(string $filename): array
	{
		$folderId = (int)$this->getOption('folderId', 0);
		$userId = (int)CurrentUser::get()->getId();

		return $this->getFolderResolver()->resolveFolderAndStorage($folderId, $filename, $userId);
	}

	private function getFolderResolver(): FolderResolverService
	{
		return ServiceLocator::getInstance()->get('disk.dockedUpload.folderResolver');
	}

	/**
	 * @param AttachedObject | File $fileModel
	 * @param string $viewerSourceUrl
	 * @param FileInfo $fileInfo
	 * @param bool $canDownload
	 *
	 * @return array
	 * @throws ArgumentException
	 * @throws NotImplementedException
	 */
	private function prepareViewerAttrs(
		Disk\File|Disk\AttachedObject $fileModel,
		string $viewerSourceUrl,
		FileInfo $fileInfo,
		bool $canDownload,
	): array
	{
		$file = $fileModel instanceof Disk\File ? $fileModel : $fileModel->getFile();
		$viewerAttrs = Disk\Ui\FileAttributes::buildByFileId($fileModel->getFileId(), $viewerSourceUrl, $file)
			->setTitle($fileModel->getName())
			->setObjectId($fileModel->getId())
			->setDownloadDisabled(!$canDownload)
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
