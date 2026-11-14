<?php

namespace Bitrix\Disk;

use Bitrix\Disk\Internals\Error\Error;
use Bitrix\Disk\Internals\Error\ErrorCollection;
use Bitrix\Disk\Internals\FileHelper;
use Bitrix\Disk\Internals\FileTable;
use Bitrix\Disk\Internals\FolderTable;
use Bitrix\Disk\Internals\ObjectNameKey;
use Bitrix\Disk\Internals\ObjectNameService;
use Bitrix\Disk\Internals\ObjectPathTable;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Disk\Internals\RightTable;
use Bitrix\Disk\Internals\SharingTable;
use Bitrix\Disk\Internals\SimpleRightTable;
use Bitrix\Disk\Internals\UniqueCode;
use Bitrix\Disk\Internals\VersionTable;
use Bitrix\Disk\Internal\Enum\WriteOperationContext;
use Bitrix\Disk\Internal\Service\Audit\AuditObjectEventProducer;
use Bitrix\Disk\Internal\Service\Audit\AuditOperationContext;
use Bitrix\Disk\Internal\Service\Audit\AuditOperationContextStack;
use Bitrix\Disk\Internal\Service\Audit\AuditSnapshotBuilder;
use Bitrix\Disk\Internal\Service\Audit\AuditTransaction;
use Bitrix\Disk\Internal\Service\Audit\AuditTransactionException;
use Bitrix\Disk\Internal\Service\Audit\AuditTransactionOutcome;
use Bitrix\Disk\Internal\Service\Audit\AuditTransactionResult;
use Bitrix\Disk\Internal\Service\Folder\DeletionRestriction;
use Bitrix\Disk\Internal\Service\Quota\StorageUsageCounter;
use Bitrix\Disk\Internal\Service\Restrictions\ContentHeadReader;
use Bitrix\Disk\Internal\Service\Restrictions\FormatPolicyService;
use Bitrix\Disk\Internal\Service\Sync\Access\PlacementAccessPublisher;
use Bitrix\Disk\Internal\Service\Sync\Journal\ChangeRecord;
use Bitrix\Disk\Internal\Service\Sync\Journal\ChangeType;
use Bitrix\Disk\Internal\Service\Sync\Journal\DescendantTombstonePublisher;
use Bitrix\Disk\Internal\Service\Sync\Journal\TombstoneReason;
use Bitrix\Disk\ProxyType\Group;
use Bitrix\Disk\Security\SecurityContext;
use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\DB\DuplicateEntryException;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Entity\ExpressionField;
use Bitrix\Main\Entity\Query;
use Bitrix\Main\Event;
use Bitrix\Main\InvalidOperationException;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ObjectException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\ORM\Query\Query as OrmQuery;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\Collection;
use Bitrix\Main\Type\DateTime;
use CFile;

Loc::loadMessages(__FILE__);

class Folder extends BaseObject
{
	const ERROR_COULD_NOT_DELETE_WITH_CODE = 'DISK_FOLDER_22001';
	const ERROR_COULD_NOT_SAVE_FILE        = 'DISK_FOLDER_22002';

	const CODE_FOR_CREATED_FILES  = SpecificFolder::CODE_FOR_CREATED_FILES;
	const CODE_FOR_SAVED_FILES    = SpecificFolder::CODE_FOR_SAVED_FILES;
	const CODE_FOR_UPLOADED_FILES = SpecificFolder::CODE_FOR_UPLOADED_FILES;

	/** Operations of \CSQLWhere holding a field to a value or a set of them: "FIELD", "=FIELD", "==FIELD", "@FIELD". */
	private const NARROWING_OPERATIONS = ['E', 'I', 'SE', 'IN'];
	// files whose content is judged by one query of their rows and one of their rows of b_file
	private const CONTENT_CHECK_CHUNK_SIZE = 500;

	/** @var bool */
	protected $hasSubFolders;
	// what the check of a move has judged, carried to the copy step of the same move
	private array $signatureCheckOptionsOfMove = [];
	private static ?DeletionRestriction $deletionRestriction = null;
	private ?DescendantTombstonePublisher $descendantTombstonePublisher = null;

	/**
	 * Gets the fully qualified name of table class which belongs to current model.
	 * @throws \Bitrix\Main\NotImplementedException
	 * @return string
	 */
	public static function getTableClassName()
	{
		return FolderTable::className();
	}

	/**
	 * Tells if the folder has sub-folders.
	 * Property {hasSubFolders} fills by using field HAS_SUBFOLDERS in select.
	 * @return boolean
	 */
	public function hasSubFolders()
	{
		return (bool)$this->hasSubFolders;
	}

	/**
	 * Checks rights to add object to current object.
	 * @param SecurityContext $securityContext Security context.
	 * @return bool
	 */
	public function canAdd(SecurityContext $securityContext)
	{
		return $securityContext->canAdd($this->id);
	}

	/**
	 * Checks rights to delete current folder.
	 * @param SecurityContext $securityContext Security context.
	 * @return bool
	 */
	public function canDelete(SecurityContext $securityContext)
	{
		if ($this->isDeletionRestricted($this->normalizeDeletedBy($securityContext->getUserId())))
		{
			return false;
		}

		return parent::canDelete($securityContext);
	}

	/**
	 * Checks rights to mark deleted current folder.
	 * @param SecurityContext $securityContext Security context.
	 * @return bool
	 */
	public function canMarkDeleted(SecurityContext $securityContext)
	{
		if ($this->isDeletionRestricted($this->normalizeDeletedBy($securityContext->getUserId())))
		{
			return false;
		}

		return parent::canMarkDeleted($securityContext);
	}

	/**
	 * Pre-loads all operations for children.
	 * @internal
	 * @param SecurityContext $securityContext Security context.
	 */
	public function preloadOperationsForChildren(SecurityContext $securityContext)
	{
		$securityContext->preloadOperationsForChildren($this->id);
	}

	public function preloadOperationsForSpecifiedObjects(
		array $ids,
		SecurityContext $securityContext,
		bool $shouldPreloadDownloadAccess = false,
	)
	{
		if (!$ids)
		{
			return;
		}

		$securityContext->preloadOperationsForSpecifiedObjects(
			$this->id,
			$ids,
			$shouldPreloadDownloadAccess,
		);
	}

	/**
	 * Adds row to entity table, fills error collection and builds model.
	 * @param array           $data Data.
	 * @param ErrorCollection $errorCollection Error collection.
	 * @return \Bitrix\Disk\Internals\Model|static|null
	 * @throws \Bitrix\Main\NotImplementedException
	 * @internal
	 */
	public static function add(
		array $data,
		ErrorCollection $errorCollection,
		?AuditTransaction $auditTransaction = null,
	)
	{
		if (!isset($data['UNIQUE_CODE']))
		{
			$data['UNIQUE_CODE'] = (new UniqueCode())->generateForNewFile();
		}

		/** @var Folder $folder */
		$folder = parent::add($data, $errorCollection);
		if($folder && !$folder->isDeleted())
		{
			$afterCommit = static function () use ($folder): void {
				$driver = Driver::getInstance();
				$driver->getIndexManager()->indexFolder($folder);
				$driver->sendChangeStatusToSubscribers($folder);
			};

			if ($auditTransaction !== null)
			{
				$auditTransaction->addAfterCommit($afterCommit);
			}
			else
			{
				$afterCommit();
			}
		}

		return $folder;
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
		$filter['TYPE'] = ObjectTable::TYPE_FOLDER;

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
	 * Tells if folder is root. It means folder does not have parent folder.
	 *
	 * @return bool
	 */
	public function isRoot()
	{
		return !$this->parentId;
	}

	/**
	 * Uploads new file to folder.
	 * @param array $fileArray Structure like $_FILES.
	 * @param array $data Contains additional fields (CREATED_BY, NAME, etc).
	 * @param array $rights Rights (@see \Bitrix\Disk\RightsManager).
	 * @param bool  $generateUniqueName Generates unique name for object in directory.
	 * @param array $options Options of the write operation (@see \Bitrix\Disk\File::addVersion()).
	 * @throws \Bitrix\Main\ArgumentException
	 * @return File|null
	 */
	public function uploadFile(array $fileArray, array $data, array $rights = array(), $generateUniqueName = false, array $options = [])
	{
		$this->errorCollection->clear();

		$data['NAME'] = $this->resolveFileName($fileArray, $data);

		static::checkRequiredInputParams($data, array(
			'NAME', 'CREATED_BY'
		));

		if(!isset($fileArray['MODULE_ID']))
		{
			$fileArray['MODULE_ID'] = Driver::INTERNAL_MODULE_ID;
		}

		if(empty($fileArray['type']))
		{
			$fileArray['type'] = '';
		}

		$fileArray['type'] = TypeFile::normalizeMimeType($fileArray['type'], $data['NAME']);
		$fileArray['name'] = $data['NAME'];

		if (!FileHelper::hasValidFileKeys($fileArray))
		{
			$this->errorCollection->add([new Error(Loc::getMessage('DISK_FOLDER_MODEL_ERROR_COULD_NOT_SAVE_FILE'), self::ERROR_COULD_NOT_SAVE_FILE)]);
			return null;
		}

		$formatPolicyError = File::findFormatPolicyErrorForUpload(
			$fileArray,
			(string)$data['NAME'],
			(int)$data['CREATED_BY'],
			$options,
		);
		if ($formatPolicyError)
		{
			$this->errorCollection->add([$formatPolicyError]);

			return null;
		}

		$personalQuotaError = File::findPersonalQuotaErrorForUpload($fileArray, $this->getStorage(), $options);
		if ($personalQuotaError)
		{
			$this->errorCollection->add([$personalQuotaError]);

			return null;
		}

		$fileId = CFile::saveFile($fileArray, Driver::INTERNAL_MODULE_ID, true, true);
		if(!$fileId)
		{
			$this->errorCollection->add(array(new Error(Loc::getMessage("DISK_FOLDER_MODEL_ERROR_COULD_NOT_SAVE_FILE"), self::ERROR_COULD_NOT_SAVE_FILE)));
			return null;
		}
		/** @var array $fileArray */

		$fileArray = CFile::getFileArray($fileId);

		$data['NAME'] = Ui\Text::correctFilename($data['NAME']);
		$fileModel = $this->addFile(array(
			'NAME' => $data['NAME'],
			'FILE_ID' => $fileId,
			'PREVIEW_ID' => isset($data['PREVIEW_ID'])? $data['PREVIEW_ID'] : null,
			'CONTENT_PROVIDER' => isset($data['CONTENT_PROVIDER'])? $data['CONTENT_PROVIDER'] : null,
			'SIZE' => !isset($data['SIZE'])? $fileArray['FILE_SIZE'] : $data['SIZE'],
			'CREATED_BY' => $data['CREATED_BY'],
			'UPDATE_TIME' => isset($data['UPDATE_TIME'])? $data['UPDATE_TIME'] : null,
			'CODE' => isset($data['CODE'])? $data['CODE'] : null,
			File::FILE_OWNERSHIP_DATA_KEY => File::FILE_OWNERSHIP_OWNED,
		), $rights, $generateUniqueName, $options);

		if(!$fileModel)
		{
			return null;
		}
		return $fileModel;
	}

	private function resolveFileName(array $fileArray, array $data)
	{
		if (!empty($data['NAME']))
		{
			return $data['NAME'];
		}

		if (!empty($fileArray['name']))
		{
			return $fileArray['name'];
		}

		return null;
	}

	/**
	 * Creates blank file (size 0 byte) in folder.
	 * @param array $data Contains additional fields (CREATED_BY, NAME, MIME_TYPE).
	 * @param array $rights Rights (@see \Bitrix\Disk\RightsManager).
	 * @param bool  $generateUniqueName Generates unique name for object in directory.
	 * @param array $options Options of the write operation (@see \Bitrix\Disk\File::addVersion()).
	 * @return File|null
	 * @throws \Bitrix\Main\ArgumentException
	 */
	public function addBlankFile(array $data, array $rights = array(), $generateUniqueName = false, array $options = [])
	{
		$this->errorCollection->clear();

		static::checkRequiredInputParams($data, array(
			'NAME', 'CREATED_BY', 'MIME_TYPE'
		));

		return $this->uploadFile(array(
			'name' => $data['NAME'],
			'content' => '',
			'type' => $data['MIME_TYPE'],
		), array(
			'NAME' => $data['NAME'],
			'SIZE' => isset($data['SIZE'])? $data['SIZE'] : null, //for im. We should show future!
			'CREATED_BY' => $data['CREATED_BY'],
		), $rights, $generateUniqueName, $options);
	}

	/**
	 * Adds file in folder.
	 * @param array $data Contains additional fields (CREATED_BY, NAME, etc).
	 * @param array $rights Rights (@see \Bitrix\Disk\RightsManager).
	 * @param bool  $generateUniqueName Generates unique name for object in directory.
	 * @param array $options Options of the write operation (@see \Bitrix\Disk\File::addVersion()).
	 * @throws \Bitrix\Main\ArgumentException
	 * @return null|static|File
	 */
	public function addFile(
		array $data,
		array $rights = [],
		$generateUniqueName = false,
		array $options = [],
		?AuditTransaction $auditTransaction = null,
	)
	{
		$this->errorCollection->clear();

		if ($auditTransaction !== null)
		{
			return $auditTransaction
				->join(function (AuditTransaction $transaction) use ($data, $rights, $generateUniqueName, $options) {
					$file = $this->addFileInsideTransaction(
						$data,
						$rights,
						(bool)$generateUniqueName,
						$options,
						$transaction,
					);

					return $file instanceof File
						? AuditTransactionOutcome::success($file)
						: AuditTransactionOutcome::failure();
				})
				->getValue()
			;
		}

		if (AuditTransaction::isActive())
		{
			return AuditTransaction::run(
				fn() => $this->addFileInsideTransaction($data, $rights, (bool)$generateUniqueName, $options),
			);
		}

		$transaction = new AuditTransaction(Application::getConnection());
		$result = $transaction->execute(
			function (AuditTransaction $transaction) use ($data, $rights, $generateUniqueName, $options) {
				$file = $this->addFileInsideTransaction(
					$data,
					$rights,
					(bool)$generateUniqueName,
					$options,
					$transaction,
				);

				return $file instanceof File
					? AuditTransactionOutcome::success($file)
					: AuditTransactionOutcome::failure();
			},
		);

		return $this->unwrapCreateTransactionResult($result);
	}

	private function addFileInsideTransaction(
		array $data,
		array $rights,
		bool $generateUniqueName,
		array $options = [],
		?AuditTransaction $auditTransaction = null,
	): ?File
	{
		$fileOwnership = $data[File::FILE_OWNERSHIP_DATA_KEY] ?? File::FILE_OWNERSHIP_BORROWED;
		unset($data[File::FILE_OWNERSHIP_DATA_KEY]);

		if ($auditTransaction !== null && $fileOwnership === File::FILE_OWNERSHIP_OWNED)
		{
			$fileId = (int)($data['FILE_ID'] ?? 0);
			if ($fileId > 0)
			{
				$auditTransaction->addCompensation(static function () use ($fileId): void {
					CFile::delete($fileId);
				});
			}
		}

		static::checkRequiredInputParams($data, ['NAME']);

		$nameService = new ObjectNameService($data['NAME'], $this->id, ObjectTable::TYPE_FILE);
		if ($generateUniqueName)
		{
			$nameService->requireUniqueName();
		}

		$result = $nameService->prepareName();
		if (!$result->isSuccess())
		{
			$this->errorCollection->add($result->getErrors());

			return null;
		}

		$data['NAME'] = $result->getName();
		$data['PARENT_ID'] = $this->id;
		$data['STORAGE_ID'] = $this->storageId;
		$data['PARENT'] = $this;
		$data[File::DEFER_CREATED_AUDIT_DATA_KEY] = true;

		/** @var File $fileModel */
		$fileModel = $this->processAdd(
			$data,
			$this->errorCollection,
			ObjectTable::TYPE_FILE,
			$generateUniqueName,
			$options,
			auditTransaction: $auditTransaction,
		);
		if (!$fileModel)
		{
			return null;
		}

		$rightsManager = Driver::getInstance()->getRightsManager();
		if (!$rightsManager->setAsNewLeaf($fileModel, $rights, $auditTransaction))
		{
			$this->errorCollection->add($rightsManager->getErrors());

			return null;
		}

		// after the rights of the new object are written: they are what its audience is read from
		$fileModel->publishToJournal(ChangeType::Created, $fileModel->getCreatedBy());

		$auditResult = (new AuditObjectEventProducer())->publishCreated(
			$fileModel,
			(int)$fileModel->getCreatedBy(),
		);
		if (!$fileModel->applyAuditResult($auditResult))
		{
			$this->errorCollection->add($fileModel->getErrors());

			return null;
		}

		$afterCommit = function () use ($fileModel): void {
			$objectEvent = $this->makeObjectEvent(
				'objectAdded',
				[
					'object' => [
						'id' => (int)$this->getId(),
						'type' => FileTable::TYPE,
						'name' => $this->getName(),
					],
					'addedObject' => [
						'id' => (int)$fileModel->getId(),
					],
				]
			);
			$objectEvent->sendToObjectChannel();
		};

		if ($auditTransaction !== null)
		{
			$auditTransaction->addAfterCommit($afterCommit);
		}
		else
		{
			$afterCommit();
		}

		return $fileModel;
	}

	private function processAdd(
		array $data,
		ErrorCollection $errorCollection,
		int $type,
		bool $generateUniqueName = false,
		array $options = [],
		int $countStepsToGenerateName = 0,
		?AuditTransaction $auditTransaction = null,
	): BaseObject|null
	{
		$connection = Application::getConnection();
		// a duplicate swallowed here (by the retry, or by the domain error of addSubFolder()) leaves an outer
		// pgsql transaction aborted, so such an attempt runs in a transaction of its own
		$swallowsDuplicateEntry = $generateUniqueName || $type === ObjectTable::TYPE_FOLDER;
		$shouldUseTransaction = $swallowsDuplicateEntry && $connection->getType() === 'pgsql';
		if ($shouldUseTransaction)
		{
			$connection->startTransaction();
		}

		try
		{
			$objectModel = match ($type)
			{
				ObjectTable::TYPE_FOLDER => Folder::add($data, $errorCollection, $auditTransaction),
				ObjectTable::TYPE_FILE => $this->addFileModel($data, $errorCollection, $options, $auditTransaction),
				default => throw new ArgumentException("Unknown object type {$type}"),
			};
		}
		catch (DuplicateEntryException $exception)
		{
			if ($shouldUseTransaction)
			{
				$connection->rollbackTransaction();
			}

			if ($generateUniqueName)
			{
				$countStepsToGenerateName++;
				if ($countStepsToGenerateName > 10)
				{
					throw new InvalidOperationException(
						"Too many attempts ({$countStepsToGenerateName}) to generate unique name {$data['NAME']}"
					);
				}

				$nameService = new ObjectNameService($data['NAME'], $this->id, $type);
				$nameService->requireUniqueName();

				$result = $nameService->prepareName();
				if ($result->isSuccess())
				{
					$data['NAME'] = $result->getName();
				}

				// The name is new, the content is not, and the attempt that collided has left in
				// $options whether it got as far as judging that content. Only what was judged there
				// is taken for judged here: a content the node never reached is judged on this attempt.
				return $this->processAdd(
					$data,
					$errorCollection,
					$type,
					$generateUniqueName,
					$options,
					$countStepsToGenerateName,
					$auditTransaction,
				);
			}

			throw $exception;
		}
		catch (\Throwable $exception)
		{
			if ($shouldUseTransaction)
			{
				$connection->rollbackTransaction();
			}

			throw $exception;
		}

		if ($shouldUseTransaction)
		{
			$connection->commitTransaction();
		}

		if (!$objectModel)
		{
			return null;
		}

		return $objectModel;
	}

	private function createNonUniqueNameError(): Error
	{
		return new Error(Loc::getMessage('DISK_FOLDER_MODEL_ERROR_NON_UNIQUE_NAME'), self::ERROR_NON_UNIQUE_NAME);
	}

	private function unwrapCreateTransactionResult(AuditTransactionResult $result): mixed
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
	 * The write node of the file, entered from this one place: the whole retry of the unique name
	 * turns on this call refusing the name the database already holds.
	 *
	 * @param array $data Fields of the file being written.
	 * @param ErrorCollection $errorCollection Errors of the write.
	 * @param array $options Options of the write operation (@see \Bitrix\Disk\File::addVersion()).
	 *                       The node marks them with what it has judged of this write.
	 * @return File|null
	 */
	protected function addFileModel(
		array $data,
		ErrorCollection $errorCollection,
		array &$options,
		?AuditTransaction $auditTransaction = null,
	): ?File
	{
		return File::add($data, $errorCollection, $options, $auditTransaction);
	}

	/**
	 * Adds link on file in folder.
	 * @param File  $sourceFile Source file.
	 * @param array $data Contains additional fields (CREATED_BY, NAME, etc).
	 * @param array $rights Rights (@see \Bitrix\Disk\RightsManager).
	 * @param bool  $generateUniqueName Generates unique name for object in directory.
	 * @throws \Bitrix\Main\ArgumentException
	 * @return File|null
	 */
	public function addFileLink(File $sourceFile, array $data, array $rights = array(), $generateUniqueName = false)
	{
		$this->errorCollection->clear();

		$data = $this->prepareDataForAddLink($sourceFile, $data, $generateUniqueName);
		if(!$data)
		{
			return null;
		}
		$data['SIZE'] = $sourceFile->getSize();

		/** @var FileLink $fileLinkModel */
		$fileLinkModel = FileLink::add(
			$data,
			$this->errorCollection,
			hasUploadCleanupMarker: $sourceFile->getRealObject()->getUploadCleanupMarkerPresenceHint(),
		);
		if(!$fileLinkModel)
		{
			return null;
		}

		$driver = Driver::getInstance();
		$driver->getRightsManager()->setAsNewLeaf($fileLinkModel, $rights);
		// a link is a place the real object is seen at, and the journal is kept over real objects
		$fileLinkModel->publishToJournal(
			ChangeType::Created,
			$fileLinkModel->getCreatedBy(),
			['places'],
		);

		if (!$fileLinkModel->isDeleted())
		{
			$driver->getIndexManager()->indexFile($fileLinkModel);

//			$this->notifySonetGroup($fileLinkModel);
		}

		$objectEvent = $this->makeObjectEvent(
			'objectAdded',
			[
				'object' => [
					'id' => (int)$this->getId(),
					'type' => FileTable::TYPE,
					'name' => $this->getName(),
				],
				'addedObject' => [
					'id' => (int)$fileLinkModel->getId(),
				],
			]
		);
		$objectEvent->sendToObjectChannel();


		return $fileLinkModel;
	}

	private function notifySonetGroup(File $fileModel)
	{
		static 	$folderUrlList = array();

		//todo create NotifyManager, which provides notify (not only group)
		if(!Loader::includeModule('socialnetwork'))
		{
			return;
		}

		$storage = $fileModel->getStorage();
		$proxyType = $storage->getProxyType();
		if(!$proxyType instanceof Group)
		{
			return;
		}

		$groupId = (int)$storage->getEntityId();
		if($groupId <= 0)
		{
			return;
		}

		$urlManager = Driver::getInstance()->getUrlManager();
		$fileUrl = $urlManager->getPathFileDetail($fileModel);
		$fileCreatedBy = $fileModel->getCreatedBy();
		$fileName = $fileModel->getName();
		$storageId = $storage->getId();

		$res = ObjectTable::getList(array(
			'filter' => array(
				'STORAGE_ID' => $storageId,
				'CREATED_BY' => $fileCreatedBy,
				'>UPDATE_TIME' => DateTime::createFromTimestamp(time() - 600), // for index
				'>CREATE_TIME' => DateTime::createFromTimestamp(time() - 600),
				'=TYPE' => ObjectTable::TYPE_FILE,
				'!=FILE_ID' => $fileModel->getFileId()
			),
			'select' => array('ID'),
			'limit' => 1
		));

		if (!($res->fetch()))
		{
			$message = Loc::getMessage('DISK_FOLDER_MODEL_IM_NEW_FILE', array(
				'#TITLE#' => '<a href="#URL#" class="bx-notifier-item-action">'.$fileName.'</a>',
			));
			$messageOut = Loc::getMessage('DISK_FOLDER_MODEL_IM_NEW_FILE', array(
					'#TITLE#' => $fileName
				)).' (#URL#)';

			$url = $urlManager->encodeUrn($fileUrl);
		}
		else
		{
			$message = Loc::getMessage('DISK_FOLDER_MODEL_IM_NEW_FILE2', array(
				'#LINK_FOLDER_START#' => '<a href="#URL#" class="bx-notifier-item-action">',
				'#LINK_END#' => '</a>'
			));
			$messageOut = Loc::getMessage('DISK_FOLDER_MODEL_IM_NEW_FILE2', array(
					'#LINK_FOLDER_START#' => '',
					'#LINK_END#' => ''
				)).' (#URL#)';

			if (!array_key_exists($storageId, $folderUrlList))
			{
				$folderUrlList[$storageId] = $proxyType->getBaseUrlFolderList();
			}

			$url = $folderUrlList[$storageId];
		}


		\CSocNetSubscription::notifyGroup(array(
			'LOG_ID' => false,
			'GROUP_ID' => array($groupId),
			'NOTIFY_MESSAGE' => '',
			'NOTIFY_TAG' => 'DISK_GROUP|'.$groupId.'|'.$fileCreatedBy,
			'FROM_USER_ID' => $fileCreatedBy,
			'URL' => $url,
			'MESSAGE' => $message,
			'MESSAGE_OUT' => $messageOut,
			'EXCLUDE_USERS' => array($fileCreatedBy)
		));
	}

	/**
	 * Adds sub-folder in folder.
	 * @param array $data Contains additional fields (CREATED_BY, NAME, etc).
	 * @param array $rights Rights (@see \Bitrix\Disk\RightsManager).
	 * @param bool  $generateUniqueName Generates unique name for object in directory.
	 * @throws \Bitrix\Main\ArgumentException
	 * @return null|static|Folder
	 */
	public function addSubFolder(
		array $data,
		array $rights = [],
		bool $generateUniqueName = false,
		?AuditTransaction $auditTransaction = null,
	)
	{
		$this->errorCollection->clear();

		static::checkRequiredInputParams($data, [
			'NAME',
		]);

		$nameService = new ObjectNameService($data['NAME'], $this->id, ObjectTable::TYPE_FOLDER);
		if ($generateUniqueName)
		{
			$nameService->requireUniqueName();
		}

		$result = $nameService->prepareName();
		if (!$result->isSuccess())
		{
			$this->errorCollection->add($result->getErrors());

			return null;
		}

		$data['NAME'] = $result->getName();
		$data['PARENT_ID'] = $this->id;
		$data['STORAGE_ID'] = $this->storageId;

		try
		{
			if ($auditTransaction !== null)
			{
				return $auditTransaction
					->join(
						fn(AuditTransaction $transaction) => $this->buildSubFolderCreateOutcome(
							$data,
							$rights,
							$generateUniqueName,
							$transaction,
						),
					)
					->getValue()
				;
			}

			if (AuditTransaction::isActive())
			{
				return AuditTransaction::run(
					fn() => $this->addSubFolderInsideTransaction($data, $rights, $generateUniqueName),
				);
			}

			$transaction = new AuditTransaction(Application::getConnection());
			$result = $transaction->execute(
				fn(AuditTransaction $transaction) => $this->buildSubFolderCreateOutcome(
					$data,
					$rights,
					$generateUniqueName,
					$transaction,
				),
			);

			return $this->unwrapCreateTransactionResult($result);
		}
		catch (DuplicateEntryException)
		{
			$this->errorCollection[] = $this->createNonUniqueNameError();

			return null;
		}
	}

	private function buildSubFolderCreateOutcome(
		array $data,
		array $rights,
		bool $generateUniqueName,
		AuditTransaction $auditTransaction,
	): AuditTransactionOutcome
	{
		try
		{
			$folder = $this->addSubFolderInsideTransaction(
				$data,
				$rights,
				$generateUniqueName,
				$auditTransaction,
			);
		}
		catch (DuplicateEntryException)
		{
			$this->errorCollection[] = $this->createNonUniqueNameError();

			return AuditTransactionOutcome::success();
		}

		return $folder instanceof Folder
			? AuditTransactionOutcome::success($folder)
			: AuditTransactionOutcome::failure();
	}

	private function addSubFolderInsideTransaction(
		array $data,
		array $rights,
		bool $generateUniqueName,
		?AuditTransaction $auditTransaction = null,
	): ?Folder
	{
		/** @var Folder|null $folderModel */
		$folderModel = $this->processAdd(
			$data,
			$this->errorCollection,
			ObjectTable::TYPE_FOLDER,
			$generateUniqueName,
			auditTransaction: $auditTransaction,
		);
		if (!$folderModel)
		{
			return null;
		}

		if (!$this->changeSelfUpdateTime())
		{
			return null;
		}

		$rightsManager = Driver::getInstance()->getRightsManager();
		if (!$rightsManager->setAsNewLeaf($folderModel, $rights, $auditTransaction))
		{
			$this->errorCollection->add($rightsManager->getErrors());

			return null;
		}

		// after the rights of the new object are written: they are what its audience is read from
		$folderModel->publishToJournal(ChangeType::Created, $folderModel->getCreatedBy());

		$auditResult = (new AuditObjectEventProducer())->publishCreated(
			$folderModel,
			(int)$folderModel->getCreatedBy(),
		);
		if (!$folderModel->applyAuditResult($auditResult))
		{
			$this->errorCollection->add($folderModel->getErrors());

			return null;
		}

		$afterCommit = function () use ($folderModel): void {
			$objectEvent = $this->makeObjectEvent(
				'objectAdded',
				[
					'object' => [
						'id' => (int)$this->getId(),
						'type' => FolderTable::TYPE,
						'name' => $this->getName(),
					],
					'addedObject' => [
						'id' => (int)$folderModel->getId(),
					],
				]
			);
			$objectEvent->sendToObjectChannel();
		};

		if ($auditTransaction !== null)
		{
			$auditTransaction->addAfterCommit($afterCommit);
		}
		else
		{
			$afterCommit();
		}

		return $folderModel;
	}

	/**
	 * Adds link on folder in folder.
	 * @param Folder $sourceFolder Original folder.
	 * @param array $data Contains additional fields (CREATED_BY, NAME, etc).
	 * @param array $rights Rights (@see \Bitrix\Disk\RightsManager).
	 * @param bool  $generateUniqueName Generates unique name for object in directory.
	 * @throws \Bitrix\Main\ArgumentException
	 * @return FolderLink|null
	 */
	public function addSubFolderLink(Folder $sourceFolder, array $data, array $rights = array(), $generateUniqueName = false)
	{
		$this->errorCollection->clear();

		$data = $this->prepareDataForAddLink($sourceFolder, $data, $generateUniqueName);
		if(!$data)
		{
			return null;
		}

		/** @var FolderLink $fileLinkModel */
		$fileLinkModel = FolderLink::add($data, $this->errorCollection);
		if(!$fileLinkModel)
		{
			return null;
		}
		$this->changeSelfUpdateTime();
		Driver::getInstance()->getRightsManager()->setAsNewLeaf($fileLinkModel, $rights);
		// a link is a place the real object is seen at, and the journal is kept over real objects
		$fileLinkModel->publishToJournal(
			ChangeType::Created,
			$fileLinkModel->getCreatedBy(),
			['places'],
		);

		if (!$fileLinkModel->isDeleted())
		{
			// The record above carries the card of the folder and answers for the folder alone, while
			// the link is a place of everything under it too. The pair is published on purpose: the
			// card is what brings the folder itself into the memory of the client, and the signal
			// below is what moves the places of its descendants.
			//
			// A link made straight into the trashcan is left out: the places of that subtree were
			// told of by the record of the deletion that made it.
			(new PlacementAccessPublisher())->publishSubtreeConnected(
				(int)$fileLinkModel->getRealObjectId(),
				(int)$fileLinkModel->getId(),
			);
		}

		$objectEvent = $this->makeObjectEvent(
			'objectAdded',
			[
				'object' => [
					'id' => (int)$this->getId(),
					'type' => FolderTable::TYPE,
					'name' => $this->getName(),
				],
				'addedObject' => [
					'id' => (int)$fileLinkModel->getId(),
				],
			]
		);
		$objectEvent->sendToObjectChannel();

		return $fileLinkModel;
	}

	private function prepareDataForAddLink(BaseObject $object, array $data, $generateUniqueName = false)
	{
		if (empty($data['NAME']))
		{
			$data['NAME'] = $object->getName();
		}

		static::checkRequiredInputParams($data, array(
			'NAME'
		));

		$nameService = new ObjectNameService($data['NAME'], $this->id, $object->getType());
		if ($generateUniqueName)
		{
			$nameService->requireUniqueName();
		}

		$result = $nameService->prepareName();
		if (!$result->isSuccess())
		{
			$this->errorCollection->add($result->getErrors());

			return null;
		}

		$data['NAME'] = $result->getName();

		if (isset($data['DELETED_TYPE']) && $data['DELETED_TYPE'] == ObjectTable::DELETED_TYPE_ROOT)
		{
			$data['NAME'] = Ui\Text::appendTrashCanSuffix($data['NAME']);
			if (!static::isUniqueName($data['NAME'], $this->id))
			{
				$this->errorCollection[] = $this->createNonUniqueNameError();

				return null;
			}
		}


		$data['PARENT_ID'] = $this->id;
		$data['STORAGE_ID'] = $this->storageId;
		$data['REAL_OBJECT_ID'] = $object->getRealObject()->getId();

		return $data;
	}

	/**
	 * Copies object to target folder.
	 *
	 * A copy answers for the tree as a whole: the quota of the target disk is asked once about
	 * everything under this folder, before the folder itself is created there. A tree that does not
	 * fit leaves neither the folder nor a file of it in the target, instead of the user finding a
	 * folder copied and empty.
	 *
	 * The format policy of the one copying judges the tree the same way: a restricted file, or content
	 * the read budget leaves unchecked, refuses the copy before anything of it is written. The content
	 * judged here is not read again by the write of each file, which still judges the name.
	 *
	 * @param Folder $targetFolder Target folder.
	 * @param int    $updatedBy Id of user.
	 * @param bool   $generateUniqueName Generates unique name for object in directory.
	 * @return BaseObject|null
	 */
	public function copyTo(Folder $targetFolder, $updatedBy, $generateUniqueName = false)
	{
		$this->errorCollection->clear();

		if (AuditOperationContextStack::current() === null)
		{
			return AuditOperationContextStack::runWithContext(
				AuditOperationContext::create(
					actionKind: AuditOperationContext::ACTION_EXPLICIT_COPY,
					selectedTargetIds: [(string)$this->getId()],
				),
				fn() => $this->copyTo($targetFolder, $updatedBy, $generateUniqueName),
			);
		}

		if($this->getId() == $targetFolder->getId())
		{
			return $this;
		}

		$quotaError = $this->findPersonalQuotaErrorForCopy($targetFolder);
		if ($quotaError)
		{
			$this->errorCollection->addOne($quotaError);

			return null;
		}

		// a link carries no content of its own, so it is not judged, as its move is not
		$options = [];
		if (!$this->isLink())
		{
			$formatPolicyError = $this->judgeDescendantsByFormatPolicy(
				File::getFormatPolicyService(),
				(int)$updatedBy,
				null,
				$checkedFileIds,
			);
			if ($formatPolicyError)
			{
				$this->errorCollection->addOne($formatPolicyError);

				return null;
			}

			$options = self::createSignatureCheckOptions($checkedFileIds);
		}

		$transaction = new AuditTransaction(Application::getConnection());
		$result = $transaction->execute(
			function (AuditTransaction $transaction) use ($targetFolder, $updatedBy, $generateUniqueName, $options) {
				$folder = $this->copyToInsideAuditTransaction(
					$targetFolder,
					$updatedBy,
					(bool)$generateUniqueName,
					$transaction,
					true,
					$options,
				);

				return $folder instanceof self
					? AuditTransactionOutcome::success($folder)
					: AuditTransactionOutcome::failure();
			},
		);

		/** @var Folder|null $folder */
		$folder = $this->unwrapAuditCommandResult($result);

		return $folder;
	}

	/**
	 * The copy step of a cross-storage move of a whole tree. The files inside travel as moved
	 * content, judged by the format policy of the one moving, the same as a file moved on its own.
	 * The room for it is answered for where the files themselves are moved, so the quota of the whole
	 * tree is not asked here.
	 *
	 * @param Folder $targetFolder Destination folder.
	 * @param int    $movedBy User id of user, which moves folder.
	 * @param AuditTransaction $auditTransaction Transaction of the move the copy is made inside.
	 * @return BaseObject|null
	 */
	protected function copyForMove(Folder $targetFolder, $movedBy, AuditTransaction $auditTransaction)
	{
		// what the move has judged of the tree before the copy (@see self::findFormatPolicyErrorForMove())
		$signatureCheckOptions = $this->signatureCheckOptionsOfMove;
		$this->signatureCheckOptionsOfMove = [];

		return $this->copyToInsideAuditTransaction($targetFolder, $movedBy, false, $auditTransaction, false, [
			WriteOperationContext::OPTION_KEY => WriteOperationContext::StoredContentMove,
			...$signatureCheckOptions,
		]);
	}

	/**
	 * A folder is moved whole or not at all, so a file under it the policy of the one moving
	 * restricts refuses the move of the folder.
	 */
	protected function findFormatPolicyErrorForMove(int $movedBy): ?Error
	{
		$this->signatureCheckOptionsOfMove = [];
		if ($this->isLink())
		{
			return null;
		}

		$error = $this->judgeDescendantsByFormatPolicy(File::getFormatPolicyService(), $movedBy, null, $checkedFileIds);
		if ($error === null)
		{
			$this->signatureCheckOptionsOfMove = self::createSignatureCheckOptions($checkedFileIds);
		}

		return $error;
	}

	/**
	 * What the copy of the tree is told about the content the check has judged: the files judged, with
	 * the content each of them was judged by, or the whole tree when the check needs no content at all.
	 *
	 * @param array<int, int>|null $checkedFileIds Content judged by the object id, null when none is needed.
	 */
	private static function createSignatureCheckOptions(?array $checkedFileIds): array
	{
		if ($checkedFileIds === null)
		{
			return [WriteOperationContext::OPTION_KEY_SIGNATURE_CHECKED => true];
		}

		return [WriteOperationContext::OPTION_KEY_SIGNATURE_CHECKED_FILES => $checkedFileIds];
	}

	/**
	 * A file of the tree is written as checked only while it keeps the content the check has judged.
	 * A file put under the tree or given a new version after the check has its content read by the
	 * write, and content the spent budget leaves unread refuses it.
	 */
	private static function resolveCopyOptionsOfFile(array $options, File $file): array
	{
		if (!array_key_exists(WriteOperationContext::OPTION_KEY_SIGNATURE_CHECKED_FILES, $options))
		{
			return $options;
		}

		$checkedFileIds = $options[WriteOperationContext::OPTION_KEY_SIGNATURE_CHECKED_FILES];
		unset($options[WriteOperationContext::OPTION_KEY_SIGNATURE_CHECKED_FILES]);

		if (($checkedFileIds[(int)$file->getId()] ?? null) === (int)$file->getFileId())
		{
			$options[WriteOperationContext::OPTION_KEY_SIGNATURE_CHECKED] = true;

			return $options;
		}

		unset($options[WriteOperationContext::OPTION_KEY_SIGNATURE_CHECKED]);
		$options[WriteOperationContext::OPTION_KEY_CONTENT_CHECK_REQUIRED] = true;

		return $options;
	}

	/**
	 * Returns the refusal of the format policy of the user for a file under this folder, or null when
	 * the policy restricts none of them. The files of the subtree are read by one query, together with
	 * the kind of the storage their content lies in, and judged a chunk at a time: the names of the
	 * chunk first, and a restricted name refuses at once; then, when the content can change the
	 * verdict, the rows of b_file of the files whose kind is still read are loaded by one query and the
	 * content of each file is read, and restricted content refuses at once. The kinds stop apart: the
	 * spent cloud budget or a cloud storage given up on stops the cloud files alone, and the local ones
	 * are read on in the time of the operation. Content the spent budget of its kind leaves unread does
	 * not stop the walk: it is counted, and a tree with no restricted file is refused as not verified
	 * when anything was left unread. Content the storage refuses to give is left to the name. Links are
	 * not judged: they carry no content of their own.
	 *
	 * @internal
	 * @param FormatPolicyService $policyService Service judging the names of the files.
	 * @param int $actorId User moving or copying the folder; none gets the global rule.
	 * @param ContentHeadReader|null $contentHeadReader Reader whose budget the walk spends, a new one by default.
	 * @return Error|null Refusal with the id of the restricted file in customData['objectId'], or the
	 * refusal File::ERROR_FORMAT_NOT_VERIFIED with the number of files left unread in
	 * customData['notVerifiedCount'].
	 */
	public function findFormatPolicyErrorForDescendants(
		FormatPolicyService $policyService,
		int $actorId,
		?ContentHeadReader $contentHeadReader = null,
	): ?Error
	{
		return $this->judgeDescendantsByFormatPolicy($policyService, $actorId, $contentHeadReader, $checkedFileIds);
	}

	/**
	 * @see self::findFormatPolicyErrorForDescendants()
	 * @param array<int, int>|null $checkedFileIds Receives the files whose content was judged and
	 * found allowed, the id of the object to the id of the content judged; null when the policy needs
	 * no content of the tree.
	 */
	private function judgeDescendantsByFormatPolicy(
		FormatPolicyService $policyService,
		int $actorId,
		?ContentHeadReader $contentHeadReader,
		?array &$checkedFileIds,
	): ?Error
	{
		$checkedFileIds = null;
		$policyActorId = $actorId > 0 ? $actorId : null;
		$context = WriteOperationContext::StoredContentMove;
		if (!$policyService->isPolicyApplied($context, $policyActorId))
		{
			return null;
		}

		if ($policyService->needsContentHead($context, $policyActorId))
		{
			$contentHeadReader ??= new ContentHeadReader();
			$checkedFileIds = [];
		}
		else
		{
			$contentHeadReader = null;
		}

		$notVerifiedCount = 0;
		$rows = $this->queryOwnFilesUnder()->exec();
		foreach ($this->readRowsInChunks($rows, self::CONTENT_CHECK_CHUNK_SIZE) as $chunk)
		{
			foreach ($chunk as $row)
			{
				$name = Ui\Text::cleanTrashCanSuffix((string)$row['NAME']);
				if (!$policyService->isAllowed($name, $context, $policyActorId)->isSuccess())
				{
					return $this->findFormatPolicyErrorForDescendant($row, $actorId);
				}
			}

			if ($contentHeadReader === null)
			{
				continue;
			}

			$contentHeadReader->preloadFileRows(array_column(
				array_filter(
					$chunk,
					static fn (array $row): bool => !$contentHeadReader->isStopped(
						ContentHeadReader::isCloudHandler($row['HANDLER_ID']),
					),
				),
				'FILE_ID',
			));
			foreach ($chunk as $row)
			{
				$error = $this->findFormatPolicyErrorForDescendant($row, $actorId, $contentHeadReader);
				if ($error === null)
				{
					$checkedFileIds[(int)$row['ID']] = (int)$row['FILE_ID'];

					continue;
				}

				if ($error->getCode() !== File::ERROR_FORMAT_NOT_VERIFIED)
				{
					return $error;
				}

				$notVerifiedCount++;
			}
		}

		if ($notVerifiedCount > 0)
		{
			return new Error(
				Loc::getMessage('DISK_FOLDER_MODEL_ERROR_FORMAT_NOT_VERIFIED'),
				File::ERROR_FORMAT_NOT_VERIFIED,
				['notVerifiedCount' => $notVerifiedCount],
			);
		}

		return null;
	}

	/**
	 * Files of this subtree that keep content of their own, links left out.
	 */
	private function queryOwnFilesUnder(): OrmQuery
	{
		return self::queryOwnFiles()->where('PATH_CHILD.PARENT_ID', $this->id);
	}

	/**
	 * Files that keep content of their own, links left out, wherever they lie.
	 */
	private static function queryOwnFiles(): OrmQuery
	{
		return ObjectTable::query()
			->setSelect([
				'ID',
				'NAME',
				'TYPE',
				'REAL_OBJECT_ID',
				'FILE_ID',
				'STORAGE_ID',
				'PARENT_ID',
				'HANDLER_ID' => 'FILE_CONTENT.HANDLER_ID',
			])
			->where('TYPE', ObjectTable::TYPE_FILE)
			->where('DELETED_TYPE', ObjectTable::DELETED_TYPE_NONE)
			->whereColumn('REAL_OBJECT_ID', 'ID')
		;
	}

	private function findFormatPolicyErrorForDescendant(
		array $row,
		int $actorId,
		?ContentHeadReader $contentHeadReader = null,
	): ?Error
	{
		$file = BaseObject::buildFromArray($row);
		if (!$file instanceof File)
		{
			return null;
		}

		$error = $file->findFormatPolicyErrorForStored(
			$actorId,
			$contentHeadReader,
			ContentHeadReader::isCloudHandler($row['HANDLER_ID']),
		);

		return $error === null ? null : self::createFormatPolicyErrorForObject($error, $file->getId());
	}

	private static function createFormatPolicyErrorForObject(Error $error, int $objectId): Error
	{
		return new Error($error->getMessage(), $error->getCode(), ['objectId' => $objectId]);
	}

	/**
	 * Copies the folder with everything under it. What the descendants are written as comes from the
	 * caller through $options; a descendant left behind fails the whole tree, and the transaction
	 * takes back what was copied before it.
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
		if ($this instanceof FolderLink)
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

		$newRoot = $this->copyFolderNodeInsideAuditTransaction(
			$targetFolder,
			$updatedBy,
			$generateUniqueName,
			$auditTransaction,
		);
		if(!$newRoot)
		{
			return null;
		}
		$mapParentToNewParent = array(
			$this->getId() => $newRoot,
		);

		$parameters = array(
			'select' => array(
				'*',
				'DEPTH_LEVEL' => 'PATH_CHILD.DEPTH_LEVEL',
			),
			'filter' => array(
				'DELETED_TYPE' => FolderTable::DELETED_TYPE_NONE,
				'PATH_CHILD.PARENT_ID' => $this->id,
			),
			'order' => array('DEPTH_LEVEL' => 'ASC')
		);

		$objectIterator = FolderTable::getList(static::prepareGetListParameters($parameters));
		foreach ($objectIterator as $objectRow)
		{
			if ($objectRow['ID'] == $this->id)
			{
				continue;
			}

			$item = BaseObject::buildFromArray($objectRow);
			if ($item->isLink())
			{
				continue;
			}

			if(!isset($mapParentToNewParent[$item->getParentId()]))
			{
				return null;
			}

			/** @var Folder $newParentFolder */
			$newParentFolder = $mapParentToNewParent[$item->getParentId()];
			if($item instanceof File)
			{
				$newFile = $item->copyToInsideAuditTransaction(
					$newParentFolder,
					$updatedBy,
					$generateUniqueName,
					$auditTransaction,
					false,
					self::resolveCopyOptionsOfFile($options, $item),
				);
				if(!$newFile)
				{
					$this->errorCollection->add($item->getErrors());

					return null;
				}
			}
			elseif ($item instanceof Folder)
			{
				$newFolder = $item->copyFolderNodeInsideAuditTransaction(
					$newParentFolder,
					$updatedBy,
					$generateUniqueName,
					$auditTransaction,
				);
				if(!$newFolder)
				{
					$this->errorCollection->add($item->getErrors());

					return null;
				}
				$mapParentToNewParent[$item->getId()] = $newFolder;
			}
		}

		if ($publishRootAudit)
		{
			$auditResult = (new AuditObjectEventProducer())->publishCopied($this, $newRoot, (int)$updatedBy);
			if (!$this->applyAuditResult($auditResult))
			{
				return null;
			}
		}

		return $newRoot;
	}

	/**
	 * The refusal of the personal quota for the copy of this tree, or null when the copy is allowed.
	 * The size is the one the copy writes into the target storage - the head versions of every file
	 * under the folder - so the quota is asked once about the whole tree instead of file by file.
	 *
	 * The write node keeps its own check for every file: this point only spares the user a folder
	 * copied without the files it was copied for.
	 *
	 * @param Folder $targetFolder Folder the tree is copied into.
	 * @return Error|null
	 */
	private function findPersonalQuotaErrorForCopy(Folder $targetFolder): ?Error
	{
		$sizeToCopy = (int)$this->getSize();
		if ($sizeToCopy <= 0)
		{
			return null;
		}

		// the target may be a link to a folder, and the copy is written into the storage it points at
		return File::findPersonalQuotaErrorForSize(
			$targetFolder->getRealObject()->getStorage(),
			$sizeToCopy,
			[WriteOperationContext::OPTION_KEY => WriteOperationContext::UserUpload],
		);
	}

	private function copyFolderNodeInsideAuditTransaction(
		Folder $targetFolder,
		$updatedBy,
		bool $generateUniqueName,
		AuditTransaction $auditTransaction,
	): ?Folder
	{
		return $targetFolder->addSubFolder([
			'NAME' => $this->getName(),
			'CREATED_BY' => $updatedBy,
		], [], $generateUniqueName, $auditTransaction);
	}

	protected function copyToInternal(Folder $targetFolder, $updatedBy, $generateUniqueName = false)
	{
		$newFolder = $targetFolder->addSubFolder(array(
			'NAME' => $this->getName(),
			'CREATED_BY' => $updatedBy,
		), array(), $generateUniqueName);

		if(!$newFolder)
		{
			$this->errorCollection->add($targetFolder->getErrors());
			return null;
		}
		return $newFolder;
	}

	/**
	 * Gets all descendants objects by the folder.
	 * @param SecurityContext $securityContext Security context.
	 * @param array           $parameters Parameters. Key "subtree_check" selects how membership in the
	 *                        subtree is checked, see ObjectTable::SUBTREE_CHECK_*. With
	 *                        ObjectTable::SUBTREE_CHECK_EXISTS objects come without DEPTH_LEVEL and
	 *                        are not ordered by it.
	 * @param int             $orderDepthLevel Order for depth level (default asc).
	 * @throws \Bitrix\Main\ArgumentOutOfRangeException
	 * @return BaseObject[]
	 */
	public function getDescendants(SecurityContext $securityContext, array $parameters = array(), $orderDepthLevel = SORT_ASC)
	{
		if(!isset($parameters['filter']))
		{
			$parameters['filter'] = array();
		}
		if(!isset($parameters['select']))
		{
			$parameters['select'] = array('*');
		}

		if(!empty($parameters['filter']['MIXED_SHOW_DELETED']))
		{
			unset($parameters['filter']['DELETED_TYPE'], $parameters['filter']['MIXED_SHOW_DELETED']);
		}
		elseif (
			!array_key_exists('DELETED_TYPE', $parameters['filter']) &&
			!array_key_exists('!DELETED_TYPE', $parameters['filter']) &&
			!array_key_exists('!=DELETED_TYPE', $parameters['filter']) &&
			!array_key_exists('!==DELETED_TYPE', $parameters['filter'])
		)
		{
			$parameters['filter']['DELETED_TYPE'] = ObjectTable::DELETED_TYPE_NONE;
		}

		$useSubtreeCheckByExists = $this->useSubtreeCheckByExists($parameters);
		$parameters['subtree_check'] = $useSubtreeCheckByExists
			? ObjectTable::SUBTREE_CHECK_EXISTS
			: ObjectTable::SUBTREE_CHECK_JOIN
		;

		if ($useSubtreeCheckByExists)
		{
			// storage of a descendant always matches the storage of its container, so the condition
			// narrows candidates by an index on the object table before the subtree is checked
			if (!static::hasNarrowingConditionOnField($parameters['filter'], 'STORAGE_ID'))
			{
				$parameters['filter']['=STORAGE_ID'] = $this->storageId;
			}

			// the cheap subtree condition is written ahead of the expensive rights check below: the
			// database promises no order of evaluation, but the plans seen here keep the one written
			$parameters = ObjectTable::addSubtreeCheckByExists($parameters, (int)$this->id);
		}
		else
		{
			$parameters['select']['DEPTH_LEVEL'] = 'PATH_CHILD.DEPTH_LEVEL';
		}

		$parameters = Driver::getInstance()->getRightsManager()->addRightsCheck($securityContext, $parameters, array('ID', 'CREATED_BY'));

		$data = FolderTable::getDescendants($this->id, static::prepareGetListParameters($parameters))->fetchAll();
		if(!$useSubtreeCheckByExists && $orderDepthLevel !== null)
		{
			Collection::sortByColumn($data, array('DEPTH_LEVEL' => $orderDepthLevel));
		}

		$modelData = array();
		foreach($data as $item)
		{
			$modelData[] = BaseObject::buildFromArray($item);
		}
		unset($item);

		return $modelData;
	}

	/**
	 * The mode is requested by the caller and may be switched off portal wide: it relies on an object
	 * keeping the storage of its container, and the option is the way back to the join if it does not.
	 */
	private function useSubtreeCheckByExists(array $parameters): bool
	{
		return
			($parameters['subtree_check'] ?? null) === ObjectTable::SUBTREE_CHECK_EXISTS
			&& Configuration::isEnabledSubtreeCheckByExists()
		;
	}

	/**
	 * Tells whether the filter already holds the field to a value or a set of them.
	 *
	 * Such a condition of the caller is left alone: a second one beside it either says the same thing twice
	 * or contradicts it. An operation holding the field to nothing ("!=", ">", "%=") is not that condition
	 * and gets a condition of the mode next to it, so the index the mode counts on stays in the plan.
	 *
	 * The key is read by the parser the ORM itself reads it with, so the set of operations understood here
	 * is the one understood there. Keys of the top level are the whole question: a condition of a nested
	 * block belongs to a key of its own and is never the one overwritten by an assignment to the top level.
	 */
	private static function hasNarrowingConditionOnField(array $filter, string $field): bool
	{
		$sqlWhere = new \CSQLWhere();
		foreach (array_keys($filter) as $key)
		{
			if (!is_string($key))
			{
				continue;
			}

			$condition = $sqlWhere->makeOperation($key);
			if (
				$condition['FIELD'] === $field
				&& in_array($condition['OPERATION'], self::NARROWING_OPERATIONS, true)
			)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Gets direct children (files, folders).
	 * @param SecurityContext $securityContext Security context.
	 * @param array           $parameters Parameters.
	 * @return BaseObject[]
	 */
	public function getChildren(SecurityContext $securityContext, array $parameters = array())
	{
		if(!isset($parameters['filter']))
		{
			$parameters['filter'] = array();
		}
		if(!empty($parameters['filter']['MIXED_SHOW_DELETED']))
		{
			unset($parameters['filter']['DELETED_TYPE'], $parameters['filter']['MIXED_SHOW_DELETED']);
		}
		elseif (
			!array_key_exists('DELETED_TYPE', $parameters['filter']) &&
			!array_key_exists('!DELETED_TYPE', $parameters['filter']) &&
			!array_key_exists('!=DELETED_TYPE', $parameters['filter']) &&
			!array_key_exists('!==DELETED_TYPE', $parameters['filter'])
		)
		{
			$parameters['filter']['DELETED_TYPE'] = ObjectTable::DELETED_TYPE_NONE;
		}
		$parameters = Driver::getInstance()->getRightsManager()->addRightsCheck($securityContext, $parameters, array('ID', 'CREATED_BY'));

		$modelData = array();
		$query = FolderTable::getChildren($this->id, static::prepareGetListParameters($parameters));
		while($item = $query->fetch())
		{
			$modelData[] = BaseObject::buildFromArray($item);
		}

		return $modelData;
	}

	/**
	 * Returns first child by filter.
	 * @param array $filter Filter.
	 * @param array $with List of eager loading.
	 *
	 * @return Folder|File|BaseObject
	 */
	public function getChild(array $filter, array $with = array())
	{
		$filter['PARENT_ID'] = $this->id;

		return BaseObject::load($filter, $with);
	}

	/**
	 * Returns the file of the folder with the same name: the case does not matter, diacritics do.
	 */
	public function getChildFileWithSameName(string $name): ?File
	{
		$id = $this->findChildIdWithSameName($name, ObjectTable::TYPE_FILE);

		return $id ? File::loadById($id) : null;
	}

	/**
	 * Returns the sub-folder of the folder with the same name: the case does not matter, diacritics do.
	 */
	public function getChildFolderWithSameName(string $name): ?Folder
	{
		$id = $this->findChildIdWithSameName($name, ObjectTable::TYPE_FOLDER);

		return $id ? Folder::loadById($id) : null;
	}

	private function findChildIdWithSameName(string $name, int $type): ?int
	{
		$row = ObjectNameKey::findSameName(
			[
				'select' => ['ID', 'NAME'],
				'filter' => [
					'=PARENT_ID' => $this->getRealObjectId(),
					'=TYPE' => $type,
				],
			],
			$name,
		);

		return $row ? (int)$row['ID'] : null;
	}

	/**
	 * Checks if folder has children.
	 * @return bool
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function hasChildren(): bool
	{
		return ObjectPathTable::hasChildren($this->getRealObjectId());
	}

	/**
	 * Counts size of all version under folder. Possible to use security context and filter.
	 * @param SecurityContext|null $securityContext Security context.
	 * @param array                $filter Filter.
	 * @return null|int
	 */
	public function countSizeOfVersions(SecurityContext $securityContext = null, array $filter = array())
	{
		$filter = array(
			'WITH_SYMLINKS' => false,
			'WITH_DELETED' => true,
		);

		$query = new Query(VersionTable::getEntity());
		$query
			->registerRuntimeField('', new ExpressionField('FILE_SIZE', 'SUM(SIZE)'))
			->addSelect('FILE_SIZE')
			->addFilter('=PATH_CHILD.PARENT_ID', $this->id)
		;

		$result = $query->exec();
		$row = $result->fetch();
		if(isset($row['FILE_SIZE']))
		{
			return $row['FILE_SIZE'];
		}

		return null;
	}

	/**
	 * Counts size of head version under folder. Possible to use security context and filter.
	 * @param SecurityContext|null $securityContext Security context.
	 * @param array                $filter Filter.
	 * @return null|int
	 */
	public function countSizeOfFiles(SecurityContext $securityContext = null, array $filter = array())
	{
		return $this->countSizeHeadOfVersions($securityContext, $filter);
	}

	/**
	 * Counts size of head version under folder. Possible to use security context and filter.
	 * @param SecurityContext|null $securityContext Security context.
	 * @param array                $filter Filter.
	 * @return null|int
	 */
	public function countSizeHeadOfVersions(SecurityContext $securityContext = null, array $filter = array())
	{
		$filter = array(
			'WITH_SYMLINKS' => false,
			'WITH_DELETED' => true,
		);

		$query = new Query(FolderTable::getEntity());
		$query
			->registerRuntimeField('', new ExpressionField('FILE_SIZE', 'SUM(SIZE)'))
			->addSelect('FILE_SIZE')
			->addFilter('=PATH_CHILD.PARENT_ID', $this->id)
		;

		$result = $query->exec();
		$row = $result->fetch();
		if(isset($row['FILE_SIZE']))
		{
			return $row['FILE_SIZE'];
		}

		return null;
	}

	/**
	 * Marks deleted object. It equals to move in trash can.
	 * @param int $deletedBy Id of user (or SystemUser::SYSTEM_USER_ID).
	 * @return bool
	 */
	public function markDeleted($deletedBy)
	{
		$context = AuditOperationContext::create(
			actionKind: AuditOperationContext::ACTION_CASCADE_OPERATION,
			cascadeRootTargetId: (string)$this->getId(),
		);

		return AuditOperationContextStack::runWithContext(
			$context,
			fn(): bool => $this->markDeletedTree($deletedBy),
		);
	}

	protected function markDeletedInsideAuditTransaction(
		$movedBy,
		AuditTransaction $auditTransaction,
	): bool
	{
		return $this->markDeletedTree($movedBy, $auditTransaction);
	}

	private function markDeletedTree(
		$deletedBy,
		?AuditTransaction $auditTransaction = null,
	): bool
	{
		if ($this->deletedType == ObjectTable::DELETED_TYPE_ROOT)
		{
			return true;
		}

		$this->errorCollection->clear();

		$restrictedFolder = $this->findDeletionRestrictedFolderInTree($this->normalizeDeletedBy($deletedBy));
		if ($restrictedFolder !== null)
		{
			$this->addDeletionRestrictedError($restrictedFolder);

			return false;
		}

		$driver = Driver::getInstance();
		$driver->getSubscriberManager()->preloadSharingsForSubtree($this);

		//the root goes to the trash can first: a failure below never leaves descendants without a root
		$success = $this->markDeletedNonRecursiveInternal(
			$deletedBy,
			ObjectTable::DELETED_TYPE_ROOT,
			$auditTransaction,
		);
		if ($success)
		{
			$success = $this->markDeletedDescendants($deletedBy, $auditTransaction);
		}

		$finalizeDeletion = static function () use ($driver): void {
			$driver->getDeletedLogManager()->finalize();
			$driver->getDeletionNotifyManager()->send();
		};
		if ($auditTransaction !== null)
		{
			if ($success)
			{
				$auditTransaction->addAfterCommit($finalizeDeletion);
			}
		}
		else
		{
			$finalizeDeletion();
		}

		return $success;
	}

	private function markDeletedDescendants(
		$deletedBy,
		?AuditTransaction $auditTransaction,
	): bool
	{
		$parameters = array(
			'select' => array(
				'*',
				'DEPTH_LEVEL' => 'PATH_CHILD.DEPTH_LEVEL',
			),
			'filter' => array(
				'PATH_CHILD.PARENT_ID' => $this->id,
			),
			//a parent goes to the trash can before its children, so subscribers are taken from the cache of the
			//parent instead of a query for parents, which skips the ones already marked deleted
			'order' => array('DEPTH_LEVEL' => 'ASC')
		);

		$success = true;
		$objectIterator = FolderTable::getList(static::prepareGetListParameters($parameters));
		foreach ($objectIterator as $objectRow)
		{
			if ($objectRow['ID'] == $this->id)
			{
				continue;
			}

			/** @var Folder|File $object */
			$object = BaseObject::buildFromArray($objectRow);
			if ($object instanceof Folder)
			{
				/** @see \Bitrix\Disk\Folder::markDeletedNonRecursiveInternal */
				$isMarked = $object->markDeletedNonRecursiveInternal(
					$deletedBy,
					ObjectTable::DELETED_TYPE_CHILD,
					$auditTransaction,
				);
			}
			elseif ($object instanceof File)
			{
				$isMarked = $object->markDeletedInternal(
					$deletedBy,
					ObjectTable::DELETED_TYPE_CHILD,
					$auditTransaction,
				);
			}
			else
			{
				continue;
			}

			//one broken descendant must not stop the cascade, otherwise the rest of the subtree stays outside the trash can
			if (!$isMarked)
			{
				$this->addDistinctErrors($object->getErrors());
				$success = false;

				//an audit transaction rolls the whole cascade back, so there is nothing left to mark
				if ($auditTransaction !== null)
				{
					break;
				}
			}
		}

		return $success;
	}

	//keeps one error per code: hundreds of descendants failing the same way must not fill the answer with copies
	private function addDistinctErrors(array $errors): void
	{
		foreach ($errors as $error)
		{
			if ($this->errorCollection->getErrorByCode($error->getCode()) === null)
			{
				$this->errorCollection->setError($error);
			}
		}
	}

	protected function markDeletedNonRecursiveInternal(
		$deletedBy,
		$deletedType = ObjectTable::DELETED_TYPE_ROOT,
		?AuditTransaction $auditTransaction = null,
	)
	{
		if ($this->isDeletionRestricted($this->normalizeDeletedBy($deletedBy)))
		{
			$this->addDeletionRestrictedError($this);

			return false;
		}

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

		$this->errorCollection->clear();
		$context = AuditOperationContext::create(
			actionKind: AuditOperationContext::ACTION_CASCADE_OPERATION,
			cascadeRootTargetId: (string)$this->getId(),
		);

		return AuditOperationContextStack::runWithContext(
			$context,
			function () use ($restoredBy): bool {
				$parameters = array(
					'select' => array(
						'*',
						'DEPTH_LEVEL' => 'PATH_CHILD.DEPTH_LEVEL',
						'HANDLER_ID' => 'FILE_CONTENT.HANDLER_ID',
					),
					'filter' => array(
						'PATH_CHILD.PARENT_ID' => $this->id,
						'!==DELETED_TYPE' => ObjectTable::DELETED_TYPE_NONE,
					),
					'order' => array('DEPTH_LEVEL' => 'DESC')
				);

				$objectIterator = FolderTable::getList(static::prepareGetListParameters($parameters));
				$formatPolicyErrors = [];
				$notVerifiedCount = 0;
				$contentHeadReader = $this->createContentHeadReaderForRestore((int)$restoredBy);
				foreach ($this->readRowsInChunks($objectIterator, self::CONTENT_CHECK_CHUNK_SIZE) as $objectRows)
				{
					$contentHeadReader?->preloadFileRows(array_column(
						array_filter(
							$objectRows,
							static fn (array $row): bool => (int)$row['TYPE'] === ObjectTable::TYPE_FILE
								&& $row['ID'] == $row['REAL_OBJECT_ID']
								&& !$contentHeadReader->isStopped(
									ContentHeadReader::isCloudHandler($row['HANDLER_ID']),
								),
						),
						'FILE_ID',
					));

					foreach ($objectRows as $objectRow)
					{
						$this->restoreDescendantRow(
							$objectRow,
							$restoredBy,
							$contentHeadReader,
							$formatPolicyErrors,
							$notVerifiedCount,
						);
					}
				}

				if ($notVerifiedCount > 0)
				{
					$formatPolicyErrors[] = new Error(
						Loc::getMessage('DISK_FOLDER_MODEL_ERROR_FORMAT_NOT_VERIFIED_LEFT_IN_TRASH'),
						File::ERROR_FORMAT_NOT_VERIFIED,
						['notVerifiedCount' => $notVerifiedCount],
					);
				}

				$needRecalculate = $this->deletedType == ObjectTable::DELETED_TYPE_CHILD;
				$statusRestoreNonRecursive = $this->restoreInternal($restoredBy);
				if($statusRestoreNonRecursive && $needRecalculate)
				{
					$this->recalculateDeletedTypeAfterRestore($restoredBy);
				}

				if($statusRestoreNonRecursive)
				{
					Driver::getInstance()->sendChangeStatusToSubscribers($this);
				}

				// added last: the restore of the folder itself starts from a clean collection
				$this->errorCollection->add($formatPolicyErrors);

				return $statusRestoreNonRecursive;
			},
		);
	}

	/**
	 * A file the format policy of the one restoring restricts stays in the trash can as a root of its
	 * own, and its refusal is collected; everything else under the folder is restored. A file whose
	 * content the spent read budget of its kind of storage leaves unchecked stays there the same way
	 * and is only counted: the files left unchecked are told of by one error of the whole restore.
	 *
	 * @param Error[] $formatPolicyErrors
	 */
	private function restoreDescendantRow(
		array $objectRow,
		$restoredBy,
		?ContentHeadReader $contentHeadReader,
		array &$formatPolicyErrors,
		int &$notVerifiedCount,
	): void
	{
		if ($objectRow['ID'] == $this->id)
		{
			return;
		}

		$object = BaseObject::buildFromArray($objectRow);
		// the journal takes one record on the root of the restore, below
		$object->journalCascadeChild = true;

		/** @var Folder|File */
		if($object instanceof Folder)
		{
			/** @see \Bitrix\Disk\Folder::restoreNonRecursive */
			$object->restoreNonRecursive($restoredBy);
		}
		elseif($object instanceof File)
		{
			$formatPolicyError = $object->isLink()
				? null
				: $object->findFormatPolicyErrorForStored(
					(int)$restoredBy,
					$contentHeadReader,
					ContentHeadReader::isCloudHandler($objectRow['HANDLER_ID'] ?? null),
				)
			;
			if ($formatPolicyError)
			{
				if ($formatPolicyError->getCode() === File::ERROR_FORMAT_NOT_VERIFIED)
				{
					$notVerifiedCount++;
				}
				else
				{
					$formatPolicyErrors[] = self::createFormatPolicyErrorForObject(
						$formatPolicyError,
						(int)$object->getId(),
					);
				}
				// the file stays in the trash can as a root of its own, the folder above it is restored
				if (!$object->keepInTrashCanAsRoot())
				{
					array_push($formatPolicyErrors, ...$object->getErrors());
				}

				return;
			}

			$object->restoreInternal($restoredBy);
		}
	}

	/**
	 * The content of the restored files is read by one reader for the whole restore, so the files
	 * share its budget and their rows of b_file are loaded a chunk at a time. No reader is needed
	 * when the policy of the one restoring is decided by the names alone.
	 */
	protected function createContentHeadReaderForRestore(int $restoredBy): ?ContentHeadReader
	{
		$needsContentHead = File::getFormatPolicyService()->needsContentHead(
			WriteOperationContext::StoredContentMove,
			$restoredBy > 0 ? $restoredBy : null,
		);

		return $needsContentHead ? new ContentHeadReader() : null;
	}

	/**
	 * @return \Generator<array[]> Rows of the iterator, this many at a time.
	 */
	private function readRowsInChunks(iterable $rows, int $chunkSize): \Generator
	{
		$chunk = [];
		foreach ($rows as $row)
		{
			$chunk[] = $row;
			if (count($chunk) >= $chunkSize)
			{
				yield $chunk;
				$chunk = [];
			}
		}

		if ($chunk)
		{
			yield $chunk;
		}
	}

	protected function restoreNonRecursive($restoredBy)
	{
		return parent::restoreInternal($restoredBy);
	}

	private static function getStorageUsageCounter(): StorageUsageCounter
	{
		return ServiceLocator::getInstance()->get(StorageUsageCounter::class);
	}

	/**
	 * Deletes folder and all descendants objects.
	 * @param int $deletedBy Id of user (or SystemUser::SYSTEM_USER_ID).
	 * @param bool $bypassDeletionRestriction Skip deletion restriction check (e.g. on owner teardown). $deletedBy is kept intact in events.
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ArgumentNullException
	 * @return bool
	 */
	public function deleteTree($deletedBy, bool $bypassDeletionRestriction = false)
	{
		$this->errorCollection->clear();

		if (!$bypassDeletionRestriction)
		{
			$restrictedFolder = $this->findDeletionRestrictedFolderInTree($this->normalizeDeletedBy($deletedBy));
			if ($restrictedFolder !== null)
			{
				$this->addDeletionRestrictedError($restrictedFolder);

				return false;
			}
		}

		$context = AuditOperationContext::create(
			actionKind: AuditOperationContext::ACTION_CASCADE_OPERATION,
			cascadeRootTargetId: (string)$this->getId(),
		);
		$rootSnapshot = (new AuditSnapshotBuilder())->buildObjectSnapshot($this);

		return AuditOperationContextStack::runWithContext(
			$context,
			function () use ($deletedBy, $bypassDeletionRestriction, $rootSnapshot): bool {
				$parameters = array(
					'select' => array(
						'*',
						'DEPTH_LEVEL' => 'PATH_CHILD.DEPTH_LEVEL',
					),
					'filter' => array(
						'PATH_CHILD.PARENT_ID' => $this->id,
					),
					'order' => array('DEPTH_LEVEL' => 'DESC')
				);

				// One record on the root of the cascade, and one more on every descendant someone outside the
				// audience of the root holds by a right of the descendant itself. Both audiences are taken
				// here, because the rights they are read from are torn down object by object below; the
				// records are published after the loop, once the tree is provably gone. A cascade that broke
				// halfway would otherwise order the client to forget a folder the portal goes on holding;
				// what such a cascade did take away is buried object by object instead, for the audience of
				// the root joined by the own codes of the object.
				$publishesTombstone = !$this->isLink();
				$tombstoneAudience = $publishesTombstone ? $this->captureTombstoneAudience() : null;
				$descendantAudience = $publishesTombstone
					? $this->getDescendantTombstonePublisher()->capture($this, $tombstoneAudience)
					: []
				;

				// What the loop did take away, remembered for the records a broken cascade owes them below.
				$goneFolderIds = [];
				$goneFileIds = [];

				$success = true;
				$objectIterator = FolderTable::getList(static::prepareGetListParameters($parameters));
				// Every file of the subtree counts the versions it takes away, so the whole tree moves the
				// counter of the employee by a single update instead of one per file. This is the operation
				// the refusal of the quota sends them to do, so it is the one that must not cost a read and
				// a write of the counter row per file.
				$usageCounter = self::getStorageUsageCounter();
				$usageCounter->openAccumulator();
				try
				{
					foreach ($objectIterator as $objectRow)
					{
						if ($objectRow['ID'] == $this->id)
						{
							//to modify current object. Don't make another instance of $this->id
							$object = $this;
						}
						else
						{
							$object = BaseObject::buildFromArray($objectRow);
						}
						$object->journalCascadeChild = true;

						/** @var Folder|File */
						if($object instanceof Folder)
						{
							if ($object instanceof FolderLink)
							{
								// as the direct deletion of the link does, whatever the restriction
								$object->declineSharingOfTheLink($deletedBy);
							}
							/** @see \Bitrix\Disk\Folder::deleteNonRecursive */
							$success = $object->deleteNonRecursive(
								$deletedBy,
								$bypassDeletionRestriction,
								$object === $this ? $rootSnapshot : null,
							);
							if ($success && !$object->isLink())
							{
								$goneFolderIds[] = (int)$object->getId();
							}
						}
						elseif($object instanceof File)
						{
							/** @see \Bitrix\Disk\File::delete */
							$success = $object->delete($deletedBy);
							if ($success && !$object->isLink())
							{
								$goneFileIds[] = (int)$object->getId();
							}
						}

						if (!$success)
						{
							if ($object !== $this)
							{
								$this->errorCollection->add($object->getErrors());
							}

							break;
						}
					}

				}
				finally
				{
					// The scope is closed on a failure of the tree as well: what it summed reaches the
					// database only on the closing, so a tree that broke halfway still counts what it took.
					$usageCounter->flushAccumulator();
				}

				if (!$success)
				{
					$this->publishTombstonesOfTheCascadeThatBroke(
						$goneFolderIds,
						$goneFileIds,
						$deletedBy,
						$tombstoneAudience,
						$descendantAudience,
					);

					return false;
				}

				if ($publishesTombstone)
				{
					$this->publishTombstoneToJournal($deletedBy, $tombstoneAudience);
					$this->getDescendantTombstonePublisher()->publishGone(
						$descendantAudience,
						$this->normalizeDeletedBy($deletedBy),
					);
				}

				return true;
			},
		);
	}

	/**
	 * The objects a broken cascade already took away, buried one by one.
	 *
	 * A cascade that went through says everything with the one record on its root. One that stopped
	 * halfway has no such record to make - the root is still on the portal, and burying it would order
	 * the client to forget a folder the portal goes on holding - while everything the loop had reached
	 * by then is gone all the same, and nothing else in the contour goes looking for it.
	 *
	 * The audiences are the ones taken before the loop: the rights these objects were visible by are
	 * torn down together with them, so by now there is nothing left to read. Every object is addressed
	 * to the audience of the root, and a descendant that carried rights of its own is addressed to the
	 * codes of those rights as well, captured over the closure of the subtree
	 * ({@see \Bitrix\Disk\Internal\Service\Sync\Journal\DescendantTombstonePublisher}). What the portal
	 * still holds is buried for nobody.
	 * Whoever held a file of the subtree by an interaction alone is buried by a record of their own,
	 * published row by row while the rows were still there
	 * ({@see \Bitrix\Disk\Internal\Service\Sync\Journal\TrackedSetCleanupPublisher}).
	 *
	 * @param int[] $goneFolderIds
	 * @param int[] $goneFileIds
	 * @param string[]|null $audienceCodes the audience of the root
	 * @param array<int, array{resourceType: string, codes: list<string>}> $descendantAudience the own
	 *        codes of descendants outside the audience of the root
	 */
	private function publishTombstonesOfTheCascadeThatBroke(
		array $goneFolderIds,
		array $goneFileIds,
		$deletedBy,
		?array $audienceCodes,
		array $descendantAudience,
	): void
	{
		$writer = $this->getJournalWriter();
		$descendantPublisher = $this->getDescendantTombstonePublisher();
		$gone = [
			ChangeRecord::RESOURCE_FOLDER => $goneFolderIds,
			ChangeRecord::RESOURCE_FILE => $goneFileIds,
		];

		foreach ($gone as $resourceType => $objectIds)
		{
			foreach ($objectIds as $objectId)
			{
				$writer->publishTombstoneOfGoneObject(
					$resourceType,
					$objectId,
					TombstoneReason::Deleted,
					$this->normalizeDeletedBy($deletedBy),
					$descendantPublisher->audienceOfGoneObject($descendantAudience, $objectId, $audienceCodes),
				);
			}
		}
	}

	protected function getDescendantTombstonePublisher(): DescendantTombstonePublisher
	{
		return $this->descendantTombstonePublisher ??= new DescendantTombstonePublisher(
			journal: $this->getJournalWriter(),
		);
	}

	protected function deleteNonRecursive(
		$deletedBy,
		bool $bypassDeletionRestriction = false,
		$rootSnapshot = null,
	)
	{
		if (!$bypassDeletionRestriction && $this->isDeletionRestricted($this->normalizeDeletedBy($deletedBy)))
		{
			$this->addDeletionRestrictedError($this);

			return false;
		}

		// The audience of the tombstone is taken before the rights it is read from are torn down; the
		// record itself waits for the row of the folder to be gone. A link is not the object it points
		// at, and the object goes on living without it.
		$publishesTombstone = !$this->journalCascadeChild && !$this->isLink();
		$tombstoneAudience = $publishesTombstone ? $this->captureTombstoneAudience() : null;

		// The codes a link carries are taken here for the same reason and by the same rule: they come
		// from the nearest right above it, and the rights of the link are torn down below.
		$placementAudience = $this->isLink()
			? (new PlacementAccessPublisher())->captureCodes((int)$this->getId())
			: []
		;

		$rootSnapshot ??= (new AuditSnapshotBuilder())->buildObjectSnapshot($this);
		$deleted = $this->runAuditCoveredMutation(function () use (
			$deletedBy,
			$bypassDeletionRestriction,
			$rootSnapshot,
			$publishesTombstone,
			$tombstoneAudience,
		): bool {
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

			SimpleRightTable::deleteBatch(array('OBJECT_ID' => $this->id));

			$success = RightTable::deleteByFilter(array(
				'OBJECT_ID' => $this->id,
			));

			if(!$success)
			{
				return false;
			}

			$resultDelete = FolderTable::delete($this->id);
			if(!$resultDelete->isSuccess())
			{
				return false;
			}

			if ($publishesTombstone)
			{
				$this->publishTombstoneToJournal($deletedBy, $tombstoneAudience);
			}

			$auditResult = (new AuditObjectEventProducer())->publishPermanentlyDeleted(
				$this,
				(int)$deletedBy,
				$bypassDeletionRestriction ? 'bypass' : 'object',
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

		Driver::getInstance()->getIndexManager()->dropIndex($this);
		Driver::getInstance()->getDeletedLogManager()->mark($this, $deletedBy);

		if($this->isLink())
		{
			// A link is a place the real object is seen at, and the journal is kept over real objects:
			// the place went, the object behind it stayed. The record is published inside a cascade too,
			// because the one record on the root of that cascade names an object of another audience.
			$this->publishToJournal(ChangeType::Updated, $deletedBy, ['places']);

			// What the link was a place of reaches below the folder, and the record above names the
			// folder alone. Left unsaid, the places of the descendants go on pointing at the parent of
			// a link that is no longer there - a path leading nowhere, which nothing else repairs.
			(new PlacementAccessPublisher())->publishSubtreeDisconnected(
				(int)$this->getRealObjectId(),
				$placementAudience,
			);
		}
		else
		{
			//todo potential - very hard operation.
			foreach(Folder::getModelList(array('filter' => array('REAL_OBJECT_ID' => $this->id))) as $link)
			{
				$link->deleteTree($deletedBy, $bypassDeletionRestriction);
			}
		}

		$event = new Event(Driver::INTERNAL_MODULE_ID, "onAfterDeleteFolder", array($this->getId(), $deletedBy));
		$event->send();

		return true;
	}

	private function findDeletionRestrictedFolderInTree(?int $deletedBy): ?self
	{
		$restrictionFilters = self::getDeletionRestriction()->getRestrictedFolderFilters($deletedBy);

		if (empty($restrictionFilters))
		{
			return null;
		}

		$filter = [
			'PATH_CHILD.PARENT_ID' => $this->id,
		];
		if (count($restrictionFilters) === 1)
		{
			$filter = array_merge($filter, $restrictionFilters[0]);
		}
		else
		{
			$filter[] = array_merge(
				['LOGIC' => 'OR'],
				$restrictionFilters,
			);
		}

		$parameters = [
			'select' => [
				'*',
				'DEPTH_LEVEL' => 'PATH_CHILD.DEPTH_LEVEL',
			],
			'filter' => $filter,
			'order' => ['DEPTH_LEVEL' => 'DESC'],
			'limit' => 1,
		];

		$objectRow = FolderTable::getList(static::prepareGetListParameters($parameters))->fetch();

		return $objectRow ? self::buildFromArray($objectRow) : null;
	}

	private function isDeletionRestricted(?int $deletedBy): bool
	{
		return self::getDeletionRestriction()->isRestricted($this, $deletedBy);
	}

	private function normalizeDeletedBy($deletedBy): ?int
	{
		if (!is_numeric($deletedBy))
		{
			return null;
		}

		return (int)$deletedBy;
	}

	private function addDeletionRestrictedError(self $folder): void
	{
		$this->errorCollection[] = new Error(
			Loc::getMessage(
				'DISK_FOLDER_MODEL_ERROR_COULD_NOT_DELETE_WITH_CODE',
				[
					'#CODE#' => $folder->getCode(),
				],
			),
			self::ERROR_COULD_NOT_DELETE_WITH_CODE,
		);
	}

	private static function getDeletionRestriction(): DeletionRestriction
	{
		if (self::$deletionRestriction === null)
		{
			self::$deletionRestriction = new DeletionRestriction();
		}

		return self::$deletionRestriction;
	}

	/**
	 * Returns size of all files with only head version.
	 * Without symbolic links.
	 *
	 * @param null $filter
	 *
	 * @return int|null
	 */
	public function getSize($filter = null)
	{
		$query = new Query(FileTable::getEntity());
		$query
			->registerRuntimeField('', new ExpressionField('FILE_SIZE', 'SUM(SIZE)'))
			->addSelect('FILE_SIZE')
			->addFilter('=PATH_CHILD.PARENT_ID', $this->getRealObjectId())
			->addFilter('=STORAGE_ID', $this->getStorageId())
			->addFilter('=TYPE', FileTable::TYPE)
			->addFilter('=DELETED_TYPE', FileTable::DELETED_TYPE_NONE)
		;

		$result = $query->exec();
		$row = $result->fetch();
		if (isset($row['FILE_SIZE']))
		{
			return $row['FILE_SIZE'];
		}

		return null;
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
			'HAS_SUBFOLDERS' => 'hasSubFolders',
		));

		return $shelve;
	}
}

/**
 * Class SpecificFolder
 * @package Bitrix\Disk
 * @internal
 */
final class SpecificFolder
{
	const CODE_FOR_CREATED_FILES  = 'FOR_CREATED_FILES';
	const CODE_FOR_SAVED_FILES    = 'FOR_SAVED_FILES';
	const CODE_FOR_UPLOADED_FILES = 'FOR_UPLOADED_FILES';
	const CODE_FOR_RECORDED_FILES = 'FOR_RECORDED_FILES';

	const CODE_FOR_IMPORT_DROPBOX  = 'FOR_DROPBOX_FILES';
	const CODE_FOR_IMPORT_ONEDRIVE = 'FOR_ONEDRIVE_FILES';
	const CODE_FOR_IMPORT_GDRIVE   = 'FOR_GDRIVE_FILES';
	const CODE_FOR_IMPORT_BOX      = 'FOR_BOX_FILES';
	const CODE_FOR_IMPORT_YANDEX   = 'FOR_YANDEXDISK_FILES';
	const CODE_FOR_MAIL_ATTACHMENTS   = 'FOR_MAIL_ATTACHMENTS';

	/**
	 * Gets name for specific folder by code. If code is invalid, then return null.
	 * @param string $code Code of specific folder.
	 * @return null|string
	 */
	public static function getName($code)
	{
		$codes = static::getCodes();
		if(!isset($codes[$code]))
		{
			return null;
		}
		return Loc::getMessage("DISK_FOLDER_SPECIFIC_{$code}_NAME");
	}

	public static function isSpecificFolder(Folder $folder, ?array $codes = null): bool
	{
		return self::isSpecificFolderCode($folder->getCode(), $codes);
	}

	public static function isSpecificFolderCode($code, ?array $codes = null): bool
	{
		if (!is_string($code))
		{
			return false;
		}

		$codes ??= self::getCodes();

		return isset($codes[$code]) || in_array($code, $codes, true);
	}

	/**
	 * Gets specific folder in storage by code. If folder does not exist, creates it.
	 * @param Storage $storage Target storage.
	 * @param string $code Code of specific folder.
	 * @return Folder|null|static
	 */
	public static function getFolder(Storage $storage, $code)
	{
		$folder = Folder::load(array(
			'=CODE' => $code,
			'STORAGE_ID' => $storage->getId(),
		));
		if($folder)
		{
			return $folder;
		}

		return static::createFolder($storage, $code);
	}

	protected static function createFolder(Storage $storage, $code)
	{
		$name = static::getName($code);
		if(!$name)
		{
			return null;
		}

		if($storage->getProxyType() instanceof ProxyType\User)
		{
			$createdBy = $storage->getEntityId();
		}
		else
		{
			$createdBy = SystemUser::SYSTEM_USER_ID;
		}

		if(static::shouldBeUnderUploadedFolder($code))
		{
			$folderForUploadedFiles = $storage->getFolderForUploadedFiles();
			if(!$folderForUploadedFiles)
			{
				return null;
			}
			return $folderForUploadedFiles->addSubFolder(array(
				'NAME' => $name,
				'CODE' => $code,
				'CREATED_BY' => $createdBy
			), array(), true);
		}

		return $storage->addFolder(array(
			'NAME' => $name,
			'CODE' => $code,
			'CREATED_BY' => $createdBy
		), array(), true);
	}

	protected static function shouldBeUnderUploadedFolder($code)
	{
		return
			static::CODE_FOR_IMPORT_DROPBOX === $code ||
			static::CODE_FOR_IMPORT_ONEDRIVE === $code ||
			static::CODE_FOR_IMPORT_YANDEX === $code ||
			static::CODE_FOR_IMPORT_BOX === $code ||
			static::CODE_FOR_IMPORT_GDRIVE === $code;
	}

	protected static function getCodes(): array
	{
		static $codes = null;
		if($codes !== null)
		{
			return $codes;
		}
		$refClass = new \ReflectionClass(__CLASS__);
		foreach($refClass->getConstants() as $name => $value)
		{
			if(mb_substr($name, 0, 4) === 'CODE')
			{
				$codes[$value] = $value;
			}
		}
		unset($name, $value);

        return $codes;
	}
}
