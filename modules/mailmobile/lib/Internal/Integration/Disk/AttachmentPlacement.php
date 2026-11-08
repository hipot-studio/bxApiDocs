<?php

declare(strict_types=1);

namespace Bitrix\MailMobile\Internal\Integration\Disk;

use Bitrix\Crm\Integration\StorageManager as CrmStorageManager;
use Bitrix\Crm\Integration\StorageType as CrmStorageType;
use Bitrix\Disk\Driver;
use Bitrix\Disk\File;
use Bitrix\Disk\Folder;
use Bitrix\Disk\Storage;
use Bitrix\Disk\Ui\Text;
use Bitrix\MailMobile\Public\Features\LargeAttachmentFeature;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Mobile\Config\Feature as MobileFeature;
use Bitrix\UI\FileUploader\Configuration;
use Bitrix\UI\FileUploader\FileInfo;
use Bitrix\UI\FileUploader\FileOwnershipCollection;
use Bitrix\UI\FileUploader\UploadResult;
use CFile;

/**
 * Attachments of mobile mail as objects of the Disk. Everything that talks to the Disk lives here: the
 * upload endpoint and the send path only ask for a placement and get identifiers of Disk objects back.
 *
 * The scenario of the large attachment accepts nothing but Disk objects, so the attachments of mobile mail
 * have to live there as well, for every attachment and not only for a heavy one.
 *
 * Creating and reading are two questions here, and only the first is behind the rollout flag of the module.
 * With the flag off no new object is created and attaching works as before, but the path of reading is
 * reachable on purpose: a form opened before the rollback, and a restored draft, still name objects that
 * exist and the sender may read, and refusing those would break the send of every one of them. So nothing
 * in this class may be trusted to be unreachable because of the flag - what an entry point is allowed to do
 * is decided by which of the two placements AttachmentPlacementFactory handed it.
 */
final class AttachmentPlacement
{
	/**
	 * A file of the Disk is named the way the file field of the application reports it and the way the draft
	 * channel of the mail module already reads it: the prefix of a new object (FileUserType::NEW_FILE_PREFIX
	 * of the disk module) plus the object id. The prefix is spelled out because the identifier is parsed on
	 * every send, including a portal without the disk module installed.
	 */
	private const OBJECT_REFERENCE_PREFIX = 'n';
	private const OBJECT_REFERENCE_PATTERN = '/^n([1-9]\d*)$/';

	/**
	 * The default of Bitrix\UI\FileUploader\Configuration at the time of writing, used only if the framework
	 * ever stops declaring one of its own: a single upload may never be left without an upper bound.
	 */
	private const FALLBACK_UPLOAD_SIZE_LIMIT = 256 * 1024 * 1024;

	/** @var array<int, bool> whether the personal Disk of a person can take a file, by user id */
	private array $targetWritableByUserId = [];

	/**
	 * The driver is handed in, not looked up: a caller that owns the request decides which one this adapter
	 * works with. AttachmentPlacementFactory is where the accessor of the disk module is called for the
	 * ordinary case.
	 */
	public function __construct(private readonly Driver $driver)
	{
	}

	public static function isAvailable(): bool
	{
		return self::isRolledOut() && self::isReachable();
	}

	/**
	 * Whether the Disk can be talked to at all. Asked apart from the rollout on purpose: switching the
	 * rollout off forbids putting anything new into the Disk, it does not take away the objects a form
	 * already holds identifiers of. Resolving one of those asks this and the permission model of the Disk,
	 * and nothing else.
	 */
	public static function isReachable(): bool
	{
		return Loader::includeModule('disk') && Loader::includeModule('ui');
	}

	/**
	 * The rollout flag of mobile mail alone. The tariff of the scenario is a separate answer of the mail
	 * module: storing an attachment in the Disk costs nothing extra, only sending it as a link does.
	 */
	private static function isRolledOut(): bool
	{
		return Loader::includeModule('mobile')
			&& class_exists(MobileFeature::class)
			&& MobileFeature::isEnabled(LargeAttachmentFeature::class)
		;
	}

	/**
	 * The object id behind an identifier reported by the client, or null when the identifier names no Disk
	 * object. Numbers are left alone on purpose: a bare number is the file of a replied message.
	 */
	public static function parseObjectReference(mixed $reference): ?int
	{
		if (!is_string($reference) || !preg_match(self::OBJECT_REFERENCE_PATTERN, $reference, $matches))
		{
			return null;
		}

		return (int)$matches[1];
	}

	public static function buildObjectReference(int $objectId): string
	{
		return self::OBJECT_REFERENCE_PREFIX . $objectId;
	}

	/**
	 * Whether the current user may put a file of the declared size into the folder the attachments go to.
	 * Whether the Disk of the person can take a file at all is asked first and separately, so a placement
	 * used past its gate still refuses instead of accepting unconditionally; a declared size past the single
	 * upload ceiling is rejected before the upload spends bandwidth and temporary space on it.
	 *
	 * The quota is not asked here: a chunked upload reports the size of its first chunk, not of the whole
	 * file, and every first chunk would recompute the sum of the storage for a size that is not even the
	 * one being stored. The full quota is guaranteed by place(), before and after the file is written.
	 */
	public function canAdd(int $declaredSize = 0): bool
	{
		if (!$this->isTargetWritable((int)CurrentUser::get()?->getId()))
		{
			return false;
		}

		return $declaredSize <= self::getSingleUploadCeiling();
	}

	/**
	 * Whether the personal Disk of the person can take a new attachment at all: a storage of their own, with
	 * the folder of uploaded files they may add to.
	 *
	 * This is a question about the person and not about one file, so the gate of the scenario asks it and
	 * chooses whether the placement is used at all. There are people who have no Disk of their own - someone
	 * added while the socialnetwork module was off never got a storage, a storage may fail to appear, and the
	 * right to add to one's own root may be taken away - and for them the whole scenario has to stay off, the
	 * way it stays off on a portal that cannot convert a set. Asked only per upload, this used to leave such a
	 * person with the ceiling of the Disk declared and every file refused.
	 *
	 * The answer is remembered per person for the life of the adapter: the receiver of the files asks it on
	 * every chunk of an upload, and it costs a load of the storage and of the folder. Nothing is written on
	 * the strength of it - place() asks the folder again, in the name of the owner of the uploaded file.
	 */
	public function isTargetWritable(int $userId): bool
	{
		return $this->targetWritableByUserId[$userId] ??= $this->readTargetWritable($userId);
	}

	private function readTargetWritable(int $userId): bool
	{
		[$folder, $storage] = $this->getTargetFolderAndStorage($userId);

		return $folder !== null
			&& $storage !== null
			&& $folder->canAdd($storage->getSecurityContext($userId))
		;
	}

	/**
	 * The largest single file this placement accepts: the ceiling of a single upload, lowered to what the
	 * storage could ever hold when its size is restricted. A heavy file is still allowed, that being the
	 * whole point of the scenario, but there is always an upper bound, and the temporary storage is not
	 * spent on a file the Disk could never keep anyway.
	 *
	 * This is the limit of a placement that is in use, so a storage of the person is there: whoever has none
	 * gets no placement, and the receiver of the files answers them with the limit of a message.
	 */
	public function getUploadSizeLimit(): int
	{
		$ceiling = self::getSingleUploadCeiling();

		[, $storage] = $this->getTargetFolderAndStorage((int)CurrentUser::get()?->getId());
		if ($storage === null || !$storage->isEnabledSizeLimitRestriction())
		{
			return $ceiling;
		}

		$storageLimit = (int)$storage->getSizeLimit();

		return $storageLimit > 0 ? min($ceiling, $storageLimit) : $ceiling;
	}

	/**
	 * The upper bound on a single upload of this scenario. The limit of a mail attachment cannot serve as it:
	 * the scenario exists to send files bigger than a message may carry. The cap the file uploader of the
	 * framework gives every receiver is taken instead, so this one keeps the bound the others have.
	 */
	private static function getSingleUploadCeiling(): int
	{
		$ceiling = (int)(new Configuration())->getMaxFileSize();

		return $ceiling > 0 ? $ceiling : self::FALLBACK_UPLOAD_SIZE_LIMIT;
	}

	/**
	 * Puts the uploaded file into the folder for uploaded files of the user and reports it back as an object
	 * of the Disk. The folder is not named by the caller: the default folder of the Disk receiver is the one
	 * the user sees their uploads in, and the system folder of mail attachments belongs to the conversion of
	 * the mail module alone.
	 */
	public function place(UploadResult $uploadResult): void
	{
		$tempFile = $uploadResult->getTempFile();
		if ($tempFile === null)
		{
			$uploadResult->addError(new Error('The uploaded file is gone'));

			return;
		}

		$userId = (int)$tempFile->getCreatedBy();
		[$folder, $storage] = $this->getTargetFolderAndStorage($userId);
		if ($folder === null || $storage === null || !$folder->canAdd($storage->getSecurityContext($userId)))
		{
			$uploadResult->addError(new Error('Access denied'));

			return;
		}

		if ((int)$tempFile->getSize() > self::getSingleUploadCeiling())
		{
			// The size the client declared for the whole file (TempFile::setSize of the first chunk), and the
			// first time the whole file meets the ceiling: canAdd was asked about the first chunk alone. The
			// declared size is enough, and it is the same number the framework keeps: TempFile::append refuses
			// a chunk that would take the file past it, and commit() registers exactly it in b_file.
			$uploadResult->addError(new Error('The file is too large'));
			$tempFile->delete();

			return;
		}

		if (!$storage->isPossibleToUpload((int)$tempFile->getSize()))
		{
			// Folder::addFile does not check the quota, so the size of the whole file is checked here right
			// before the placement: without it the receiver would store files past the quota of the portal,
			// and every repeat would spend more of it. It is the same declared size the object is registered
			// with below, so the quota can only be spent to the full of it and never short.
			$uploadResult->addError(new Error('Not enough space in the storage'));
			$tempFile->delete();

			return;
		}

		$diskFile = $folder->addFile(
			[
				'NAME' => Text::correctFilename($tempFile->getFilename()),
				'FILE_ID' => $tempFile->getFileId(),
				'SIZE' => $tempFile->getSize(),
				'CREATED_BY' => $userId,
			],
			[],
			true,
		);

		if (!$diskFile instanceof File)
		{
			$errors = $folder->getErrors();
			if (is_array($errors) && $errors !== [])
			{
				$uploadResult->addErrors($errors);
			}
			else
			{
				$uploadResult->addError(new Error('The file has not been saved'));
			}

			$tempFile->delete();

			return;
		}

		// Disk owns the content now. The uploader must not remove it if quota compensation fails.
		$tempFile->makePersistent();

		if (!$storage->isPossibleToUpload(0))
		{
			$uploadResult->addError(new Error('Not enough space in the storage'));

			try
			{
				$isDeleted = $diskFile->delete($userId);
			}
			catch (\Throwable)
			{
				$isDeleted = false;
			}

			if (!$isDeleted)
			{
				$errors = $diskFile->getErrors();
				if (is_array($errors) && $errors !== [])
				{
					$uploadResult->addErrors($errors);
				}
				else
				{
					$uploadResult->addError(new Error('The uploaded file could not be removed'));
				}
			}

			return;
		}

		$fileInfo = FileInfo::createFromBFile((int)$diskFile->getFileId());
		if ($fileInfo !== null)
		{
			$fileInfo->setId(self::buildObjectReference((int)$diskFile->getId()));
			$fileInfo->setName((string)$diskFile->getName());
			$fileInfo->setCustomData([
				'fileId' => (int)$diskFile->getId(),
				'objectId' => (int)$diskFile->getId(),
			]);
			$uploadResult->setFileInfo($fileInfo);
		}

	}

	/**
	 * The stored files the user is allowed to see among the ones an identifier of b_file was given for.
	 * Access is decided by the permission model of the Disk, not by the receiver of the files.
	 */
	public function verifyFileOwner(FileOwnershipCollection $files): void
	{
		$fileIds = [];
		foreach ($files as $fileOwnership)
		{
			$fileIds[] = $fileOwnership->getId();
		}

		$readableFileIds = $this->selectReadableFileIds($fileIds);
		foreach ($files as $fileOwnership)
		{
			if (isset($readableFileIds[$fileOwnership->getId()]))
			{
				$fileOwnership->markAsOwn();
			}
		}
	}

	/**
	 * The stored file behind every reported object of the Disk, by the object the caller named. An object the
	 * user may not read is left out, and so is one that lies in the trash can: the identifier comes from the
	 * client, so the send path may not take the ownership of the file for granted. A caller that gets fewer
	 * files back than it named objects refuses the send.
	 *
	 * @param int[] $objectIds
	 *
	 * @return array<int, int> stored file id by Disk object id
	 */
	public function resolveAttachments(array $objectIds, int $userId): array
	{
		return array_map(
			static fn(array $file): int => $file['fileId'],
			$this->resolveAttachmentFiles($objectIds, $userId),
		);
	}

	/**
	 * The same answer as resolveAttachments plus the name the object carries, in the same single batch load.
	 *
	 * The name is asked for because the name of the stored file is not the name of the attachment: an
	 * attachment of a received message is stored in b_file under the md5 of its name, while the object of the
	 * module storage the attachment is registered as keeps the real one (Mail\Helper\Attachment\Storage). So a
	 * message assembled out of stored names alone hands the recipient a file called after a hash.
	 *
	 * @param int[] $objectIds
	 *
	 * @return array<int, array{fileId: int, name: string}> by Disk object id
	 */
	public function resolveAttachmentFiles(array $objectIds, int $userId): array
	{
		$uniqueObjectIds = array_values(array_filter(
			array_unique(array_map('intval', $objectIds)),
			static fn(int $objectId): bool => $objectId > 0,
		));

		if ($uniqueObjectIds === [])
		{
			return [];
		}

		// One batch load instead of a query per object, and only an object that carries a stored file is
		// asked about: the batch load of the Disk, unlike the load of a single file, builds a model out of
		// every row it reads, and a folder has no stored file to attach anyway.
		$files = File::getModelList([
			'select' => ['*'],
			'filter' => [
				'@ID' => $uniqueObjectIds,
				'>FILE_ID' => 0,
			],
			'with' => ['STORAGE'],
		]);

		$resolvedByObjectId = [];
		foreach ($files as $file)
		{
			if (!$file instanceof File || $file->isDeleted())
			{
				continue;
			}

			$storage = $file->getStorage();
			if ($storage === null || !$file->canRead($storage->getSecurityContext($userId)))
			{
				continue;
			}

			$fileId = (int)$file->getFileId();
			if ($fileId > 0)
			{
				$resolvedByObjectId[(int)$file->getId()] = [
					'fileId' => $fileId,
					'name' => (string)$file->getName(),
				];
			}
		}

		return $resolvedByObjectId;
	}

	/**
	 * Creates template-owned Disk objects over independent b_file rows. Draft cleanup deletes every object
	 * backed by the draft file id, so a second Disk object over the same row would not survive it.
	 *
	 * @param list<int> $objectIds
	 * @return array{diskObjectIds: list<int>, objectIdMap: array<int, int>}|null
	 */
	public function copyForTemplate(array $objectIds, int $userId): ?array
	{
		if ($objectIds === [])
		{
			return [
				'diskObjectIds' => [],
				'objectIdMap' => [],
			];
		}

		$copiedObjectIds = [];
		$objectIdMap = [];
		foreach ($objectIds as $sourceObjectId)
		{
			$copiedFileId = 0;
			try
			{
				$sourceFile = File::loadById((int)$sourceObjectId);
				if (!$sourceFile instanceof File || $sourceFile->isDeleted())
				{
					$this->removeTemplateCopies($copiedObjectIds);

					return null;
				}

				$copiedFileId = (int)CFile::CloneFile((int)$sourceFile->getFileId());
				$copiedFile = $copiedFileId > 0 ? CFile::GetFileArray($copiedFileId) : null;
				if (!is_array($copiedFile))
				{
					if ($copiedFileId > 0)
					{
						CFile::Delete($copiedFileId);
					}
					$this->removeTemplateCopies($copiedObjectIds);

					return null;
				}

				$copiedFile['ORIGINAL_NAME'] = (string)$sourceFile->getName();
				$copiedObjectId = (int)CrmStorageManager::saveEmailAttachment(
					$copiedFile,
					CrmStorageType::Disk,
					'',
					['USER_ID' => $userId],
				);
				if ($copiedObjectId <= 0)
				{
					CFile::Delete($copiedFileId);
					$this->removeTemplateCopies($copiedObjectIds);

					return null;
				}
			}
			catch (\Throwable)
			{
				if ($copiedFileId > 0)
				{
					CFile::Delete($copiedFileId);
				}
				$this->removeTemplateCopies($copiedObjectIds);

				return null;
			}

			$copiedObjectIds[] = $copiedObjectId;
			$objectIdMap[(int)$sourceObjectId] = $copiedObjectId;
		}

		return [
			'diskObjectIds' => $copiedObjectIds,
			'objectIdMap' => $objectIdMap,
		];
	}

	/** @param list<int> $objectIds */
	public function removeTemplateCopies(array $objectIds): void
	{
		foreach ($objectIds as $objectId)
		{
			try
			{
				CrmStorageManager::deleteFile($objectId, CrmStorageType::Disk);
			}
			catch (\Throwable)
			{
			}
		}
	}

	/**
	 * Removes objects of the target folder. Nothing of the send path creates one any more - an attachment of a
	 * quote is attached from the file of the message it was read from, the way the web does it - so this is a
	 * guard kept for whatever puts a copy there next: an object that never left with a sent message would stay
	 * visible and keep eating the quota, and every retry would add another one.
	 *
	 * File::delete() enforces no permission of its own (it only guards an exclusive lock), so the right to
	 * delete is asked here before it: an object the user may not delete is left untouched and reported back as
	 * a failure. An object the request itself just created is deletable by the user who created it; a foreign
	 * object handed in from elsewhere is refused here.
	 *
	 * The objects come in one batch load together with their storages, the way resolveAttachmentFiles does it,
	 * so a pack of a hundred is read in a single query instead of one per object. The identifiers reach this
	 * method only from a source the server owns, so none of them is a folder; the batch still keeps a folder
	 * out through the same '>FILE_ID' filter, and an object already gone is left out of the batch and counts as
	 * removed. The right to delete and the delete itself stay per object.
	 *
	 * @param int[] $objectIds
	 *
	 * @return int[] object ids that could not be removed
	 */
	public function removeCreatedObjects(array $objectIds, int $userId): array
	{
		$objectIds = array_values(array_filter(
			array_unique(array_map('intval', $objectIds)),
			static fn(int $objectId): bool => $objectId > 0,
		));

		if ($userId <= 0)
		{
			return $objectIds;
		}

		if ($objectIds === [])
		{
			return [];
		}

		try
		{
			$files = File::getModelList([
				'select' => ['*'],
				'filter' => [
					'@ID' => $objectIds,
					'>FILE_ID' => 0,
				],
				'with' => ['STORAGE'],
			]);
		}
		catch (\Throwable)
		{
			return $objectIds;
		}

		$fileByObjectId = [];
		foreach ($files as $file)
		{
			if ($file instanceof File && !$file->isDeleted())
			{
				$fileByObjectId[(int)$file->getId()] = $file;
			}
		}

		$failedObjectIds = [];
		foreach ($objectIds as $objectId)
		{
			$file = $fileByObjectId[$objectId] ?? null;
			if ($file === null)
			{
				// gone already, a folder, or trashed: nothing of ours is left to drop
				continue;
			}

			try
			{
				$securityContext = $file->getStorage()?->getSecurityContext($userId);
				if ($securityContext === null || !$file->canDelete($securityContext))
				{
					$failedObjectIds[] = $objectId;

					continue;
				}

				if (!$file->delete($userId))
				{
					$failedObjectIds[] = $objectId;
				}
			}
			catch (\Throwable)
			{
				$failedObjectIds[] = $objectId;
			}
		}

		return $failedObjectIds;
	}

	/**
	 * @param int[] $fileIds
	 *
	 * @return array<int, true> readable stored file ids as keys
	 */
	private function selectReadableFileIds(array $fileIds): array
	{
		$fileIds = array_values(array_filter(array_map('intval', $fileIds), static fn(int $id): bool => $id > 0));
		if ($fileIds === [])
		{
			return [];
		}

		// One stored file may stand behind several objects of the Disk, and it is readable as soon as one of
		// them is. The objects come in one batch load together with their storages, the way resolveAttachments
		// does it, so nothing is read again per object; there is no batch answer about rights, so those are
		// still asked object by object. The batch load, unlike the load of a single file, builds a model out
		// of every row it reads, hence the query asks only about an object that carries a stored file and the
		// model is checked once more: a folder has no stored file, and an object in the trash can is dropped.
		$files = File::getModelList([
			'select' => ['*'],
			'filter' => [
				'@FILE_ID' => $fileIds,
				'>FILE_ID' => 0,
			],
			'with' => ['STORAGE'],
		]);

		$readable = [];
		foreach ($files as $file)
		{
			if (!$file instanceof File || $file->isDeleted())
			{
				continue;
			}

			$storage = $file->getStorage();
			if ($storage !== null && $file->canRead($storage->getCurrentUserSecurityContext()))
			{
				$readable[(int)$file->getFileId()] = true;
			}
		}

		return $readable;
	}

	/**
	 * @return array{0: Folder|null, 1: Storage|null}
	 */
	private function getTargetFolderAndStorage(int $userId): array
	{
		if ($userId <= 0)
		{
			return [null, null];
		}

		$storage = $this->driver->getStorageByUserId($userId);
		$folder = $storage?->getFolderForUploadedFiles();

		return [$folder instanceof Folder ? $folder : null, $storage];
	}
}
