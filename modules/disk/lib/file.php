<?php

namespace Bitrix\Disk;

use Bitrix\Disk;
use Bitrix\Disk\Document\Online\UserInfoToken;
use Bitrix\Disk\Internal\Enum\WriteOperationContext;
use Bitrix\Disk\Internal\Service\Quota\PersonalQuotaService;
use Bitrix\Disk\Internal\Service\Quota\StorageUsageCounter;
use Bitrix\Disk\Internal\Service\Restrictions\ContentHeadReader;
use Bitrix\Disk\Internal\Service\Restrictions\FormatPolicyService;
use Bitrix\Disk\Internal\Service\Restrictions\SignatureDetector;
use Bitrix\Disk\Internal\Service\Audit\AuditObjectEventProducer;
use Bitrix\Disk\Internal\Service\Audit\AuditOperationContext;
use Bitrix\Disk\Internal\Service\Audit\AuditOperationContextStack;
use Bitrix\Disk\Internal\Service\Audit\AuditSnapshotBuilder;
use Bitrix\Disk\Internal\Service\Audit\AuditTransaction;
use Bitrix\Disk\Internal\Service\Audit\AuditTransactionException;
use Bitrix\Disk\Internal\Service\Audit\AuditTransactionOutcome;
use Bitrix\Disk\Internal\Service\Audit\AuditTransactionResult;
use Bitrix\Disk\Internal\Service\Sync\Journal\ChangeType;
use Bitrix\Disk\Internal\Service\Sync\Journal\TrackedSetCleanupPublisher;
use Bitrix\Disk\Internal\Service\UnifiedLink\UnifiedLinkAccessService;
use Bitrix\Disk\Internal\Service\UnifiedLink\UnifiedLinkUrl;
use Bitrix\Disk\Internal\Service\UnifiedLink\UnifiedLinkSupportService;
use Bitrix\Disk\Internal\Service\UploadCleanup\MarkerService;
use Bitrix\Disk\Internals\AttachedObjectTable;
use Bitrix\Disk\Internals\EditSessionTable;
use Bitrix\Disk\Internals\Error\Error;
use Bitrix\Disk\Internals\Error\ErrorCollection;
use Bitrix\Disk\Internals\ExternalLinkTable;
use Bitrix\Disk\Internals\FileTable;
use Bitrix\Disk\Internals\ObjectNameService;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Disk\Internals\RightTable;
use Bitrix\Disk\Internals\SharingTable;
use Bitrix\Disk\Internals\SimpleRightTable;
use Bitrix\Disk\Internals\TrackedObjectTable;
use Bitrix\Disk\Internals\UniqueCode;
use Bitrix\Disk\Internals\VersionTable;
use Bitrix\Disk\Realtime\Events\UserRecentsEvent;
use Bitrix\Disk\Security\ParameterSigner;
use Bitrix\Disk\Security\SecurityContext;
use Bitrix\Disk\Uf\FileUserType;
use Bitrix\Main;
use Bitrix\Main\Application;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Entity\ExpressionField;
use Bitrix\Main\Entity\Query;
use Bitrix\Main\Event;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ObjectException;
use Bitrix\Main\Type\DateTime;
use CFile;

Loc::loadMessages(__FILE__);

class File extends BaseObject
{
	public const FILE_OWNERSHIP_DATA_KEY = 'FILE_OWNERSHIP';
	public const FILE_OWNERSHIP_BORROWED = 'borrowed';
	public const FILE_OWNERSHIP_OWNED = 'owned';
	public const DEFER_CREATED_AUDIT_DATA_KEY = 'DEFER_CREATED_AUDIT';

	const ERROR_COULD_NOT_SAVE_FILE           = 'DISK_FILE_22002';
	const ERROR_COULD_NOT_COPY_FILE           = 'DISK_FILE_22003';
	const ERROR_COULD_NOT_RESTORE_FROM_OBJECT = 'DISK_FILE_22004';
	const ERROR_COULD_NOT_GET_SAVED_FILE      = 'DISK_FILE_22005';
	const ERROR_SIZE_RESTRICTION              = 'DISK_FILE_22006';
	const ERROR_EXCLUSIVE_LOCK                = 'DISK_FILE_22007';
	const ERROR_ALREADY_LOCKED                = 'DISK_FILE_22008';
	const ERROR_INVALID_LOCK_TOKEN            = 'DISK_FILE_22009';
	const ERROR_INVALID_USER_FOR_UNLOCK       = 'DISK_FILE_22010';
	const ERROR_INVALID_FILE_ARRAY 		      = 'DISK_FILE_22011';
	// Owner of the code of the personal quota refusal: the domain service and the transport map name it by this one.
	const ERROR_PERSONAL_QUOTA_EXCEEDED       = 'DISK_FILE_22012';
	// Owner of the code of the format refusal: the domain service and the transport map name it by this one.
	const ERROR_FORMAT_RESTRICTED             = 'DISK_FILE_22013';
	// Owner of the code of the refusal for content the spent read budget left unchecked.
	const ERROR_FORMAT_NOT_VERIFIED           = 'DISK_FILE_22014';

	const STATE_DO_NOTHING     = 2;
	const STATE_DELETE_PROCESS = 3;

	const CODE_RECORDED_FILE = 'RECORDED';

	protected UnifiedLinkAccessService $unifiedLinkAccessService;
	protected UnifiedLinkUrl $unifiedLinkUrl;
	private bool $skipRecentlyUsed = false;

	protected array $fileTypeFixes = [
		TypeFile::UNKNOWN => [
			'xlsm' => TypeFile::DOCUMENT,
		],
	];

	/** @var int */
	protected $typeFile;
	/** @var int */
	protected $globalContentVersion;
	/** @var int */
	protected $fileId;
	/** @var int */
	protected $prevFileId;
	/** @var array */
	protected $file;
	/** @var int */
	protected $size;
	/** @var string */
	protected $externalHash;
	/** @var string */
	protected $etag;
	/** @var string */
	protected $extension;
	/** @var int */
	protected $currentState = self::STATE_DO_NOTHING;
	/** @var ?Preview */
	protected ?Preview $preview;
	/** @var  int */
	protected $previewId;
	/** @var  int */
	protected $viewId;
	/** @var int */
	protected $prevViewId;
	/** @var View\Base */
	protected $view;
	/**
	 * Tells the inner node of the running write operation that the format policy has already
	 * been applied to the name, so that one operation asks the policy exactly once.
	 */
	private bool $formatPolicyChecked = false;

	/**
	 * The attributes travel on to the model as they do for every other object: a file raised from an
	 * array of them - the way the tests and the builders of in-memory objects raise one - keeps its name
	 * and its content id instead of losing them on the way.
	 */
	public function __construct(array $attributes = [])
	{
		parent::__construct($attributes);

		$serviceLocator = ServiceLocator::getInstance();
		$this->unifiedLinkAccessService = $serviceLocator->get(UnifiedLinkAccessService::class);
		$this->unifiedLinkUrl = $serviceLocator->get(UnifiedLinkUrl::class);
	}

	/**
	 * Gets the fully qualified name of table class which belongs to current model.
	 * @throws \Bitrix\Main\NotImplementedException
	 * @return string
	 */
	public static function getTableClassName()
	{
		return FileTable::className();
	}

	/**
	 * Checks rights to start bizprocess on current object.
	 * @param SecurityContext $securityContext Security context.
	 * @return bool
	 */
	public function canStartBizProc(SecurityContext $securityContext)
	{
		return $securityContext->canStartBizProc($this->id);
	}

	/**
	 * Checks rights to restore and delete versions of current file.
	 * A version is a state of the file content, so managing versions is the right to update the content.
	 * @param SecurityContext $securityContext Security context.
	 * @return bool
	 */
	public function canManageVersions(SecurityContext $securityContext): bool
	{
		return $this->canUpdate($securityContext);
	}

	/**
	 * Adds row to entity table, fills error collection and builds model.
	 * @param array           $data Data.
	 * @param ErrorCollection $errorCollection Error collection.
	 * @param array           $options Options of the write operation (@see \Bitrix\Disk\File::addVersion()).
	 *                                 The node marks them with what it has already judged, so a caller
	 *                                 repeating the write pays for the content no second time.
	 * @return \Bitrix\Disk\Internals\Model|static|null
	 * @throws \Bitrix\Main\NotImplementedException
	 * @internal
	 */
	public static function add(
		array $data,
		ErrorCollection $errorCollection,
		array &$options = [],
		?AuditTransaction $auditTransaction = null,
	)
	{
		$fileOwnership = $data[self::FILE_OWNERSHIP_DATA_KEY] ?? self::FILE_OWNERSHIP_BORROWED;
		$publishCreated = !($data[self::DEFER_CREATED_AUDIT_DATA_KEY] ?? false);
		unset($data[self::FILE_OWNERSHIP_DATA_KEY], $data[self::DEFER_CREATED_AUDIT_DATA_KEY]);

		$name = (string)($data['NAME'] ?? '');
		$actorId = self::resolveFormatPolicyActorId(
			array_key_exists(WriteOperationContext::OPTION_KEY_POLICY_ACTOR_ID, $options)
				? $options[WriteOperationContext::OPTION_KEY_POLICY_ACTOR_ID]
				: ($data['CREATED_BY'] ?? null)
		);

		$formatPolicyError = self::findFormatPolicyError($name, $options, $actorId);
		if ($formatPolicyError)
		{
			$errorCollection->add([$formatPolicyError]);

			return null;
		}

		$parent = null;
		if(isset($data['PARENT']) && $data['PARENT'] instanceof Folder)
		{
			$parent = $data['PARENT'];
			unset($data['PARENT']);
		}

		// Bridge for the docked upload commit: DISABLE_AUTO_BIZPROC is only a
		// transport for the flag down to the internal addVersion() call. It is not
		// a b_disk_object field, so it must be stripped before parent::add().
		$disableAutoBizProc = !empty($data['DISABLE_AUTO_BIZPROC']);
		unset($data['DISABLE_AUTO_BIZPROC']);
		// An upload-only flag, not a column: it must not reach the object insert.
		$disableRecentlyUsed = !empty($data['DISABLE_RECENTLY_USED']);
		unset($data['DISABLE_RECENTLY_USED']);

		if (!isset($data['UNIQUE_CODE']))
		{
			$data['UNIQUE_CODE'] = (new UniqueCode())->generateForNewFile();
		}

		// The content is the only thing this node reaches the storage for, so it is looked at last of
		// the checks and still before anything of the file is written down.
		$contentHeadReader = empty($options[WriteOperationContext::OPTION_KEY_CONTENT_CHECK_REQUIRED])
			? null
			: new ContentHeadReader()
		;
		$contentHead = self::readStoredContentHead(
			(int)($data['FILE_ID'] ?? 0),
			$options,
			$actorId,
			$contentHeadReader,
		);
		if ($contentHead !== null)
		{
			// The content has been looked at, and the caller is told so by the mark it gets back:
			// nothing but a content really judged here may keep a later write away from the storage.
			$options[WriteOperationContext::OPTION_KEY_SIGNATURE_CHECKED] = true;

			$formatPolicyError = self::findFormatPolicyError($name, $options, $actorId, $contentHead);
			if ($formatPolicyError)
			{
				$errorCollection->add([$formatPolicyError]);

				return null;
			}
		}
		elseif ($contentHeadReader?->wasLastReadSkippedForBudget())
		{
			$errorCollection->add([self::createFormatNotVerifiedError()]);

			return null;
		}

		$callback = function (?AuditTransaction $transaction = null) use (
			$data,
			$parent,
			$errorCollection,
			$fileOwnership,
			$publishCreated,
			$disableRecentlyUsed,
			$disableAutoBizProc,
			$options,
		) {
			if ($transaction !== null && $fileOwnership === self::FILE_OWNERSHIP_OWNED)
			{
				$fileId = (int)($data['FILE_ID'] ?? 0);
				if ($fileId > 0)
				{
					$transaction->addCompensation(static function () use ($fileId): void {
						\CFile::Delete($fileId);
					});
				}
			}

			/** @var File|null $createdFile */
			$createdFile = parent::add($data, $errorCollection);
			if (!$createdFile)
			{
				return null;
			}

			if ($parent !== null)
			{
				$createdFile->setAttributes(array('PARENT' => $parent));
			}

			$versionData = array(
				'ID' => $createdFile->getFileId(),
				'FILE_SIZE' => $createdFile->getSize(),
			);

			if (!empty($data['UPDATE_TIME']))
			{
				$versionData['UPDATE_TIME'] = $data['UPDATE_TIME'];
			}
			if (!empty($data['ETAG']))
			{
				$versionData['ETAG'] = $data['ETAG'];
			}

			$createdFile->skipRecentlyUsed = $disableRecentlyUsed;
			$createdFile->markFormatPolicyChecked();
			try
			{
				$version = $createdFile->addVersion(
					$versionData,
					$createdFile->getCreatedBy(),
					options: $disableAutoBizProc ? ['disableAutoBizProc' => true] + $options : $options,
					auditTransaction: $transaction,
				);
			}
			finally
			{
				$createdFile->skipRecentlyUsed = false;
			}
			if (!$version)
			{
				$errorCollection->add($createdFile->getErrors());

				return null;
			}

			if ($publishCreated)
			{
				$auditResult = (new AuditObjectEventProducer())->publishCreated(
					$createdFile,
					(int)$createdFile->getCreatedBy(),
				);
				if (!$createdFile->applyAuditResult($auditResult))
				{
					$errorCollection->add($createdFile->getErrors());

					return null;
				}
			}

			if ($transaction !== null)
			{
				$transaction->addAfterCommit(static function () use ($createdFile): void {
					$event = new Event(Driver::INTERNAL_MODULE_ID, 'onAfterAddFile', [$createdFile]);
					$event->send();
				});
			}

			return $createdFile;
		};

		if ($auditTransaction !== null)
		{
			$file = $callback($auditTransaction);
		}
		elseif (AuditTransaction::isActive())
		{
			$file = AuditTransaction::run(static fn(AuditTransaction $owner) => $callback($owner));
		}
		else
		{
			$transaction = new AuditTransaction(Application::getConnection());
			$result = $transaction->execute(
				static function (AuditTransaction $transaction) use ($callback): AuditTransactionOutcome {
					$file = $callback($transaction);

					return $file instanceof self
						? AuditTransactionOutcome::success($file)
						: AuditTransactionOutcome::failure();
				},
			);
			/** @var File|null $file */
			$file = self::unwrapAuditTransactionResult($result);
		}

		if (!$file)
		{
			return null;
		}

		return $file;
	}

	private static function unwrapAuditTransactionResult(AuditTransactionResult $result): mixed
	{
		$callbackException = $result->getCallbackException();
		if (
			$callbackException !== null
			&& $result->isRollbackConfirmed()
			&& count($result->getCallbackExceptions()) === 1
			&& $result->getCompensationExceptions() === []
		)
		{
			throw $callbackException;
		}

		if (
			$result->isRollbackConfirmed()
			&& $result->getCallbackExceptions() === []
			&& $result->getCompensationExceptions() === []
		)
		{
			return $result->getOutcome()->getValue();
		}

		if ($result->isSuccess())
		{
			return $result->getOutcome()->getValue();
		}

		throw new AuditTransactionException($result);
	}

	/**
	 * Returns once model by specific filter.
	 * @param array $filter Filter.
	 * @param array $with List of eager loading.
	 * @throws \Bitrix\Main\NotImplementedException
	 * @return static
	 */
	public static function load(array $filter, array $with = array())
	{
		$filter['TYPE'] = ObjectTable::TYPE_FILE;

		return parent::load($filter, $with);
	}

	protected static function getClassNameModel(array $row)
	{
		$classNameModel = parent::getClassNameModel($row);
		if(
			$classNameModel === static::className() ||
			is_subclass_of($classNameModel, static::className()) ||
			in_array(static::className(), class_parents($classNameModel)) //5.3.9
		)
		{
			return $classNameModel;
		}

		throw new ObjectException('Could not to get non subclass of ' . static::className());
	}

	/**
	 * Returns extension.
	 * @return string
	 */
	public function getExtension()
	{
		if($this->extension === null)
		{
			$this->extension = getFileExtension($this->getName());
		}
		return $this->extension;
	}

	/**
	 * Returns file name without extension.
	 * @return string
	 */
	public function getNameWithoutExtension(): string
	{
		return GetFileNameWithoutExtension($this->getName());;
	}

	/**
	 * Returns external hash.
	 * @return string
	 */
	public function getExternalHash()
	{
		return $this->externalHash;
	}

	/**
	 * Returns etag.
	 * @return string
	 */
	public function getEtag()
	{
		return $this->etag;
	}

	/**
	 * Changes etag.
	 *
	 * @param string $etag Entity tag.
	 * @return bool
	 */
	public function changeEtag($etag)
	{
		return $this->update(array('ETAG' => $etag));
	}

	/**
	 * Returns global content version.
	 *
	 * Version always increments after creating new version.
	 * @return int
	 */
	public function getGlobalContentVersion()
	{
		return $this->globalContentVersion;
	}

	/**
	 * Returns id of file (table {b_file}).
	 * @return int
	 */
	public function getFileId()
	{
		return $this->fileId;
	}

	/**
	 * Returns file (@see CFile::getById());
	 * @return array|null
	 * @throws \Bitrix\Main\NotImplementedException
	 */
	public function getFile()
	{
		if(!$this->fileId)
		{
			return null;
		}

		if(isset($this->file) && $this->fileId == $this->file['ID'])
		{
			// A record handed over by a batch read carries no public url: building one runs an event and
			// costs a bucket per file in the clouds, so it waits until someone asks for the url.
			$this->file['SRC'] ??= \CFile::GetFileSRC($this->file);

			return $this->file;
		}

		$this->file = \CFile::GetFileArray($this->fileId);

		if(!$this->file)
		{
			return [];
		}

		return $this->file;
	}

	/**
	 * Fills the cache of getFile() with a record read elsewhere, so that a batch read of b_file serves this
	 * model too. A record of another file is ignored. The public url is not built here: getFile() builds it
	 * when it is asked for, so a batch pays for it only on the files whose url is actually used.
	 *
	 * @param array $fileData Record of b_file, as CFile returns it.
	 * @return void
	 */
	public function primeFileData(array $fileData): void
	{
		if (!$this->fileId || (int)($fileData['ID'] ?? 0) !== (int)$this->fileId)
		{
			return;
		}

		$this->file = $fileData;
	}

	/**
	 * Returns id of preview image.
	 * @return int
	 * @deprecated
	 */
	public function getPreviewId()
	{
		return $this->previewId;
	}

	/**
	 * Set previewId, save in the database.
	 *
	 * @param int $fileId
	 * @return bool
	 * @deprecated
	 */
	public function changePreviewId($fileId)
	{
		return $this->update(array('PREVIEW_ID' => $fileId));
	}

	public function attachImagePreview(array $previewFileData): Main\Result
	{
		$result = new Main\Result();
		if (!\CFile::isImage($previewFileData['name'], $previewFileData['type']))
		{
			$result->addError(new Error('Preview is not an image'));

			return $result;
		}

		$previewFileData['MODULE_ID'] = 'main';

		if (!Disk\Internals\FileHelper::hasValidFileKeys($previewFileData))
		{
			$result->addError(new Error('Preview file data is not valid'));

			return $result;
		}

		$previewId = \CFile::saveFile($previewFileData, 'main_preview', true, true);
		if (!$previewId)
		{
			$result->addError(new Error('Could not save preview to b_file'));

			return $result;
		}

		$resultPreview = (new Main\UI\Viewer\PreviewManager())->setPreviewImageId($this->getFileId(), $previewId);
		if (!$resultPreview->isSuccess())
		{
			$result->addErrors($resultPreview->getErrors());
			\CFile::delete($previewId);
		}

		return $result;
	}

	/**
	 * Returns id of view.
	 * @return int|null
	 * @deprecated
	 */
	public function getViewId()
	{
		return $this->viewId;
	}

	/**
	 * Set viewId, save in the database.
	 *
	 * @param int $fileId
	 * @return bool
	 * @deprecated
	 */
	public function changeViewId($fileId)
	{
		return $this->update(array('VIEW_ID' => $fileId));
	}

	/**
	 * Delete converted view file.
	 *
	 * @return bool
	 * @deprecated
	 */
	public function deleteViewId()
	{
		if($this->viewId > 0)
		{
			\CFile::Delete($this->viewId);
			return $this->update(array('VIEW_ID' => null));
		}

		return false;
	}

	/**
	 * Returns size in bytes.
	 *
	 * @param null $filter
	 *
	 * @return int
	 */
	public function getSize($filter = null)
	{
		return $this->size;
	}

	/**
	 * Returns type of file.
	 * @see TypeFile class for details.
	 * @return int
	 */
	public function getTypeFile()
	{
		return $this->fileTypeFixes[(int)$this->typeFile][$this->getExtension()] ?? $this->typeFile;
	}

	/**
	 * Renames object.
	 * @param string $newName New name.
	 * @return bool
	 */
	public function rename($newName, bool $generateUniqueName = false)
	{
		$result = parent::rename($newName, $generateUniqueName);
		if($result)
		{
			$this->extension = null;
		}
		return $result;
	}

	/**
	 * Copies object to target folder.
	 *
	 * A copy is a file of its own, and it may land where the source never was - a shared folder, the
	 * storage of a group, a collab. So the content answers for itself as it does on any other write: a
	 * file stored before the policy may carry a format the policy names now, and the same bytes would
	 * be refused if the user downloaded them and put them back.
	 *
	 * @param Folder $targetFolder Target folder.
	 * @param int    $updatedBy Id of user.
	 * @param bool   $generateUniqueName Generates unique name for object in directory.
	 * @return BaseObject|null
	 */
	public function copyTo(Folder $targetFolder, $updatedBy, $generateUniqueName = false)
	{
		return $this->copyWithOptions($targetFolder, $updatedBy, (bool)$generateUniqueName, []);
	}

	/**
	 * Copies the file on behalf of no user: the copy keeps the author given, and the global rule of the
	 * format policy judges it.
	 *
	 * @internal
	 * @param int $authorId Author of the copy.
	 * @return File|null
	 */
	public function copyToOnBehalfOfNoUser(Folder $targetFolder, int $authorId, bool $generateUniqueName = false)
	{
		return $this->copyWithOptions($targetFolder, $authorId, $generateUniqueName, [
			WriteOperationContext::OPTION_KEY_POLICY_ACTOR_ID => null,
		]);
	}

	/**
	 * A copy carries stored content to a place of the one copying, so the content the read budget
	 * leaves unchecked refuses it (@see WriteOperationContext::OPTION_KEY_CONTENT_CHECK_REQUIRED).
	 *
	 * @return File|null
	 */
	private function copyWithOptions(Folder $targetFolder, $updatedBy, bool $generateUniqueName, array $options)
	{
		$this->errorCollection->clear();
		$options[WriteOperationContext::OPTION_KEY_CONTENT_CHECK_REQUIRED] = true;

		if (AuditOperationContextStack::current() === null)
		{
			return AuditOperationContextStack::runWithContext(
				AuditOperationContext::create(
					actionKind: AuditOperationContext::ACTION_EXPLICIT_COPY,
					selectedTargetIds: [(string)$this->getId()],
				),
				fn() => $this->copyWithOptions($targetFolder, $updatedBy, $generateUniqueName, $options),
			);
		}

		$transaction = new AuditTransaction(Application::getConnection());
		$result = $transaction->execute(
			function (AuditTransaction $transaction) use ($targetFolder, $updatedBy, $generateUniqueName, $options) {
				$file = $this->copyToInsideAuditTransaction(
					$targetFolder,
					$updatedBy,
					$generateUniqueName,
					$transaction,
					true,
					$options,
				);

				return $file instanceof self
					? AuditTransactionOutcome::success($file)
					: AuditTransactionOutcome::failure();
			},
		);

		/** @var File|null $file */
		$file = $this->unwrapAuditCommandResult($result);

		return $file;
	}

	/**
	 * A move between storages is done by copying the content over, and the copy is marked as a move:
	 * the write node judges it by the policy of the one moving, the author of the copy. The move has
	 * already judged the stored content (@see self::findFormatPolicyErrorForMove()), so the node does
	 * not read it a second time.
	 *
	 * @param Folder $targetFolder Destination folder.
	 * @param int    $movedBy User id of user, which moves file.
	 * @param AuditTransaction $auditTransaction Transaction of the move the copy is made inside.
	 * @return BaseObject|null
	 */
	protected function copyForMove(Folder $targetFolder, $movedBy, AuditTransaction $auditTransaction)
	{
		return $this->copyToInsideAuditTransaction($targetFolder, $movedBy, false, $auditTransaction, false, [
			WriteOperationContext::OPTION_KEY => WriteOperationContext::StoredContentMove,
			WriteOperationContext::OPTION_KEY_SIGNATURE_CHECKED => true,
		]);
	}

	/**
	 * Writes the content of this file into the target folder as a new file. The context of the write
	 * comes from the caller through $options.
	 */
	protected function copyToInsideAuditTransaction(
		Folder $targetFolder,
		$updatedBy,
		bool $generateUniqueName,
		AuditTransaction $auditTransaction,
		bool $publishRootAudit,
		array $options = [],
	): ?BaseObject
	{
		if ($this instanceof FileLink)
		{
			return parent::copyToInsideAuditTransaction(
				$targetFolder,
				$updatedBy,
				$generateUniqueName,
				$auditTransaction,
				$publishRootAudit,
				$options,
			);
		}

		$forkFileId = $this->cloneFileForCopy($this->getFileId());
		if (!$forkFileId)
		{
			$this->errorCollection->add(array(new Error(Loc::getMessage('DISK_FILE_MODEL_ERROR_COULD_NOT_COPY_FILE'), self::ERROR_COULD_NOT_COPY_FILE)));
			return null;
		}
		$this->registerOwnedCopyFile($auditTransaction, $forkFileId);

		// The read of the stored content is paid for only where it can change the verdict at all
		// (@see self::isContentHeadNeeded()), so a write outside the policy reaches no storage.
		$fileModel = $targetFolder->addFile([
			'NAME' => $this->getName(),
			'FILE_ID' => $forkFileId,
			'ETAG' => $this->getEtag(),
			'SIZE' => $this->getSize(),
			'CREATED_BY' => $updatedBy,
		], [], $generateUniqueName, $options, $auditTransaction);
		if (!$fileModel)
		{
			$this->errorCollection->add($targetFolder->getErrors());

			return null;
		}

		if (!$this->clonePreviewData($this->getFileId(), $forkFileId, $auditTransaction))
		{
			return null;
		}

		if ($publishRootAudit)
		{
			$auditResult = (new AuditObjectEventProducer())->publishCopied($this, $fileModel, (int)$updatedBy);
			if (!$this->applyAuditResult($auditResult))
			{
				return null;
			}
		}

		return $fileModel;
	}

	protected function cloneFileForCopy(int $fileId): ?int
	{
		return \CFile::CloneFile($fileId);
	}

	private function clonePreviewData(
		int $originalFileId,
		int $clonedFileId,
		AuditTransaction $auditTransaction,
	): bool
	{
		$previewManager = new Main\UI\Viewer\PreviewManager();
		$originalPreviewFileData = $previewManager->getFilePreviewEntryByFileId($originalFileId);
		if (empty($originalPreviewFileData['ID']))
		{
			return true;
		}

		$forkedPreviewImageFileId = null;
		$forkedPreviewFileId = null;
		if (!empty($originalPreviewFileData['PREVIEW_IMAGE_ID']))
		{
			$forkedPreviewImageFileId = $this->cloneFileForCopy((int)$originalPreviewFileData['PREVIEW_IMAGE_ID']);
			if (!$forkedPreviewImageFileId)
			{
				$this->addCopyFileError();

				return false;
			}
			$this->registerOwnedCopyFile($auditTransaction, $forkedPreviewImageFileId);
		}
		if (!empty($originalPreviewFileData['PREVIEW_ID']))
		{
			$forkedPreviewFileId = $this->cloneFileForCopy((int)$originalPreviewFileData['PREVIEW_ID']);
			if (!$forkedPreviewFileId)
			{
				$this->addCopyFileError();

				return false;
			}
			$this->registerOwnedCopyFile($auditTransaction, $forkedPreviewFileId);
		}

		$resultPreview = $previewManager->attachPreviewToFileId($clonedFileId, $forkedPreviewFileId, $forkedPreviewImageFileId);
		if (!$resultPreview->isSuccess())
		{
			$this->errorCollection->add($resultPreview->getErrors());

			return false;
		}

		return true;
	}

	private function registerOwnedCopyFile(AuditTransaction $auditTransaction, int $fileId): void
	{
		$auditTransaction->addCompensation(static function () use ($fileId): void {
			try
			{
				\CFile::Delete($fileId);
			}
			finally
			{
				\CFile::CleanCache($fileId);
			}
		});
	}

	private function addCopyFileError(): void
	{
		$this->errorCollection->add([
			new Error(
				Loc::getMessage('DISK_FILE_MODEL_ERROR_COULD_NOT_COPY_FILE'),
				self::ERROR_COULD_NOT_COPY_FILE,
			),
		]);
	}

	/**
	 * Increases global content version.
	 * @return bool
	 */
	public function increaseGlobalContentVersion()
	{
		//todo inc in DB by expression
		$success = $this->update(array(
			'GLOBAL_CONTENT_VERSION' => (int)$this->getGlobalContentVersion() + 1,
		));

		if(!$success)
		{
			return false;
		}

		$this->updateLinksAttributes(array(
			'GLOBAL_CONTENT_VERSION' => $this->getGlobalContentVersion(),
		));

		return $success;
	}

	/**
	 * Returns object lock model.
	 *
	 * @return ObjectLock|null
	 */
	public function getLock()
	{
		if ($this->isLoadedAttribute('lock'))
		{
			return $this->lock;
		}

		$lock = ObjectLock::load(['OBJECT_ID' => $this->getRealObjectId()]);
		$this->lock = $lock;
		$this->setAsLoadedAttribute('lock');

		if ($lock && $lock->shouldProcessAutoUnlock())
		{
			$this->unlock(SystemUser::SYSTEM_USER_ID);
			$this->lock = null;
		}

		return $this->lock;
	}

	/**
	 * Locks file.
	 *
	 * @param int $lockedBy User which locks object.
	 * @param string $token Token, if is not set, then run auto generate.
	 * @param array $data Data.
	 * @return ObjectLock
	 */
	public function lock($lockedBy, $token = null, array $data = array())
	{
		if($token === null)
		{
			$token = ObjectLock::generateLockToken();
		}

		//now we work only with exclusive locks.
		$objectLock = $this->getLock();
		if($objectLock)
		{
			$this->errorCollection[] = new Error(
				Loc::getMessage('DISK_FILE_MODEL_ERROR_ALREADY_LOCKED'),
				self::ERROR_ALREADY_LOCKED
			);
			return null;
		}

		$cleanupObject = $this->getRealObject();
		$cleanupObjectId = (int)($cleanupObject?->getId() ?: $this->getId());
		if (!(new MarkerService())->revokeBeforeUse(
			$cleanupObjectId,
			$cleanupObject?->getUploadCleanupMarkerPresenceHint(),
		))
		{
			$this->errorCollection[] = new Error(
				Loc::getMessage('DISK_FILE_MODEL_ERROR_EXCLUSIVE_LOCK'),
				self::ERROR_EXCLUSIVE_LOCK,
			);

			return null;
		}

		$lock = ObjectLock::add(array_merge($data, array(
			'TOKEN' => $token,
			'OBJECT_ID' => $this->getRealObjectId(),
			'CREATED_BY' => $lockedBy,
		)), $this->errorCollection);

		if($lock)
		{
			$this->update(array('SYNC_UPDATE_TIME' => new DateTime()));
			Driver::getInstance()->sendChangeStatusToSubscribers($this, 'quick');
		}

		return $lock;
	}

	/**
	 * Unlocks file.
	 *
	 * @param int $unlockedBy User which unlocks object.
	 * @param string $token Token.
	 * @return bool
	 */
	public function unlock($unlockedBy, $token = null)
	{
		$objectLock = $this->getLock();
		if (!$objectLock)
		{
			return true;
		}

		if (!$objectLock->canUnlock($unlockedBy))
		{
			$this->errorCollection[] = $this->generateUnlockErrorByAnotherUser($objectLock);

			return false;
		}

		if ($token !== null && $objectLock->getToken() !== $token)
		{
			$this->errorCollection[] = new Error(
				Loc::getMessage('DISK_FILE_MODEL_ERROR_INVALID_LOCK_TOKEN'),
				self::ERROR_INVALID_LOCK_TOKEN
			);

			return false;
		}

		$success = $objectLock->delete($unlockedBy);
		if ($success)
		{
			$this->update(array('SYNC_UPDATE_TIME' => new DateTime()));
			Driver::getInstance()->sendChangeStatusToSubscribers($this, 'quick');
		}

		return $success;
	}

	public function generateUnlockErrorByAnotherUser(ObjectLock $objectLock): Error
	{
		$createLockUser = $objectLock->getCreateUser();
		if ($createLockUser)
		{
			return new Error(Loc::getMessage('DISK_FILE_MODEL_ERROR_INVALID_USER_FOR_UNLOCK_2', [
				'#USER#' => "<a href='{$createLockUser->getDetailUrl()}'>" . htmlspecialcharsbx($createLockUser->getFormattedName()) . "</a>",
			]), self::ERROR_INVALID_USER_FOR_UNLOCK);
		}

		return new Error(Loc::getMessage('DISK_FILE_MODEL_ERROR_INVALID_USER_FOR_UNLOCK'), self::ERROR_INVALID_USER_FOR_UNLOCK);
	}

	/**
	 * Puts the file back onto the content of one of its own versions, as the deletion of the head
	 * version does. No new version is written and the content is already stored in this very file,
	 * so the format policy does not judge it anew: saved versions are not rechecked.
	 *
	 * @internal
	 * @param Version $version Version of this file whose content becomes the current one.
	 * @param int $updatedBy Id of user.
	 * @return bool
	 */
	public function switchToContentOfVersion(Version $version, $updatedBy): bool
	{
		$this->markFormatPolicyChecked();

		return (bool)$this->updateContent(
			[
				'ID' => $version->getFileId(),
				'FILE_SIZE' => $version->getSize(),
			],
			$updatedBy,
			[WriteOperationContext::OPTION_KEY => WriteOperationContext::StoredContentMove],
		);
	}

	/**
	 * Updates file content.
	 *
	 * Runs index file, updates all FileLinks, sends notify to subscribers.
	 *
	 * @param array $file Structure like $_FILES.
	 * @param int $updatedBy Id of user.
	 * @param array $options Options of the write operation (@see \Bitrix\Disk\File::addVersion()).
	 * @return bool
	 * @throws \Bitrix\Main\ArgumentException
	 */
	public function updateContent(
		array $file,
		$updatedBy,
		array $options = [],
		?AuditTransaction $auditTransaction = null,
	)
	{
		$this->errorCollection->clear();
		$oldSize = (int)$this->getSize();

		if (!$this->checkFormatPolicy($options, self::resolveVersionPolicyActorId($updatedBy, $options), $file))
		{
			return false;
		}

		static::checkRequiredInputParams($file, array(
			'ID', 'FILE_SIZE'
		));

		$objectLock = null;
		if(Configuration::isEnabledObjectLock())
		{
			$objectLock = $this->getLock();
			if($objectLock && $objectLock->isExclusive() && !$objectLock->canUnlock($updatedBy))
			{
				$this->errorCollection[] = new Error(Loc::getMessage('DISK_FILE_MODEL_ERROR_EXCLUSIVE_LOCK'), self::ERROR_EXCLUSIVE_LOCK);
				return false;
			}
		}

		//todo inc in DB by expression
		$updateData = array(
			'GLOBAL_CONTENT_VERSION' => (int)$this->getGlobalContentVersion() + 1,
			'FILE_ID' => $file['ID'],
			'ETAG' => empty($file['ETAG'])? $this->generateEtag() : $file['ETAG'], //each time we update etag field. It is random string
			'SIZE' => $file['FILE_SIZE'],
			'UPDATE_TIME' => empty($file['UPDATE_TIME'])? new DateTime() : $file['UPDATE_TIME'],
			'UPDATED_BY' => $updatedBy,
		);

		$this->prevFileId = $this->fileId;
		$mutation = function () use ($updateData, $file, $updatedBy, $objectLock, $oldSize): bool {
			$success = $this->update($updateData);

			if(!$success)
			{
				$this->prevFileId = null;
				return false;
			}

			$this->changeParentUpdateTime(new DateTime(), $updatedBy);

			if ($objectLock instanceof ObjectLock && Configuration::shouldAutoUnlockObjectOnSave())
			{
				$this->unlock($updatedBy);
			}

			$this->updateLinksAttributes(array(
				'ETAG' => $this->getEtag(),
				'GLOBAL_CONTENT_VERSION' => $this->getGlobalContentVersion(),
				'SIZE' => $file['FILE_SIZE'],
				'UPDATE_TIME' => $this->getUpdateTime(),
				'SYNC_UPDATE_TIME' => $this->getSyncUpdateTime(),
				'UPDATED_BY' => $updatedBy,
			));
			$this->updateRelated($updatedBy);

			if (!$this->isFirstVersion())
			{
				$auditResult = (new AuditObjectEventProducer())->publishContentChanged(
					$this,
					(int)$this->getUpdatedBy(),
					$oldSize,
					(int)$this->getSize()
				);
				if (!$this->applyAuditResult($auditResult))
				{
					return false;
				}

				// The first version is the content the file was created with, and the creation of the file is
				// published on its own: the journal would otherwise take two records for every upload.
				$this->publishToJournal(
					ChangeType::ContentVersion,
					$updatedBy,
					['size', 'versionId', 'versionNumber', 'updatedAt', 'updatedBy'],
				);
			}

			return true;
		};

		if ($auditTransaction !== null)
		{
			$success = (bool)$auditTransaction
				->join(
					static fn(AuditTransaction $transaction) => $mutation()
						? AuditTransactionOutcome::success(true)
						: AuditTransactionOutcome::failure(),
				)
				->getValue()
			;
		}
		else
		{
			$success = $this->runAuditCoveredMutation($mutation);
		}

		if(!$success)
		{
			return false;
		}

		// The hook may run after the flag is reset, so the decision is taken here, not inside it.
		$skipRecentlyUsed = $this->skipRecentlyUsed;
		$afterCommit = function () use ($updatedBy, $skipRecentlyUsed): void {
			$driver = Driver::getInstance();
			if (!$skipRecentlyUsed && $this->getStorage()->isUseInternalRights())
			{
				$driver->getRecentlyUsedManager()->push(
					$updatedBy,
					$this
				);
			}

			if ($this->isFirstVersion())
			{
				//initial full index
				$driver->getIndexManager()->indexFile($this);
			}
			else
			{
				//just update content
				$driver->getIndexManager()->updateFileContent($this);
			}

			$driver->getIndexManager()->scheduleBoardIndex($this, (int)$this->getGlobalContentVersion());

			$objectEvent = $this->makeObjectEvent(
				'contentUpdated',
				[
					'object' => [
						'id' => (int)$this->getId(),
						'name' => $this->getName(),
						'parentId' => (int)$this->getParentId(),
						'updatedBy' => (int)$this->getUpdatedBy(),
					],
					'updatedBy' => [
						'infoToken' => UserInfoToken::generateTimeLimitedToken($this->getUpdatedBy(), $this->getId()),
					]
				]
			);
			$objectEvent->sendToObjectChannel();

			//todo little hack...We don't synchronize file in folder with uploaded files. And we have not to send notify by pull
			if($this->parent === null || $this->parent && $this->parent->getCode() !== Folder::CODE_FOR_UPLOADED_FILES)
			{
				$driver->sendChangeStatusToSubscribers($this, 'quick');

				$currentUpdatedBy = $this->getUpdatedBy();
				if($currentUpdatedBy)
				{
					$driver->sendEvent($currentUpdatedBy, 'live', array(
						'objectId' => $this->getId(),
						'action' => 'commit',
						'contentVersion' => (int)$this->getGlobalContentVersion(),
						'size' => (int)$this->getSize(),
						'formatSize' => (string)CFile::formatSize($this->getSize()),
					));
				}
			}
		};

		if ($auditTransaction !== null)
		{
			$auditTransaction->addAfterCommit($afterCommit);
		}
		elseif (AuditTransaction::isActive())
		{
			AuditTransaction::run(static function (AuditTransaction $transaction) use ($afterCommit): bool {
				$transaction->addAfterCommit($afterCommit);

				return true;
			});
		}
		else
		{
			$afterCommit();
		}

		return true;
	}

	/**
	 * Adds new version to file.
	 *
	 * The method may joins version with last version.
	 *
	 * @param array $file Structure like $_FILES.
	 * @param int $createdBy Id of user.
	 * @param bool $disableJoin If set false the method attempts to join version with last version (@see \Bitrix\Disk\Configuration::getSecondsToJoinVersion()).
	 * @param array $options Options of the write operation. Unknown keys flow down the write path untouched.
	 * @return Version|null
	 * @throws \Bitrix\Main\SystemException
	 */
	public function addVersion(
		array $file,
		$createdBy,
		$disableJoin = false,
		array $options = [],
		?AuditTransaction $auditTransaction = null,
	)
	{
		if ($auditTransaction !== null)
		{
			return $auditTransaction
				->join(function (AuditTransaction $transaction) use ($file, $createdBy, $disableJoin, $options) {
					$version = $this->addVersionInsideTransaction(
						$file,
						$createdBy,
						$disableJoin,
						$options,
						$transaction,
					);

					return $version instanceof Version
						? AuditTransactionOutcome::success($version)
						: AuditTransactionOutcome::failure();
				})
				->getValue()
			;
		}

		if (AuditTransaction::isActive())
		{
			return AuditTransaction::run(
				fn(AuditTransaction $owner) => $this->addVersionInsideTransaction(
					$file,
					$createdBy,
					(bool)$disableJoin,
					$options,
					$owner,
				),
			);
		}

		$transaction = new AuditTransaction(Application::getConnection());
		$result = $transaction->execute(
			function (AuditTransaction $transaction) use ($file, $createdBy, $disableJoin, $options) {
				$version = $this->addVersionInsideTransaction(
					$file,
					$createdBy,
					$disableJoin,
					$options,
					$transaction,
				);

				return $version instanceof Version
					? AuditTransactionOutcome::success($version)
					: AuditTransactionOutcome::failure();
			},
		);

		/** @var Version|null $version */
		$version = self::unwrapAuditTransactionResult($result);

		return $version;
	}

	private function addVersionInsideTransaction(
		array $file,
		$createdBy,
		bool $disableJoin,
		array $options,
		?AuditTransaction $auditTransaction,
	): ?Version
	{
		$this->errorCollection->clear();

		$commentAttachedObjects = $options['commentAttachedObjects'] ?? true;
		$disableAutoBizProc = !empty($options['disableAutoBizProc']);

		if(Configuration::isEnabledStorageSizeRestriction())
		{
			static::checkRequiredInputParams($file, array(
				'FILE_SIZE'
			));

			if($this->errorCollection->hasErrors())
			{
				return null;
			}

			if(!$this->getStorage()->isPossibleToUpload($file['FILE_SIZE']))
			{
				$this->errorCollection[] = new Error(
					Loc::getMessage('DISK_FILE_MODEL_ERROR_SIZE_RESTRICTION'), self::ERROR_SIZE_RESTRICTION
				);

				return null;
			}
		}

		if (!$this->checkFormatPolicy($options, self::resolveVersionPolicyActorId($createdBy, $options), $file))
		{
			return null;
		}

		$needToJoin = !$disableJoin && $this->isNeedToJoinVersion($createdBy);
		// The version the join rewrites is read once and serves both: the size the quota measures the
		// write by, and the row the join itself writes. Nothing between the two touches that row.
		$versionToJoin = $needToJoin ? $this->getLastVersion() : null;

		// The node every write of content passes through, the creation of a file included: whatever
		// the early points of the transports let through, the quota is answered for here. The order
		// is the one of the upload path: a format nothing can save is named before a quota the user
		// can free up.
		if (!$this->checkPersonalQuota($file, $options, (int)$versionToJoin?->getSize()))
		{
			return null;
		}

		$this->markFormatPolicyChecked();
		if(!$this->updateContent($file, $createdBy, $options, $auditTransaction))
		{
			return null;
		}

		if($needToJoin)
		{
			$lastVersion = $this->joinVersion($versionToJoin, $auditTransaction);
			if ($lastVersion)
			{
				$afterCommit = function () use ($createdBy, $disableAutoBizProc): void {
					$this->cleanVersionsOverLimitByTime($createdBy);
					$this->tryToRunBizProcAfterEdit($disableAutoBizProc);
				};
				if ($auditTransaction !== null)
				{
					$auditTransaction->addAfterCommit($afterCommit);
				}
				else
				{
					$afterCommit();
				}

				return $lastVersion;
			}
		}

		$extraVersionData = [];
		if (isset($options['UPLOAD_INTENT_TOKEN']))
		{
			$extraVersionData['UPLOAD_INTENT_TOKEN'] = $options['UPLOAD_INTENT_TOKEN'];
		}

		$versionModel = Version::add(array_merge(array(
			'OBJECT_ID' => $this->id,
			'OBJECT' => $this,
			'FILE_ID' => $this->fileId,
			'NAME' => $this->name,
			'CREATED_BY' => $createdBy,
		), $this->getHistoricalData(), $extraVersionData), $this->errorCollection);

		if(!$versionModel)
		{
			return null;
		}

		$this->resetHeadVersionToAttachedObject($versionModel);

		$afterCommit = function () use ($createdBy, $commentAttachedObjects, $versionModel, $disableAutoBizProc): void {
			$this->cleanVersionsOverLimitByCount($createdBy);
			$this->cleanVersionsOverLimitByTime($createdBy);
			if ($commentAttachedObjects)
			{
				$this->commentAttachedObjects($versionModel);
			}

			if ($this->isFirstVersion())
			{
				$this->tryToRunBizProcAfterCreate($disableAutoBizProc);
			}
			else
			{
				$this->tryToRunBizProcAfterEdit($disableAutoBizProc);
			}

			Application::getInstance()->getTaggedCache()->clearByTag("disk_file_{$this->id}");
		};

		if ($auditTransaction !== null)
		{
			$auditTransaction->addAfterCommit($afterCommit);
		}
		else
		{
			$afterCommit();
		}

		return $versionModel;
	}

	/**
	 * Applies the format policy to the name of this file and to the content being written.
	 *
	 * The mark left by the outer node of the same write operation is consumed here: an operation
	 * asks the policy once, at the outermost node it passes through.
	 *
	 * @param array $options Options of the write operation.
	 * @param int|null $actorId User performing the write, whose policy judges it.
	 * @param array $file Structure like $_FILES of the content being written.
	 * @return bool
	 */
	private function checkFormatPolicy(array $options, ?int $actorId, array $file = []): bool
	{
		if ($this->formatPolicyChecked)
		{
			$this->formatPolicyChecked = false;

			return true;
		}

		$error = self::findFormatPolicyError($this->getName(), $options, $actorId);
		if ($error === null)
		{
			// The content is the only thing this node reaches the storage for, so it is looked at
			// after the name and still before anything of the file is written down.
			$contentHead = self::readStoredContentHead((int)($file['ID'] ?? 0), $options, $actorId);
			if ($contentHead !== null)
			{
				$error = self::findFormatPolicyError($this->getName(), $options, $actorId, $contentHead);
			}
		}

		if ($error)
		{
			$this->errorCollection[] = $error;

			return false;
		}

		return true;
	}

	/**
	 * Lets the inner node of the running write operation skip the policy: this node has just
	 * applied it to the same name.
	 */
	private function markFormatPolicyChecked(): void
	{
		$this->formatPolicyChecked = true;
	}

	/**
	 * Applies the format policy to an upload while its content is still at hand, before the file
	 * layer stores it. The head read here saves the write node a read from the storage, and the
	 * mark left in $options tells the node that the content has already been looked at.
	 *
	 * @internal
	 * @param array $fileArray Structure like $_FILES.
	 * @param string $name Name the file is written under.
	 * @param int $actorId User performing the write, whose policy judges it.
	 * @param array $options Options of the write operation.
	 * @return Error|null
	 */
	public static function findFormatPolicyErrorForUpload(
		array $fileArray,
		string $name,
		int $actorId,
		array &$options,
	): ?Error
	{
		$policyActorId = self::resolveFormatPolicyActorId($actorId);

		$contentHead = self::readUploadedContentHead($fileArray, $options, $policyActorId);
		if ($contentHead !== null)
		{
			$options[WriteOperationContext::OPTION_KEY_SIGNATURE_CHECKED] = true;
		}

		return self::findFormatPolicyError($name, $options, $policyActorId, $contentHead);
	}

	/**
	 * Applies the format policy to this file as it is already stored: the name it is kept under and
	 * the beginning of its content. A move and a restore carry stored content to a place of the one
	 * performing them, so they are judged by the policy of that user before anything is written.
	 * The content is read only when it can still change the verdict, within the read budget. Content
	 * the spent budget of its kind of storage leaves unread is refused as not verified; content the
	 * storage refuses to give or that cannot be read is left to the name.
	 *
	 * @internal
	 * @param int $actorId User moving or restoring the file; none gets the global rule.
	 * @param ContentHeadReader|null $contentHeadReader Reader of the operation over many files, whose
	 * budget and rows of b_file the file shares; a file judged on its own gets a reader of its own.
	 * @param bool|null $inCloud Whether the content lies in a cloud storage, when the operation knows it.
	 * @return Error|null
	 */
	public function findFormatPolicyErrorForStored(
		int $actorId,
		?ContentHeadReader $contentHeadReader = null,
		?bool $inCloud = null,
	): ?Error
	{
		$policyActorId = self::resolveFormatPolicyActorId($actorId);
		$options = [WriteOperationContext::OPTION_KEY => WriteOperationContext::StoredContentMove];
		// a file in the trash can keeps a suffix after its extension
		$name = $this->getNameWithoutTrashCanSuffix();

		$error = self::findFormatPolicyError($name, $options, $policyActorId);
		if ($error !== null)
		{
			return $error;
		}

		$contentHeadReader ??= new ContentHeadReader();
		$contentHead = self::readStoredContentHead(
			(int)$this->getFileId(),
			$options,
			$policyActorId,
			$contentHeadReader,
			$inCloud,
		);
		if ($contentHead === null)
		{
			return $contentHeadReader->wasLastReadSkippedForBudget() ? self::createFormatNotVerifiedError() : null;
		}

		return self::findFormatPolicyError($name, $options, $policyActorId, $contentHead);
	}

	/**
	 * A link carries no content of its own, so a move of a link is not judged.
	 */
	protected function findFormatPolicyErrorForMove(int $movedBy): ?Error
	{
		if ($this->isLink())
		{
			return null;
		}

		return $this->findFormatPolicyErrorForStored($movedBy);
	}

	/**
	 * Returns the refusal of the format policy for the name, or null when the write is allowed.
	 * The refusal carries one code and one text, whichever way the format was recognized.
	 *
	 * @param string $name Name of the file being written.
	 * @param array $options Options of the write operation.
	 * @param int|null $actorId User performing the write, whose policy judges it.
	 * @param string|null $contentHead Beginning of the content being written, when it is known.
	 * @return Error|null
	 */
	private static function findFormatPolicyError(
		string $name,
		array $options,
		?int $actorId,
		?string $contentHead = null,
	): ?Error
	{
		$result = self::getFormatPolicyService()->isAllowed(
			$name,
			WriteOperationContext::createFromOptions($options),
			$actorId,
			$contentHead,
			self::resolveEditingSessionStartedAt($options),
		);

		if ($result->isSuccess())
		{
			return null;
		}

		return new Error(
			Loc::getMessage('DISK_FILE_MODEL_ERROR_FORMAT_RESTRICTED'), self::ERROR_FORMAT_RESTRICTED
		);
	}

	/**
	 * The refusal of stored content the policy needs to look at and the spent read budget left unread.
	 */
	private static function createFormatNotVerifiedError(): Error
	{
		return new Error(
			Loc::getMessage('DISK_FILE_MODEL_ERROR_FORMAT_NOT_VERIFIED'), self::ERROR_FORMAT_NOT_VERIFIED
		);
	}

	/**
	 * Returns the beginning of the content already stored by the file layer. The storage is read
	 * only when the read can still change the verdict: content looked at before it was stored
	 * leaves its mark in $options, and the policy itself says whether the check gets that far.
	 *
	 * @param int $fileId Id of the content in b_file.
	 * @param array $options Options of the write operation.
	 * @param int|null $actorId User performing the write.
	 * @param ContentHeadReader|null $contentHeadReader Reader of the running operation, if it has one.
	 * @param bool|null $inCloud Whether the content lies in a cloud storage, when the caller knows it.
	 * @return string|null
	 */
	private static function readStoredContentHead(
		int $fileId,
		array $options,
		?int $actorId,
		?ContentHeadReader $contentHeadReader = null,
		?bool $inCloud = null,
	): ?string
	{
		if (!self::isContentHeadNeeded($options, $actorId))
		{
			return null;
		}

		return ($contentHeadReader ?? new ContentHeadReader())->readStoredHead($fileId, $inCloud);
	}

	/**
	 * Returns the beginning of an upload lying outside the storage: the bytes are given as they
	 * are, or read from the temporary file the upload arrived in.
	 *
	 * @param array $fileArray Structure like $_FILES.
	 * @param array $options Options of the write operation.
	 * @param int|null $actorId User performing the write.
	 * @return string|null
	 */
	private static function readUploadedContentHead(array $fileArray, array $options, ?int $actorId): ?string
	{
		if (!self::isContentHeadNeeded($options, $actorId))
		{
			return null;
		}

		if (isset($fileArray['content']) && is_string($fileArray['content']))
		{
			$head = substr($fileArray['content'], 0, SignatureDetector::HEAD_LENGTH);

			return $head === '' ? null : $head;
		}

		$path = $fileArray['tmp_name'] ?? null;

		return is_string($path) ? (new ContentHeadReader())->readHeadFromPath($path) : null;
	}

	private static function isContentHeadNeeded(array $options, ?int $actorId): bool
	{
		if (!empty($options[WriteOperationContext::OPTION_KEY_SIGNATURE_CHECKED]))
		{
			return false;
		}

		return self::getFormatPolicyService()->needsContentHead(
			WriteOperationContext::createFromOptions($options),
			$actorId,
			self::resolveEditingSessionStartedAt($options),
		);
	}

	/**
	 * The write is judged by the policy of the one it is written on behalf of; an author the write
	 * does not name, like the system user, is judged by the global rule.
	 */
	private static function resolveFormatPolicyActorId(mixed $userId): ?int
	{
		return is_numeric($userId) && (int)$userId > 0 ? (int)$userId : null;
	}

	/**
	 * A version written out of an editing session is judged by the policy of the one who opened the
	 * session, whichever participant authored the last edit.
	 */
	private static function resolveVersionPolicyActorId(mixed $authorId, array $options): ?int
	{
		$sessionOwnerId = $options[WriteOperationContext::OPTION_KEY_EDITING_SESSION_OWNER_ID] ?? null;
		if (
			$sessionOwnerId !== null
			&& WriteOperationContext::createFromOptions($options) === WriteOperationContext::EditingSession
		)
		{
			return self::resolveFormatPolicyActorId($sessionOwnerId);
		}

		return self::resolveFormatPolicyActorId($authorId);
	}

	/**
	 * @internal Also serves the checks of a folder whose files are moved or restored.
	 */
	public static function getFormatPolicyService(): FormatPolicyService
	{
		return ServiceLocator::getInstance()->get('disk.formatPolicyService');
	}

	private static function resolveEditingSessionStartedAt(array $options): ?int
	{
		$startedAt = $options[WriteOperationContext::OPTION_KEY_EDITING_SESSION_STARTED_AT] ?? null;

		return is_numeric($startedAt) ? (int)$startedAt : null;
	}

	/**
	 * Applies the personal quota to the content being written into the storage of this file.
	 *
	 * @param array $file Structure like $_FILES of the content being written.
	 * @param array $options Options of the write operation.
	 * @param int $replacedSize Size of the version this write rewrites, bytes.
	 * @return bool
	 */
	private function checkPersonalQuota(array $file, array $options, int $replacedSize = 0): bool
	{
		$sizeToGain = (int)($file['FILE_SIZE'] ?? 0) - $replacedSize;
		if ($sizeToGain <= 0)
		{
			return true;
		}

		$error = self::findPersonalQuotaErrorForSize($this->getStorage(), $sizeToGain, $options);
		if ($error)
		{
			$this->errorCollection[] = $error;

			return false;
		}

		return true;
	}

	/**
	 * Size the storage gets back when the write joins the last version: joining rewrites the row of
	 * that version instead of adding one, so the counter moves by the difference of the two sizes.
	 * A file with no version yet - the first write of a file being created - replaces nothing.
	 *
	 * @return int
	 */
	private function findSizeOfTheJoinedVersion(): int
	{
		return (int)$this->getLastVersion()?->getSize();
	}

	/**
	 * Applies the personal quota to an upload by the size it declares, while the content is still
	 * outside the storage. The write node answers for the quota all the same; this point spares the
	 * portal the storing of content that is about to be refused - a cloud storage would take the
	 * upload the whole way before the node deleted it.
	 *
	 * A size that cannot be told from the upload leaves the check to the write node.
	 *
	 * @internal
	 * @param array $fileArray Structure like $_FILES.
	 * @param Storage|null $storage Storage the content is written into.
	 * @param array $options Options of the write operation.
	 * @param int $replacedSize Size of the version the write rewrites, bytes.
	 * @return Error|null
	 */
	public static function findPersonalQuotaErrorForUpload(
		array $fileArray,
		?Storage $storage,
		array $options,
		int $replacedSize = 0,
	): ?Error
	{
		$fileSize = self::resolveUploadedFileSize($fileArray);
		if ($fileSize === null)
		{
			return null;
		}

		$sizeToGain = $fileSize - $replacedSize;

		return $sizeToGain <= 0 ? null : self::findPersonalQuotaErrorForSize($storage, $sizeToGain, $options);
	}

	/**
	 * Applies the personal quota to an upload about to become the next version of this file, while
	 * the content is still outside the storage. This is the one place the growth of the disk by a
	 * version is told: a write that joins the last version rewrites its row, so the disk grows by
	 * the difference of the two sizes, and a rewrite of the same size takes up nothing at all.
	 *
	 * The version is written into the real object of this file, so the disk judged here is the one
	 * that object lies in and not the one a link to it lies in. The join is asked of the same object:
	 * a write that disables the join is not one this answers for.
	 *
	 * Nothing is written and the errors of the object are left as they were.
	 *
	 * @internal
	 * @param array $fileArray Structure like $_FILES.
	 * @param int $createdBy Id of the user writing the version.
	 * @param array $options Options of the write operation.
	 * @return Error|null
	 */
	public function findPersonalQuotaErrorForVersionUpload(array $fileArray, $createdBy, array $options): ?Error
	{
		$realObject = $this->getRealObject();
		$joinsTheLastVersion = $realObject !== null && $realObject->isNeedToJoinVersion($createdBy);

		return self::findPersonalQuotaErrorForUpload(
			$fileArray,
			$realObject?->getStorage() ?? $this->getStorage(),
			$options,
			$joinsTheLastVersion ? $realObject->findSizeOfTheJoinedVersion() : 0,
		);
	}

	/**
	 * Returns the refusal of the personal quota for this write, or null when the write is allowed.
	 *
	 * The size is the one the write adds to the storage, so a caller answering for several files at
	 * once - a group action of the user - asks about their sum and gets one refusal for the whole.
	 *
	 * @internal
	 * @param Storage|null $storage Storage the content is written into.
	 * @param int $fileSize Size being written, bytes.
	 * @param array $options Options of the write operation.
	 * @return Error|null
	 */
	public static function findPersonalQuotaErrorForSize(?Storage $storage, int $fileSize, array $options): ?Error
	{
		$result = self::getPersonalQuotaService()->canWriteToStorage(
			$storage,
			$fileSize,
			WriteOperationContext::createFromOptions($options),
		);

		if ($result->isSuccess())
		{
			return null;
		}

		$refusalData = ($result->getErrors()[0] ?? null)?->getCustomData();

		return new Error(
			self::makePersonalQuotaMessage($storage, $refusalData),
			self::ERROR_PERSONAL_QUOTA_EXCEEDED,
		);
	}

	/**
	 * The words the refusal carries. The employee the disk belongs to is told how much of it is
	 * spent and what to free up; everyone else writing into a disk of somebody else - an upload into
	 * a folder a colleague shared with them - is told who to ask, and nothing about the state of a
	 * disk that is not theirs to know.
	 *
	 * @param Storage|null $storage Storage the content is written into.
	 * @param array|null $refusalData Sizes the refusal was measured by.
	 * @return string
	 */
	private static function makePersonalQuotaMessage(?Storage $storage, ?array $refusalData): string
	{
		if (!self::isStorageOfCurrentUser($storage))
		{
			return (string)Loc::getMessage('DISK_FILE_MODEL_ERROR_PERSONAL_QUOTA_EXCEEDED_FOREIGN');
		}

		return (string)Loc::getMessage('DISK_FILE_MODEL_ERROR_PERSONAL_QUOTA_EXCEEDED', [
			'#USED#' => CFile::formatSize((int)($refusalData[PersonalQuotaService::REFUSAL_DATA_USED] ?? 0)),
			'#LIMIT#' => CFile::formatSize((int)($refusalData[PersonalQuotaService::REFUSAL_DATA_LIMIT] ?? 0)),
		]);
	}

	/**
	 * Whether the disk being written into belongs to the employee behind the request. A refusal at
	 * all means the storage is a personal one, so its entity is the employee the quota belongs to.
	 *
	 * A write nobody stands behind - an agent, a console run - is a stranger here as well.
	 *
	 * @param Storage|null $storage Storage the content is written into.
	 * @return bool
	 */
	private static function isStorageOfCurrentUser(?Storage $storage): bool
	{
		$ownerId = (int)$storage?->getEntityId();

		return $ownerId > 0 && $ownerId === (int)CurrentUser::get()->getId();
	}

	/**
	 * Size an upload declares, or null when it declares none. The size is told from what the upload
	 * carries with it - the key of the array, the temporary file or the bytes themselves - and never
	 * from the permanent storage: at this point the content has not reached it.
	 */
	private static function resolveUploadedFileSize(array $fileArray): ?int
	{
		$declaredSize = $fileArray['size'] ?? null;
		if (is_numeric($declaredSize))
		{
			return (int)$declaredSize;
		}

		if (isset($fileArray['content']) && is_string($fileArray['content']))
		{
			return strlen($fileArray['content']);
		}

		$path = $fileArray['tmp_name'] ?? null;
		if (is_string($path) && is_file($path))
		{
			$size = filesize($path);

			return $size === false ? null : $size;
		}

		return null;
	}

	private static function getPersonalQuotaService(): PersonalQuotaService
	{
		return ServiceLocator::getInstance()->get(PersonalQuotaService::class);
	}

	private static function getStorageUsageCounter(): StorageUsageCounter
	{
		return ServiceLocator::getInstance()->get(StorageUsageCounter::class);
	}

	/**
	 * Moves the counter of the owner of the personal storage this file lies in by what a write past
	 * the model of the version took away or added; a storage the quota says nothing about takes none.
	 *
	 * @param int $deltaBytes Movement of the source of truth, bytes.
	 * @return void
	 */
	private function applyPersonalStorageUsageDelta(int $deltaBytes): void
	{
		if ($deltaBytes === 0)
		{
			return;
		}

		$ownerId = $this->findPersonalStorageOwnerId();
		if ($ownerId !== null)
		{
			self::getStorageUsageCounter()->applyDelta($ownerId, $deltaBytes);
		}
	}

	/**
	 * Hands the volume of this file over from the personal disk it left to the one it landed in. The
	 * content is not written anew by such a move, so no version row moves and nothing else counts it;
	 * a storage the quota says nothing about has no owner and takes no delta.
	 *
	 * @param int|null $sourceOwnerId Owner of the personal storage the file left, read before the switch.
	 * @param int $movedSize Volume of the version rows that went with it, bytes.
	 * @return void
	 */
	private function moveUsageBetweenPersonalStorages(?int $sourceOwnerId, int $movedSize): void
	{
		if ($movedSize === 0)
		{
			return;
		}

		$usageCounter = self::getStorageUsageCounter();

		if ($sourceOwnerId !== null)
		{
			$usageCounter->applyDelta($sourceOwnerId, -$movedSize);
		}

		$targetOwnerId = $this->findPersonalStorageOwnerId();
		if ($targetOwnerId !== null)
		{
			$usageCounter->applyDelta($targetOwnerId, $movedSize);
		}
	}

	/**
	 * Volume this file takes up: the sizes of its version rows, which is what the used size of a
	 * personal disk is counted over. A link keeps no version rows and takes up nothing.
	 *
	 * @return int
	 */
	private function countSizeOfVersions(): int
	{
		$row =
			(new Query(VersionTable::getEntity()))
				->registerRuntimeField('', new ExpressionField('TOTAL_SIZE', 'SUM(%s)', 'SIZE'))
				->addSelect('TOTAL_SIZE')
				->addFilter('=OBJECT_ID', $this->id)
				->exec()
				->fetch()
		;

		return (int)($row['TOTAL_SIZE'] ?? 0);
	}

	/**
	 * Owner of the personal storage this file lies in, or null for everything the quota says nothing
	 * about: a group storage, the common one, the storage of an application.
	 *
	 * @return int|null
	 */
	private function findPersonalStorageOwnerId(): ?int
	{
		$storage = $this->getStorage();
		if (!($storage?->getProxyType() instanceof ProxyType\User))
		{
			return null;
		}

		$ownerId = (int)$storage->getEntityId();

		return $ownerId > 0 ? $ownerId : null;
	}

	private function generateEtag()
	{
		return md5(mt_rand() . mt_rand());
	}

	private function tryToRunBizProcAfterCreate(bool $disableAutoBizProc = false)
	{
		if ($disableAutoBizProc)
		{
			// Docked commit path: the workflow is started by durable-committed
			// side effects, not by the domain write. Single point of suppression.
			return;
		}

		if (!Integration\BizProcManager::isAvailable())
		{
			return;
		}

		$storage = $this->getStorage();
		if($storage && $this->getStorage()->isEnabledBizProc())
		{
			BizProcDocument::runAfterCreate($this->storageId, $this->id);
		}
	}

	private function tryToRunBizProcAfterEdit(bool $disableAutoBizProc = false)
	{
		if ($disableAutoBizProc)
		{
			return;
		}

		if (!Integration\BizProcManager::isAvailable())
		{
			return;
		}

		$storage = $this->getStorage();
		if($storage && $this->getStorage()->isEnabledBizProc())
		{
			BizProcDocument::runAfterEdit($this->storageId, $this->id);
		}
	}

	public function commentAttachedObjects(Version $version): void
	{
		$createdBy = $version->getCreatedBy();
		$valueVersionUf = FileUserType::NEW_FILE_PREFIX . $version->getId();

		/** @var User $createUser */
		$createUser = User::loadById($createdBy);
		if (!$createUser)
		{
			return;
		}

		$text = $this->getTextForComment($createUser);
		$attachedObjects = $this->getAttachedObjects([
			'filter' => [
				'=ALLOW_AUTO_COMMENT' => 1,
			],
		]);
		foreach ($attachedObjects as $attachedObject)
		{
			if (!$attachedObject->getAllowAutoComment())
			{
				continue;
			}

			AttachedObject::storeDataByObjectId($this->getId(), [
				'IS_EDITABLE' => $attachedObject->isEditable(),
				'ALLOW_EDIT' => $attachedObject->getAllowEdit(),
				'STATE' => 'commentAttachedObjects',
			]);

			$attachedObject->getConnector()->addComment($createdBy, [
				'text' => $text,
				'versionId' => $valueVersionUf,
				'authorGender' => $createUser->getPersonalGenderExact()
			]);

			AttachedObject::storeDataByObjectId($this->getId(), null);
		}
	}

	private function cleanVersionsOverLimitByCount(int $createdBy): void
	{
		$versionLimitPerFile = Configuration::getVersionLimitPerFile();
		if ($this->getGlobalContentVersion() > 1 && $versionLimitPerFile > 0)
		{
			$olderVersions = $this->getVersions([
				'order' => ['ID' => 'DESC'],
				'offset' => $versionLimitPerFile,
				'limit' => 100,
			]);

			foreach ($olderVersions as $oldVersion)
			{
				$oldVersion->delete($createdBy);
			}
		}
	}

	/**
	 * @see \Bitrix\Disk\Internals\Cleaner::deleteVersionsByTtl()
	 * @param int $createdBy
	 * @return void
	 * @throws Main\ArgumentException
	 */
	private function cleanVersionsOverLimitByTime(int $createdBy): void
	{
		$dayLimit = Configuration::getFileVersionTtl();
		if ($dayLimit === -1)
		{
			return;
		}

		if ($this->isFirstVersion())
		{
			return;
		}

		if ($this->hasAttachedObjects())
		{
			return;
		}

		$versions = $this->getVersions([
			'filter' => ['<CREATE_TIME' => DateTime::createFromTimestamp(time() - $dayLimit * 86400)],
			'order' => ['ID' => 'ASC'],
		]);

		foreach ($versions as $oldVersion)
		{
			if ($oldVersion->isHead())
			{
				continue;
			}

			$oldVersion->delete($createdBy);
		}
	}

	/**
	 * @param Version|null $lastVersion Version the write rewrites, or null when the file carries none.
	 * @return Version|null
	 */
	private function joinVersion(?Version $lastVersion, ?AuditTransaction $auditTransaction = null)
	{
		if (!$lastVersion)
		{
			return null;
		}

		$joinData = array('CREATE_TIME' => new DateTime);

		if (!$lastVersion->joinData(
			array_merge(
				$joinData,
				$this->getHistoricalData()
			)
		))
		{
			$this->errorCollection->add($lastVersion->getErrors());

			return null;
		}

		if ($this->prevFileId && $this->prevFileId != $this->fileId)
		{
			$previousFileId = $this->prevFileId;
			$deletePreviousContent = static function () use ($previousFileId): void {
				CFile::delete($previousFileId);
			};
			if ($auditTransaction !== null)
			{
				$auditTransaction->addAfterCommit($deletePreviousContent);
			}
			else
			{
				$deletePreviousContent();
			}
		}

		return $lastVersion;
	}

	private function isNeedToJoinVersion($createdBy)
	{
		if(Configuration::getFileVersionTtl() === 0 && !$this->hasAttachedObjects())
		{
			return true;
		}

		$secondsToJoinVersion = Configuration::getSecondsToJoinVersion();
		if ($secondsToJoinVersion === 0)
		{
			return false;
		}

		$now = new DateTime;
		if($this->updateTime && $this->updatedBy == $createdBy)
		{
			$updateTimestamp = $this->updateTime->getTimestamp();
			if($now->getTimestamp() - $updateTimestamp < $secondsToJoinVersion)
			{
				return true;
			}
		}

		return false;
	}

	private function resetHeadVersionToAttachedObject(Version $version)
	{
		if(Configuration::isEnabledKeepVersion())
		{
			return;
		}

		AttachedObjectTable::updateBatch(
			array(
				'VERSION_ID' => $version->getId(),
			),
			array(
				'OBJECT_ID' => $version->getObjectId(),
				'!=VERSION_ID' => null,
			)
		);
	}

	public function isFirstVersion(): bool
	{
		return (int)$this->getGlobalContentVersion() === 1;
	}

	private function getTextForComment(User $createUser)
	{
		if(!Configuration::isEnabledKeepVersion())
		{
			$text = Loc::getMessage('DISK_FILE_MODEL_UPLOAD_NEW_HEAD_VERSION_IN_COMMENT_M');
			if($createUser->getPersonalGender() == 'F')
			{
				$text = Loc::getMessage('DISK_FILE_MODEL_UPLOAD_NEW_HEAD_VERSION_IN_COMMENT_F');
			}
		}
		else
		{
			$text = Loc::getMessage('DISK_FILE_MODEL_UPLOAD_NEW_VERSION_IN_COMMENT_M');
			if($createUser->getPersonalGender() == 'F')
			{
				$text = Loc::getMessage('DISK_FILE_MODEL_UPLOAD_NEW_VERSION_IN_COMMENT_F');
			}
		}

		return $text;
	}

	/**
	 * Uploads new version to file.
	 * @see \Bitrix\Disk\File::addVersion().
	 * @param array $fileArray Structure like $_FILES.
	 * @param int $createdBy Id of user.
	 * @param array $options Options of the write operation (@see \Bitrix\Disk\File::addVersion()).
	 * @return Version|null
	 */
	public function uploadVersion(array $fileArray, $createdBy, array $options = [])
	{
		$this->errorCollection->clear();

		if(!isset($fileArray['MODULE_ID']))
		{
			$fileArray['MODULE_ID'] = Driver::INTERNAL_MODULE_ID;
		}

		if(empty($fileArray['type']))
		{
			$fileArray['type'] = '';
		}
		$fileArray['type'] = TypeFile::normalizeMimeType($fileArray['type'], $this->name);

		if (!Disk\Internals\FileHelper::hasValidFileKeys($fileArray))
		{
			$this->errorCollection->add(array(new Error('File data is not valid')));
			return null;
		}

		$formatPolicyError = self::findFormatPolicyErrorForUpload(
			$fileArray,
			$this->getName(),
			self::resolveVersionPolicyActorId($createdBy, $options) ?? 0,
			$options,
		);
		if ($formatPolicyError)
		{
			$this->errorCollection->add([$formatPolicyError]);

			return null;
		}

		$personalQuotaError = $this->findPersonalQuotaErrorForVersionUpload($fileArray, $createdBy, $options);
		if ($personalQuotaError)
		{
			$this->errorCollection->add([$personalQuotaError]);

			return null;
		}

		$fileId = CFile::saveFile($fileArray, Driver::INTERNAL_MODULE_ID, true, true);
		if(!$fileId)
		{
			$this->errorCollection->add(array(new Error(Loc::getMessage('DISK_FILE_MODEL_ERROR_COULD_NOT_SAVE_FILE'), self::ERROR_COULD_NOT_SAVE_FILE)));
			return null;
		}
		$updateTime = isset($fileArray['UPDATE_TIME'])? $fileArray['UPDATE_TIME'] : null;
		/** @var array $fileArray */

		$fileArray = CFile::getFileArray($fileId);
		if(!$fileArray)
		{
			CFile::delete($fileId);
			$this->errorCollection->add(array(new Error(Loc::getMessage('DISK_FILE_MODEL_ERROR_COULD_NOT_SAVE_FILE'), self::ERROR_COULD_NOT_GET_SAVED_FILE)));
			return null;
		}
		if($updateTime)
		{
			$fileArray['UPDATE_TIME'] = $updateTime;
		}
		$version = $this->addVersion($fileArray, $createdBy, false, $options);
		if(!$version)
		{
			CFile::delete($fileId);
		}

		return $version;
	}

	/**
	 * Returns version of file by version id.
	 *
	 * @param int $versionId Id of version.
	 * @return Version
	 */
	public function getVersion($versionId)
	{
		$version = Version::load(array(
			'ID' => $versionId,
			'OBJECT_ID' => $this->id,
		));
		if($version)
		{
			$version->setAttributes(array('OBJECT' => $this));
		}

		return $version;
	}

	/**
	 * Gets last version of the file.
	 * @return Version|null
	 */
	public function getLastVersion()
	{
		$versions = $this->getVersions(array('limit' => 1));
		return array_shift($versions)?: null;
	}

	/**
	 * Returns all versions by file.
	 * @param array $parameters Parameters.
	 * @return Version[]
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Exception
	 */
	public function getVersions(array $parameters = array())
	{
		if(!isset($parameters['filter']))
		{
			$parameters['filter'] = array();
		}
		$parameters['filter']['OBJECT_ID'] = $this->id;

		if(!isset($parameters['order']))
		{
			$parameters['order'] = array(
				'CREATE_TIME' => 'DESC',
			);
		}

		$versions = Version::getModelList($parameters);
		foreach($versions as $version)
		{
			$version->setAttributes(array('OBJECT' => $this));
		}
		unset($version);

		return $versions;
	}

	/**
	 * Restores file from the version.
	 *
	 * The method is similar with (@see Bitrix\Disk\File::addVersion()).
	 *
	 * @param Version $version Version which need to restore.
	 * @param int $createdBy Id of user.
	 * @return bool
	 */
	public function restoreFromVersion(Version $version, $createdBy)
	{
		$this->errorCollection->clear();

		if($version->getObjectId() != $this->getRealObjectId())
		{
			$this->errorCollection->add(array(new Error(Loc::getMessage('DISK_FILE_MODEL_ERROR_COULD_NOT_RESTORE_FROM_ANOTHER_OBJECT'), self::ERROR_COULD_NOT_RESTORE_FROM_OBJECT)));

			return false;
		}

		$forkFileId = \CFile::CloneFile($version->getFileId());
		if(!$forkFileId)
		{
			$this->errorCollection->add(array(new Error(Loc::getMessage('DISK_FILE_MODEL_ERROR_COULD_NOT_COPY_FILE'), self::ERROR_COULD_NOT_COPY_FILE)));
			return false;
		}


		// A restore makes an old revision the current content of the file, so the write node judges
		// that content as it judges any other: the bytes of a revision stored before the policy may
		// carry a format the policy now names, and the name of the file promises nothing about them.
		if($this->addVersion(\CFile::getFileArray($forkFileId), $createdBy, true) === null)
		{
			\CFile::delete($forkFileId);

			return false;
		}

		return true;
	}

	/**
	 * Moves object to another folder.
	 * Support cross-storage move (mark deleted + create new)
	 * @param Folder $folder             Destination folder.
	 * @param int    $movedBy            User id of user, which move file.
	 * @param bool   $generateUniqueName Generates unique name for object if in destination directory.
	 * @return BaseObject|null
	 */
	public function moveTo(Folder $folder, $movedBy, $generateUniqueName = false)
	{
		if(Configuration::isEnabledObjectLock())
		{
			$objectLock = $this->getLock();
			if($objectLock && $objectLock->isExclusive() && !$objectLock->canUnlock($movedBy))
			{
				$this->errorCollection[] = new Error(Loc::getMessage('DISK_FILE_MODEL_ERROR_EXCLUSIVE_LOCK'), self::ERROR_EXCLUSIVE_LOCK);
				return null;
			}
		}

		return parent::moveTo($folder, $movedBy, $generateUniqueName);
	}

	/**
	 * Moves file to folder from another storage.
	 * @see moveInAnotherStorage(), moveInSameStorage() to understand logic.
	 * This method is specific and can use nobody. Be careful!
	 *
	 *
	 * @internal
	 * @param Folder $folder             Destination folder.
	 * @param int    $movedBy            User id of user, which move file.
	 * @param bool   $generateUniqueName Generates unique name for object if in destination directory.
	 * @return bool
	 * @throws \Bitrix\Main\ArgumentException
	 */
	public function moveToAnotherFolder(Folder $folder, $movedBy, $generateUniqueName = false)
	{
		$realFolderId = $folder->getRealObject()->getId();
		if($this->getParentId() == $realFolderId)
		{
			return true;
		}

		$formatPolicyError = $this->findFormatPolicyErrorForMove((int)$movedBy);
		if ($formatPolicyError)
		{
			$this->errorCollection[] = $formatPolicyError;

			return false;
		}

		// This move rewrites the storage of the object instead of copying its content over, so the
		// version rows leave one disk and land in the other whole. Both the quota of the destination
		// and the counters of the two disks are answered for here: nothing below writes a version.
		$movesToAnotherStorage = $folder->getRealObject()->getStorageId() != $this->storageId;
		$movedSize = $movesToAnotherStorage ? $this->countSizeOfVersions() : 0;
		$sourceOwnerId = $movesToAnotherStorage ? $this->findPersonalStorageOwnerId() : null;

		if ($movedSize > 0)
		{
			$error = self::findPersonalQuotaErrorForSize(
				$folder->getRealObject()->getStorage(),
				$movedSize,
				[WriteOperationContext::OPTION_KEY => WriteOperationContext::StoredContentMove],
			);
			if ($error)
			{
				$this->errorCollection[] = $error;

				return false;
			}
		}

		$originalName = $this->name;

		$tmpName = $this->generateUniqueTmpName();
		$successUpdate = $this->update([
			'NAME' => $tmpName, // we need to make the file name unique before moving it
		]);
		if(!$successUpdate)
		{
			return false;
		}

		/** @var FileTable $tableClassName */
		$tableClassName = self::getTableClassName();
		$rightsCodesBeforeMove = Driver::getInstance()->getRightsManager()->captureCodesBeforeMove($this);

		$moveResult = $tableClassName::move(
			$this->id,
			$realFolderId,
			$this->getRealObject()->getUploadCleanupMarkerPresenceHint(),
		);
		if (!$moveResult->isSuccess())
		{
			$this->errorCollection->addFromResult($moveResult);

			return false;
		}
		$this->setAttributesFromResult($moveResult);

		//<editor-fold desc="Rename moved file back">
		$nameService = new ObjectNameService($originalName, $realFolderId, $this->getType());
		if ($generateUniqueName)
		{
			$nameService->requireUniqueName();
		}

		$result = $nameService->prepareName();
		if (!$result->isSuccess())
		{
			$this->errorCollection->add($result->getErrors());

			return false;
		}

		$successUpdate = $this->update([
			'NAME' => $result->getName()
		]);
		if (!$successUpdate)
		{
			return false;
		}
		//</editor-fold>

		Driver::getInstance()->getRightsManager()->setAfterMove($this, $rightsCodesBeforeMove);

		$subscribersAfterMove = Driver::getInstance()->collectSubscribers($this);
		Driver::getInstance()->sendChangeStatus($subscribersAfterMove);

		if ($movesToAnotherStorage)
		{
			$changeStorageIdResult = $tableClassName::changeStorageId($this->id, $folder->getRealObject()->getStorageId());
			if(!$changeStorageIdResult->isSuccess())
			{
				$this->errorCollection->addFromResult($changeStorageIdResult);
				return false;
			}

			$this->storage = $folder->getRealObject()->getStorage();
			$this->storageId = $folder->getRealObject()->getStorageId();

			$this->moveUsageBetweenPersonalStorages($sourceOwnerId, $movedSize);
		}

		$success = $this->update([
			'UPDATE_TIME' => new DateTime(),
			'UPDATED_BY' => $movedBy,
		]);
		if (!$success)
		{
			return null;
		}

		$this->publishToJournal(ChangeType::Moved, $movedBy, ['places']);

		$event = new UserRecentsEvent(
			(int)$movedBy,
			'recentFileMoved',
			[
				'file' => [
					'id' => (int)$this->getId(),
					'realObjectId' => (int)$this->getRealObjectId(),
					'storageId' => (int)$folder->getStorageId(),
					'parentId' => (int)$this->getParentId(),
				],
			],
		);
		$event->sendToUser();

		return $this;
	}

	/**
	 * Marks deleted object. It equals to move in trash can.
	 * @param int $deletedBy Id of user (or SystemUser::SYSTEM_USER_ID).
	 * @return bool
	 */
	public function markDeleted($deletedBy)
	{
		return $this->markDeletedWithAuditTransaction($deletedBy);
	}

	protected function markDeletedInsideAuditTransaction(
		$movedBy,
		AuditTransaction $auditTransaction,
	): bool
	{
		return $this->markDeletedWithAuditTransaction($movedBy, $auditTransaction);
	}

	private function markDeletedWithAuditTransaction(
		$deletedBy,
		?AuditTransaction $auditTransaction = null,
	): bool
	{
		$alreadyDeleted = $this->isDeleted();
		$status = $this->markDeletedInternal(
			$deletedBy,
			ObjectTable::DELETED_TYPE_ROOT,
			$auditTransaction,
		);
		if ($status && !$alreadyDeleted)
		{
			$sendNotification = static function (): void {
				Driver::getInstance()->getDeletionNotifyManager()->send();
			};
			if ($auditTransaction !== null)
			{
				$auditTransaction->addAfterCommit($sendNotification);
			}
			else
			{
				$sendNotification();
			}
		}

		return $status;
	}

	/**
	 * Internal method for deleting file as child of folder.
	 * @param int $deletedBy Id of user.
	 * @param int $deletedType Type of delete (@see ObjectTable::DELETED_TYPE_ROOT, ObjectTable::DELETED_TYPE_CHILD)
	 * @return bool
	 * @internal
	 */
	public function markDeletedInternal(
		$deletedBy,
		$deletedType = ObjectTable::DELETED_TYPE_ROOT,
		?AuditTransaction $auditTransaction = null,
	)
	{
		$alreadyDeleted = $this->isDeleted();
		$success = parent::markDeletedInternal($deletedBy, $deletedType, $auditTransaction);
		if ($success && !$alreadyDeleted)
		{
			$markDeletedLog = function () use ($deletedBy): void {
				Driver::getInstance()->getDeletedLogManager()->mark($this, $deletedBy);
			};
			if ($auditTransaction !== null)
			{
				$auditTransaction->addAfterCommit($markDeletedLog);
			}
			else
			{
				$markDeletedLog();
			}
		}

		return $success;
	}

	/**
	 * Restores object from trash can.
	 * @param int $restoredBy Id of user (or SystemUser::SYSTEM_USER_ID).
	 * @return bool
	 */
	public function restore($restoredBy)
	{
		if (!$this->isDeleted())
		{
			return true;
		}

		$formatPolicyError = $this->findFormatPolicyErrorForStored((int)$restoredBy);
		if ($formatPolicyError)
		{
			$this->errorCollection[] = $formatPolicyError;

			return false;
		}

		$needRecalculate = $this->deletedType == ObjectTable::DELETED_TYPE_CHILD;
		$status = parent::restoreInternal($restoredBy);
		if($status && $needRecalculate)
		{
			$this->recalculateDeletedTypeAfterRestore($restoredBy);
		}
		if($status)
		{
			$driver = Driver::getInstance();
			if ($this->getStorage()->isUseInternalRights())
			{
				$driver->getRecentlyUsedManager()->push(
					$restoredBy,
					$this
				);
			}
			$driver->getIndexManager()->indexFileByModuleSearch($this);
			$driver->sendChangeStatusToSubscribers($this);

			//it's necessary to reset cache because in default way \Bitrix\Disk\Driver::sendChangeStatusToSubscribers
			// doesn't reset tree cache when send notification on the file. But in case when we restore file - we have to.
			//The reason is that we can restore folder when we restore the file.
			$subscribers = Driver::getInstance()->collectSubscribers($this);
			Driver::getInstance()->cleanCacheTreeBitrixDisk(array_keys($subscribers));
		}

		return $status;
	}

	/**
	 * Deletes file and all connected data and entities (@see Sharing, @see Rights, etc).
	 * @param int $deletedBy Id of user.
	 * @return bool
	 * @throws \Bitrix\Main\ArgumentNullException
	 */
	public function delete($deletedBy)
	{
		$this->errorCollection->clear();

		if(Configuration::isEnabledObjectLock())
		{
			$objectLock = $this->getLock();
			if($objectLock && $objectLock->isExclusive() && !$objectLock->canUnlock($deletedBy))
			{
				$this->errorCollection[] = new Error(
					Loc::getMessage('DISK_FILE_MODEL_ERROR_EXCLUSIVE_LOCK'), self::ERROR_EXCLUSIVE_LOCK
				);
				return false;
			}
		}

		$rootSnapshot = (new AuditSnapshotBuilder())->buildObjectSnapshot($this);
		$this->currentState = static::STATE_DELETE_PROCESS;
		$versionFileIds = [];

		// The audience is taken before the rights and the interaction set it is read from are torn down;
		// the record itself is published once the row of the file is gone, so that a deletion that broke
		// halfway leaves the client holding a file the portal still holds too.
		$publishesTombstone = !$this->journalCascadeChild && !$this->isLink();
		$tombstoneAudience = $publishesTombstone ? $this->captureTombstoneAudience() : null;

		$deleted = $this->runAuditCoveredMutation(function (AuditTransaction $transaction) use (
			$deletedBy,
			&$versionFileIds,
			$rootSnapshot,
			$publishesTombstone,
			$tombstoneAudience,
		): bool {
			$success = EditSessionTable::deleteByFilter(array(
				'OBJECT_ID' => $this->id,
			));

			if(!$success)
			{
				return false;
			}

			$this->releaseEditSlotsOfObject($transaction);

			Document\Models\DocumentSessionTable::deleteBatch([
				'OBJECT_ID' => $this->id,
			]);

			// The rows of the interaction set are the only record of who held the file by an interaction and
			// by no right of their own. A child of a cascade publishes no tombstone the holder could be
			// addressed by: the record of the root stands for the whole subtree, and its root is a folder
			// they never saw. The rows go here together with the record they are addressed from, so that a
			// cascade that broke below leaves no record naming a holder the set still carries the file to.
			if ($this->journalCascadeChild)
			{
				(new TrackedSetCleanupPublisher())->publishHoldersLosingTheObject((int)$this->id);

				TrackedObjectTable::deleteBatch([
					'REAL_OBJECT_ID' => $this->id,
				]);
			}

			$success = ExternalLinkTable::deleteByFilter(array(
				'OBJECT_ID' => $this->id,
			));

			if(!$success)
			{
				return false;
			}

			foreach($this->getSharingsAsReal() as $sharing)
			{
				$sharing->delete($deletedBy);
			}

			//with status unreplied, declined (not approved)
			$success = SharingTable::deleteByFilter(array(
				'REAL_OBJECT_ID' => $this->id,
			));

			if(!$success)
			{
				return false;
			}

			foreach($this->getAttachedObjects() as $attached)
			{
				$attached->delete();
			}
			unset($attached);

			SimpleRightTable::deleteBatch(array('OBJECT_ID' => $this->id));

			$success = RightTable::deleteByFilter(array(
				'OBJECT_ID' => $this->id,
			));

			if(!$success)
			{
				return false;
			}

			$deletedVersionsSize = 0;
			$versionQuery = Version::getList(array('filter' => array(
				'OBJECT_ID' => $this->id,
			)));
			while($versionData = $versionQuery->fetch())
			{
				$version = Version::buildFromArray($versionData);
				$versionFileIds[] = [
					'fileId' => $version->getFileId(),
					'viewId' => $version->getViewId(),
				];
				$deletedVersionsSize += (int)$version->getSize();
			}
			unset($version, $versionQuery);

			$success = VersionTable::deleteByFilter(array(
				'OBJECT_ID' => $this->id,
			));

			if(!$success)
			{
				return false;
			}

			// The rows went by the batch and past the model, so what they took away from the disk is
			// counted here once, by the sizes read above; the transaction takes the delta back with them.
			$this->applyPersonalStorageUsageDelta(-$deletedVersionsSize);

			if($this->getLock())
			{
				$this->getLock()->delete($deletedBy);
			}

			//it's possible, that object was already deleted. And we don't have to add it to deleted log. It's unnecessary.
			//we add only directly destroyed objects.
			if(!$this->isDeleted())
			{
				Driver::getInstance()->getDeletedLogManager()->mark($this, $deletedBy);
			}

			$deleteResult = FileTable::delete($this->id);
			if(!$deleteResult->isSuccess())
			{
				return false;
			}

			if (!$this->journalCascadeChild)
			{
				// Taken here and not before the gates above: several of them still answer with false, and a
				// deletion that broke halfway would take the file from the holders who see it by their
				// interaction alone while the portal goes on holding it.
				TrackedObjectTable::deleteBatch([
					'REAL_OBJECT_ID' => $this->id,
				]);
			}

			if ($publishesTombstone)
			{
				$this->publishTombstoneToJournal($deletedBy, $tombstoneAudience);
			}

			$auditResult = (new AuditObjectEventProducer())->publishPermanentlyDeleted(
				$this,
				(int)$deletedBy,
				'object',
				$rootSnapshot,
			);
			if (!$auditResult->isSuccess())
			{
				$this->errorCollection->add($auditResult->getErrors());

				return false;
			}

			return true;
		});
		if(!$deleted)
		{
			return false;
		}

		if(Integration\BizProcManager::isAvailable())
		{
			BizProcDocument::deleteWorkflowsFile($this->id);
		}

		foreach ($versionFileIds as $versionFileId)
		{
			\CFile::delete($versionFileId['fileId']);
			if ($versionFileId['viewId'])
			{
				\CFile::delete($versionFileId['viewId']);
			}
		}

		\CFile::delete($this->fileId);
		Driver::getInstance()->getIndexManager()->dropIndex($this);

		Application::getInstance()->getTaggedCache()->clearByTag("disk_file_{$this->id}");

		if(!$this->isLink())
		{
			//todo potential - very hard operation.
			foreach(File::getModelList(array('filter' => array('REAL_OBJECT_ID' => $this->id))) as $link)
			{
				$link->delete($deletedBy);
			}
		}

		$event = new Event(Driver::INTERNAL_MODULE_ID, "onAfterDeleteFile", array($this->getId(), $deletedBy, array(
			'STORAGE_ID' => $this->getStorageId(),
		)));
		$event->send();

		return true;
	}

	/**
	 * Gives the portal back the edit slots the object still holds in the restriction log.
	 *
	 * A row of the journal is reached only through the sessions of the object, so it has to go
	 * before they do: once the session rows are gone nothing names it any more, and the slot stays
	 * taken until the TTL of the row runs out - ten minutes for VibeOffice, four hours for
	 * OnlyOffice. The portal lock of the journal is deliberately not taken: removing rows can only
	 * free a slot, so it competes with no decision about one.
	 *
	 * A cleanup that failed leaves exactly what the deletion used to leave anyway, so the file
	 * still goes; the count is then not published, for the reason
	 * {@see \Bitrix\Disk\Document\Vibeoffice\TerminalSessionFinalizer} states - a made-up number
	 * would tell the portal the limit is exhausted.
	 */
	protected function releaseEditSlotsOfObject(AuditTransaction $transaction): void
	{
		$restrictionManager = new Document\OnlyOffice\RestrictionManager();
		$released = $restrictionManager->deleteUsageByObject((int)$this->id);
		if (!$released->isSuccess())
		{
			return;
		}

		$data = $released->getData();
		$availableCount = $data['availableCount'] ?? null;
		if ((int)($data['deletedCount'] ?? 0) <= 0 || $availableCount === null)
		{
			return;
		}

		$transaction->addAfterCommit(
			static function () use ($restrictionManager, $availableCount): void {
				$restrictionManager->notifyRestrictionLogChanged((int)$availableCount);
			},
		);
	}

	/**
	 * Returns current state of the file.
	 *
	 * For example, STATE_DELETE_PROCESS.
	 *
	 * @return int
	 * @internal
	 */
	public function getCurrentState()
	{
		return $this->currentState;
	}

	/**
	 * Returns object preview model.
	 *
	 * @return Preview|null
	 */
	public function getPreview(): ?Preview
	{
		if ($this->isLoadedAttribute('preview'))
		{
			return $this->preview ?? null;
		}

		$this->preview = Preview::load(['FILE_ID' => $this->getFileId()]);
		$this->setAsLoadedAttribute('preview');

		return $this->preview;
	}

	protected function getHistoricalData()
	{
		return array(
			'FILE_ID' => $this->fileId,
			'SIZE' => $this->size,

			'GLOBAL_CONTENT_VERSION' => $this->globalContentVersion,

			'OBJECT_CREATED_BY' => $this->createdBy,
			'OBJECT_UPDATED_BY' => $this->updatedBy,

			'OBJECT_CREATE_TIME'=> $this->createTime,
			'OBJECT_UPDATE_TIME'=> $this->updateTime,
		);
	}

	/**
	 * Returns all attached objects by the file.
	 * @param array $parameters Parameters.
	 * @return AttachedObject[]
	 */
	public function getAttachedObjects(array $parameters = array())
	{
		if(!isset($parameters['filter']))
		{
			$parameters['filter'] = array();
		}
		$parameters['filter']['=OBJECT_ID'] = $this->id;
		$parameters['filter']['=VERSION_ID'] = null;

		return AttachedObject::getModelList($parameters);
	}

	/**
	 * Returns count of attached objects of file.
	 * @return int
	 */
	public function countAttachedObjects()
	{
		$countQuery = new Query(AttachedObjectTable::getEntity());
		$countQuery
			->addSelect(new ExpressionField('CNT', 'COUNT(1)'))
			->setFilter(array(
				'=OBJECT_ID' => $this->id,
				'=VERSION_ID' => null,
			))
		;

		$totalData = $countQuery->setLimit(null)->setOffset(null)->exec()->fetch();

		return $totalData['CNT'];
	}

	public function hasAttachedObjects(): bool
	{
		$query = new Query(AttachedObjectTable::getEntity());
		$query
			->addSelect('ID')
			->setFilter([
				'=OBJECT_ID' => $this->id,
			])
			->setLimit(1)
		;

		$data = $query->exec()->fetch();

		return !empty($data['ID']);
	}

	/**
	 * @param array{id: int, type: string} $entity
	 * @param array{
	 *     allowEdit: bool,
	 *     isEditable: bool,
	 *     createdBy: int,
	 *     ownerParameters?: array<string, int|string|bool>|null,
	 * } $options ownerParameters describe the owner for its connector and cannot be changed after creation;
	 *     invalid ones leave the file unattached with the error AttachedObject::ERROR_OWNER_PARAMETERS_INVALID.
	 * @return AttachedObject|null
	 * @throws Main\NotImplementedException
	 */
	final public function attachToEntity(array $entity, array $options): ?AttachedObject
	{
		$userFieldManager = Driver::getInstance()->getUserFieldManager();
		[$connectorClass, $moduleId] = $userFieldManager->getConnectorDataByEntityType($entity['type']);

		$errorCollection = new ErrorCollection();
		$attachedObject = Disk\AttachedObject::add([
			'MODULE_ID' => $moduleId,
			'OBJECT_ID' => $this->getId(),
			'ENTITY_ID' => $entity['id'],
			'ENTITY_TYPE' => $connectorClass,
			'IS_EDITABLE' => (int)$options['isEditable'],
			'ALLOW_EDIT' => (int)$options['allowEdit'],
			'CREATED_BY' => $options['createdBy'],
			'OWNER_PARAMETERS' => $options['ownerParameters'] ?? null,
		], $errorCollection, $this->getUploadCleanupMarkerPresenceHint());

		if (!$attachedObject)
		{
			$this->errorCollection->add($errorCollection->getValues());
		}

		return $attachedObject;
	}

	protected function updateLinksAttributes(array $attr)
	{
		$possibleToUpdate = array(
			'GLOBAL_CONTENT_VERSION' => 'globalContentVersion',
			'TYPE_FILE' => 'typeFile',
			'SIZE' => 'size',
			'EXTERNAL_HASH' => 'externalHash',
			'ETAG' => 'etag',
			'UPDATE_TIME' => 'updateTime',
			'SYNC_UPDATE_TIME' => 'syncUpdateTime',
			'UPDATED_BY' => 'updatedBy',
			'UPDATE_USER' => 'updateUser',
		);
		$attr = array_intersect_key($attr, $possibleToUpdate);
		if($attr)
		{
			parent::updateLinksAttributes($attr);
		}
	}

	private function updateRelated($updatedBy)
	{
		Disk\Driver::getInstance()->getTrackedObjectManager()->refresh($this, $updatedBy);
	}

	/**
	 * Returns the list of pair for mapping.
	 * Key is field in DataManager, value is object property.
	 * @return array
	 */
	public static function getMapAttributes()
	{
		static $shelve = null;
		if($shelve !== null)
		{
			return $shelve;
		}

		$shelve = array_merge(parent::getMapAttributes(), array(
			'TYPE_FILE' => 'typeFile',
			'GLOBAL_CONTENT_VERSION' => 'globalContentVersion',
			'FILE_ID' => 'fileId',
			'SIZE' => 'size',
			'EXTERNAL_HASH' => 'externalHash',
			'PREVIEW_ID' => 'previewId',
			'VIEW_ID' => 'viewId',
			'ETAG' => 'etag',
			'PREVIEW' => 'preview',
		));

		return $shelve;
	}

	/**
	 * Returns the list attributes which is connected with another models.
	 * @return array
	 */
	public static function getMapReferenceAttributes(): array
	{
		return array_merge(parent::getMapReferenceAttributes(), [
			'PREVIEW' => Preview::class,
		]);
	}

	/**
	 * Return instance of View for current file.
	 *
	 * @return View\Base
	 */
	public function getView()
	{
		if (!$this->view)
		{
			$previewData = $this->getPreview();
			$viewId = $previewData?->getPreviewId();
			$imageId = $previewData?->getPreviewImageId();

			if (TypeFile::isDocument($this))
			{
				$this->view = new View\Document($this->getName(), $this->getFileId(), $viewId, $imageId);
			}
			elseif (TypeFile::isVideo($this))
			{
				$this->view = new View\Video($this->getName(), $this->getFileId(), $viewId, $imageId);
			}
			else
			{
				$this->view = new View\Base($this->getName(), $this->getFileId(), $viewId, $imageId);
			}
		}

		return $this->view;
	}

	/**
	 * Specify data which should be serialized to JSON
	 * @link http://php.net/manual/en/jsonserializable.jsonserialize.php
	 * @return mixed data which can be serialized by <b>json_encode</b>,
	 * which is a value of any type other than a resource.
	 * @since 5.4.0
	 */
	public function jsonSerialize(): array
	{
		$urlShowObjectInGrid = Driver::getInstance()->getUrlManager()->getUrlFocusController('showObjectInGrid', [
			'objectId' => $this->getId(),
		]);
		$urlShowObjectInGrid = new Main\Web\Uri($urlShowObjectInGrid);

		$downloadUri = Main\Engine\UrlManager::getInstance()->create('disk.file.download', ['fileId' => $this->getId()]);
		$uls = null;

		if ($this->supportsUnifiedLink())
		{
			$uls = $this->unifiedLinkAccessService->getUlsForObjectId($this->getId());

			$this->unifiedLinkUrl->addUlsToUri($downloadUri, $uls);
		}

		$links = [
			/** @see \Bitrix\Disk\Controller\File::downloadAction() */
			'download' => $downloadUri,
			'showInGrid' => $urlShowObjectInGrid,
			'preview' => $this->getPreviewLink($uls),
		];

		return array_merge(parent::jsonSerialize(), [
			'typeFile' => (int)$this->getTypeFile(),
			'globalContentVersion' => (int)$this->getGlobalContentVersion(),
			'fileId' => (int)$this->getFileId(),
			'size' => (int)$this->getSize(),
			'etag' => $this->getEtag(),
			'links' => array_filter($links),
		]);
	}

	private function getPreviewLink(?string $uls): ?string
	{
		$urlManager = Main\Engine\UrlManager::getInstance();

		if ($this->getView()->getPreviewData())
		{
			$controllerAction = 'disk.api.file.showPreview';
		}
		elseif (TypeFile::isImage($this))
		{
			$controllerAction = 'disk.api.file.showImage';
		}
		else
		{
			return null;
		}

		$previewUri = $urlManager->create($controllerAction, [
			'humanRE' => 1,
			'width' => 640,
			'height' => 640,
			'signature' => ParameterSigner::getImageSignature($this->getId(), 640, 640),
			'fileId' => $this->getId(),
		]);

		if (is_string($uls) && $uls !== '')
		{
			$this->unifiedLinkUrl->addUlsToUri($previewUri, $uls);
		}

		return (string)$previewUri;
	}

	public function supportsUnifiedLink(): bool
	{
		$realObject = $this->getRealObject();

		if ($realObject === null)
		{
			return false;
		}

		$unifiedLinkSupportService = Main\DI\ServiceLocator::getInstance()->get(UnifiedLinkSupportService::class);

		return $unifiedLinkSupportService->supports($realObject);
	}

	public function isNewByCreateTime(): bool
	{
		return time() - $this->getCreateTime()->getTimestamp() < 30;
	}
}
