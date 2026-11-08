<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\Disk;

use Bitrix\Crm\Integration\Disk\HiddenStorage;
use Bitrix\Crm\Integration\StorageFileType;
use Bitrix\Crm\Integration\StorageType;
use Bitrix\Crm\V2\Internal\Entity\Activity\ValueObject\ActivityFile;
use Bitrix\Disk\File;
use Bitrix\Disk\Folder;
use Bitrix\Disk\SpecificFolder;
use Bitrix\Disk\SystemUser;
use Bitrix\Main\FileTable;
use Bitrix\Main\Loader;
use Bitrix\Main\Web\Uri;
use CCrmOwnerType;
use CCrmUrlUtil;
use CFile;

/**
 * The storage side of an activity: the files it leaves behind when it is deleted (LBS-1 section 5.3 step 8,
 * `CCrmActivity::DoDeleteStorageElements()` at crm_activity.php:2321-2375) and the metadata a read publishes
 * about them.
 *
 * TWO DIRECTIONS, ONE BOUNDARY. The step of the delete pipeline that removes the files of an activity calls
 * {@see deleteElements()} once, exactly where legacy calls its counterpart; {@see getFiles()} answers a read
 * and writes nothing whatever. The gate of the delete step - the option that keeps the files, and the
 * activity moved to the recycle bin that raises it - stays in the pipeline, because it is a condition of the
 * operation rather than knowledge about a storage. The rows of `b_crm_act_elem` are not here either: they
 * are a table of CRM and belong to its repository.
 *
 * WHICH FILE MAY BE DELETED AT ALL. A file of the disk is removed only when it sits in a service folder of
 * CRM - the folder of email attachments, of call records, of the REST applications, the folder of uploaded
 * files or the hidden folder of activities - AND nothing else is attached to it. Everything else is left
 * alone: the same file may hang on a message, on a timeline record or on an entity of a different module,
 * and a file the layer cannot resolve is skipped instead of guessed about. A file of the core storage
 * (`StorageType::File`) carries no such structure and is deleted as it is.
 *
 * WHAT CROSSES THE BOUNDARY. Identifiers and the storage type of CRM ({@see StorageType}) go in, never a
 * file, a folder or a storage of the disk. What comes back is how many elements were removed - the number a
 * test can read and a step is free to ignore, as legacy ignores it - or the neutral {@see ActivityFile}
 * metadata of a read, which names nothing of the disk either.
 *
 * THE DISK IS AN OPTIONAL DEPENDENCY, and here it is a stricter statement than legacy makes: legacy calls
 * `Loader::includeModule('disk')` and drops the answer, so a portal without the module would fail on the
 * very next line. Reaching that line needs elements of a storage only the disk itself creates, which is why
 * the branch is unreachable rather than working - and why the guard below is a guard and not a change of
 * behaviour. THE CLASS IS NOT FINAL for that branch: {@see isDiskAvailable()} is the seam its test needs,
 * and it is the form the sample of this layer uses (`Internal\Integration\Sale\LocationDictionary`). The two
 * readers of a storage, {@see readFilesOfTheDisk()} and {@see readFilesOfTheCore()}, are seams of the same
 * kind: what a read makes of what a storage answered is pinned without a portal behind it.
 *
 * @internal
 */
class ActivityStorageElements
{
	private const MODULE_ID = 'disk';

	/** The entry point of CRM that reads out a file of the disk attached to an activity. */
	private const FILE_OF_AN_ACTIVITY_PATH = '/bitrix/tools/crm_show_file.php';

	/**
	 * Removes the files of an activity that is being deleted.
	 *
	 * @param int $storageTypeId storage the elements belong to, {@see StorageType}.
	 * @param int[] $storageElementIds elements of that storage, as the activity carries them.
	 * @return int the number of elements that were removed.
	 */
	public function deleteElements(int $storageTypeId, array $storageElementIds): int
	{
		$elementIds = self::positiveIdsOf($storageElementIds);
		if ($elementIds === [])
		{
			return 0;
		}

		if ($storageTypeId === StorageType::File)
		{
			return self::deleteFilesOfTheCore($elementIds);
		}

		if ($storageTypeId === StorageType::Disk)
		{
			return $this->deleteFilesOfTheDisk($elementIds);
		}

		// A storage of the WebDav era, or none at all: legacy removes nothing there and neither does this.
		return 0;
	}

	/**
	 * Metadata of the files of an activity, in the order of the given elements.
	 *
	 * THE RIGHT OVER THE FILES IS THE RIGHT OVER THE ACTIVITY, as it is in legacy. Whoever reads the activity
	 * reads its files: `ActivityAccess` has settled that right before this layer is reached, and no second right
	 * of a storage is asked on top of it - `CCrmActivityRestProxy::externalizeFields()` asks none either. The
	 * addresses of a file of the disk therefore lead through CRM ({@see addressOfTheFileOfTheDisk()}), which
	 * settles that same right again whenever one of them is opened.
	 *
	 * AN ELEMENT THE STORAGE NO LONGER HAS IS LEFT OUT: a read answers with the files that are there rather
	 * than with placeholders for the rest, because a name and an address are exactly what the caller asked for.
	 * Nothing is written on the way - no storage is migrated and no element is created - so a read of the files
	 * of an activity leaves the activity as it was.
	 *
	 * @param int $activityId the activity the elements belong to; it is the owner its addresses name.
	 * @param int $storageTypeId storage the elements belong to, {@see StorageType}.
	 * @param int[] $storageElementIds elements of that storage, as the activity carries them.
	 * @return ActivityFile[]
	 */
	public function getFiles(int $activityId, int $storageTypeId, array $storageElementIds): array
	{
		$elementIds = self::positiveIdsOf($storageElementIds);
		if ($elementIds === [])
		{
			return [];
		}

		$metadata = match ($storageTypeId)
		{
			StorageType::File => $this->readFilesOfTheCore($elementIds),
			StorageType::Disk => $this->readFilesOfTheDisk($elementIds, $activityId),
			// A storage of the WebDav era, or none at all: legacy reads nothing there and neither does this.
			default => [],
		};

		$files = [];
		foreach ($elementIds as $elementId)
		{
			if (isset($metadata[$elementId]))
			{
				$files[] = $metadata[$elementId];
			}
		}

		return $files;
	}

	/**
	 * Whether the disk is installed.
	 */
	protected function isDiskAvailable(): bool
	{
		return Loader::includeModule(self::MODULE_ID);
	}

	/**
	 * The files of the disk by element id, in one query whatever the number of the files. No right of the disk
	 * is asked: the right over these files is the right over the activity, and it is settled already.
	 *
	 * @param int[] $elementIds
	 * @return array<int, ActivityFile>
	 */
	protected function readFilesOfTheDisk(array $elementIds, int $activityId): array
	{
		if (!$this->isDiskAvailable())
		{
			return [];
		}

		$files = [];
		foreach (File::loadBatchById($elementIds) as $file)
		{
			$elementId = (int)$file->getId();
			$address = self::addressOfTheFileOfTheDisk($elementId, $activityId);

			$files[$elementId] = new ActivityFile(
				$elementId,
				(string)$file->getName(),
				$address,
				$address,
			);
		}

		return $files;
	}

	/**
	 * Where a file of the disk attached to an activity is read from: the entry point of CRM
	 * (`CCrmFileProxy::WriteDiskFileToResponse()`), named the owner it belongs to, exactly as
	 * `CCrmActivity::PrepareStorageElementInfo()` names it through `DiskManager::getFileInfo()`.
	 *
	 * THE OWNER IS THE POINT OF THE ADDRESS. CRM resolves it to the bindings of the activity and lets through
	 * whoever may read one of them; an address without it would either name a storage of the disk, whose rights
	 * are somebody else's, or lead nowhere.
	 *
	 * ONE ADDRESS SHOWS THE FILE AND HANDS IT OVER, as one does for the core storage: the entry point writes
	 * the file out in one way only and has no second address to offer.
	 */
	private static function addressOfTheFileOfTheDisk(int $elementId, int $activityId): string
	{
		$address = new Uri(self::FILE_OF_AN_ACTIVITY_PATH);
		$address->addParams([
			'fileId' => $elementId,
			'ownerTypeId' => CCrmOwnerType::Activity,
			'ownerId' => $activityId,
		]);

		return $address->getUri();
	}

	/**
	 * The files of the core storage by element id, in one query.
	 *
	 * The storage names one address, which both shows the file and hands it over, so the two addresses of a
	 * file are the same one here. It carries no rights of its own either: a file of it is readable by
	 * whoever can reach the activity. The address is encoded segment by segment, as legacy encodes it
	 * (`CCrmActivity::PrepareStorageElementInfo()` at crm_activity.php:4535): a name holding a space, a `#`
	 * or a letter outside ASCII would otherwise reach the caller as an address it cannot open.
	 *
	 * @param int[] $elementIds
	 * @return array<int, ActivityFile>
	 */
	protected function readFilesOfTheCore(array $elementIds): array
	{
		$rows = FileTable::getList([
			'select' => ['*'],
			'filter' => ['@ID' => $elementIds],
		])->fetchAll();

		$files = [];
		foreach ($rows as $row)
		{
			$elementId = (int)$row['ID'];
			$source = (string)CCrmUrlUtil::UrnEncode((string)CFile::GetFileSRC($row));
			$source = $source === '' ? null : $source;

			$files[$elementId] = new ActivityFile(
				$elementId,
				(string)(($row['ORIGINAL_NAME'] ?? '') ?: ($row['FILE_NAME'] ?? '')),
				$source,
				$source,
			);
		}

		return $files;
	}

	/**
	 * @param int[] $elementIds
	 */
	private function deleteFilesOfTheDisk(array $elementIds): int
	{
		if (!$this->isDiskAvailable())
		{
			return 0;
		}

		$deleted = 0;
		foreach ($elementIds as $elementId)
		{
			$file = File::loadById($elementId);
			if ($file === null || !self::isDeletableWithTheActivity($file))
			{
				continue;
			}

			$file->delete(SystemUser::SYSTEM_USER_ID);
			++$deleted;
		}

		return $deleted;
	}

	private static function isDeletableWithTheActivity(File $file): bool
	{
		$folder = $file->getParent();
		if (!($folder instanceof Folder))
		{
			return false;
		}

		return self::isServiceFolder($folder) && (int)$file->countAttachedObjects() === 0;
	}

	private static function isServiceFolder(Folder $folder): bool
	{
		if (
			isset(self::serviceFolderXmlIds()[(string)$folder->getXmlId()])
			|| $folder->getCode() === SpecificFolder::CODE_FOR_UPLOADED_FILES
		)
		{
			return true;
		}

		$parentFolder = $folder->getParent();

		return $parentFolder !== null && $parentFolder->getCode() === HiddenStorage::FOLDER_CODE_ACTIVITY;
	}

	/**
	 * Folders of CRM whose files belong to the activity that put them there.
	 *
	 * @return array<string, true>
	 */
	private static function serviceFolderXmlIds(): array
	{
		return [
			StorageFileType::getFolderXmlID(StorageFileType::EmailAttachment) => true,
			StorageFileType::getFolderXmlID(StorageFileType::CallRecord) => true,
			StorageFileType::getFolderXmlID(StorageFileType::Rest) => true,
		];
	}

	/**
	 * @param int[] $elementIds
	 */
	private static function deleteFilesOfTheCore(array $elementIds): int
	{
		foreach ($elementIds as $elementId)
		{
			CFile::Delete($elementId);
		}

		return count($elementIds);
	}

	/**
	 * @param array<int|string, mixed> $storageElementIds
	 * @return int[]
	 */
	private static function positiveIdsOf(array $storageElementIds): array
	{
		$elementIds = [];
		foreach ($storageElementIds as $storageElementId)
		{
			$elementId = (int)$storageElementId;
			if ($elementId > 0)
			{
				$elementIds[] = $elementId;
			}
		}

		return array_values(array_unique($elementIds));
	}
}
