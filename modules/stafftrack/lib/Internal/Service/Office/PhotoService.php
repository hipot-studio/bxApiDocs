<?php

namespace Bitrix\StaffTrack\Internal\Service\Office;

use Bitrix\Disk\Driver;
use Bitrix\Disk\File;
use Bitrix\Disk\Folder;
use Bitrix\Disk\SystemUser;
use Bitrix\Disk\Ui\Text;
use Bitrix\Main\Application;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\StaffTrack\Access\Model\OfficeModel;
use Bitrix\StaffTrack\Access\OfficeAccessController;
use Bitrix\StaffTrack\Access\OfficeAction;
use Bitrix\StaffTrack\Dictionary\OfficeErrorCode;
use Bitrix\StaffTrack\Internal\Integration\Disk\Office\ProxyType;
use Bitrix\StaffTrack\Internal\Integration\Disk\Office\StorageProvider;
use Bitrix\StaffTrack\Internal\Integration\Disk\Office\UploadController;
use Bitrix\StaffTrack\Internal\Model\OfficePhoto;
use Bitrix\StaffTrack\Internal\Repository\OfficePhotoRepository;
use Bitrix\StaffTrack\Internal\Repository\OfficeRepository;
use Bitrix\StaffTrack\Item;
use Bitrix\UI\FileUploader\PendingFileCollection;
use Bitrix\UI\FileUploader\Uploader;
use CFile;

/**
 * Photos of an office: the step between the tokens a client gets from the uploader and the rows the
 * office card is built from. A photo is added and removed on its own - the set is never rewritten as a
 * whole (I-07 BRIEF), so simultaneous edits of different photos do not overwrite each other.
 *
 * The file belongs to the module and not to whoever uploaded it: it outlives the personal disk of its
 * author and dies with the office (I-09 BRIEF). Nothing but the identifier of the file is kept in a
 * row - the links a client follows are built by the disk on every read.
 *
 * The right to change the photos of an office is asked here rather than at the transport: the ajax
 * controller in front of this service only answers faster, it does not answer instead.
 *
 * What a unit test cannot raise - the disk, the storage of the module and the uploader of the
 * interface module - is reached through the protected methods below and replaced there.
 */
class PhotoService
{
	/** No more than ten photos per office (AC-013 BRIEF). */
	public const MAX_PHOTOS = 10;

	/** The codes of the office directory: the same answers its card operations give. */
	public const ERROR_ACCESS_DENIED = OfficeErrorCode::ACCESS_DENIED;
	public const ERROR_OFFICE_NOT_FOUND = OfficeErrorCode::NOT_FOUND;

	public const ERROR_PHOTO_NOT_FOUND = 'STAFFTRACK_OFFICE_PHOTO_NOT_FOUND';
	public const ERROR_LIMIT_REACHED = 'STAFFTRACK_OFFICE_PHOTO_LIMIT_REACHED';
	public const ERROR_SAVE_FAILED = 'STAFFTRACK_OFFICE_PHOTO_SAVE_FAILED';
	public const ERROR_DELETE_FAILED = 'STAFFTRACK_OFFICE_PHOTO_DELETE_FAILED';

	private const PREVIEW_WIDTH = 640;
	private const PREVIEW_HEIGHT = 640;

	protected OfficeRepository $officeRepository;
	protected OfficePhotoRepository $officePhotoRepository;

	public function __construct()
	{
		$this->officeRepository = new OfficeRepository();
		$this->officePhotoRepository = new OfficePhotoRepository();
	}

	public function getPhotos(int $officeId): Item\Collection\OfficePhotoCollection
	{
		return $this->buildPhotos($this->officePhotoRepository->getListByOfficeId($officeId));
	}

	/**
	 * The photos of many offices at once: the rows of them all come in one query and the files behind
	 * those rows in one more, so a directory of any size costs the same two.
	 *
	 * @param int[] $officeIds
	 * @return array<int, Item\Collection\OfficePhotoCollection> Keyed by office, empty collection included.
	 */
	public function getPhotosByOfficeIds(array $officeIds): array
	{
		$rows = $this->officePhotoRepository->getListByOfficeIds($officeIds);
		$links = $this->loadFileLinks($this->collectFileIds($rows));

		$itemsByOffice = [];
		foreach ($rows as $row)
		{
			$photo = $this->buildPhoto($row, $links);
			if ($photo !== null)
			{
				$itemsByOffice[(int)$row->getOfficeId()][] = $photo;
			}
		}

		$photosByOffice = [];
		foreach ($officeIds as $officeId)
		{
			$photosByOffice[$officeId] = new Item\Collection\OfficePhotoCollection(
				$itemsByOffice[$officeId] ?? [],
			);
		}

		return $photosByOffice;
	}

	/**
	 * Takes the files a client uploaded and hangs them on an office. The answer carries the photos of
	 * the office as they are after the addition, so a client that added a file and a client that lost
	 * the race both end up looking at the same set.
	 *
	 * @param string[] $photoTokens Tokens of the uploader, not identifiers of disk files.
	 */
	public function addPhotos(int $officeId, array $photoTokens, int $userId): Result
	{
		$result = new Result();

		if (!$this->canManagePhotos($officeId, $userId))
		{
			return $result->addError($this->getAccessDeniedError());
		}

		if ($this->officeRepository->getById($officeId) === null)
		{
			return $result->addError(new Error('Office not found', self::ERROR_OFFICE_NOT_FOUND));
		}

		$tokens = $this->normalizeTokens($photoTokens);
		if ($tokens === [])
		{
			return $result->setData(['photos' => $this->getPhotos($officeId)]);
		}

		// The count is read beside the insert and not under a lock, so two simultaneous additions both
		// see the same number and may take the office past the limit - accepted, see O-09 BRIEF. The
		// tokens are counted before the files are moved: a refused addition leaves the storage clean.
		if ($this->officePhotoRepository->countByOfficeId($officeId) + count($tokens) > self::MAX_PHOTOS)
		{
			return $result->addError(new Error(
				'An office holds no more than ' . self::MAX_PHOTOS . ' photos',
				self::ERROR_LIMIT_REACHED,
			));
		}

		$storeResult = $this->storeUploadedFiles($tokens, $userId);
		if (!$storeResult->isSuccess())
		{
			return $result->addErrors($storeResult->getErrors());
		}

		$saveResult = $this->saveRows($officeId, $storeResult->getData()['fileIds'], $userId);
		if (!$saveResult->isSuccess())
		{
			return $result->addErrors($saveResult->getErrors());
		}

		return $result->setData(['photos' => $this->getPhotos($officeId)]);
	}

	/**
	 * Removes one photo of an office and the file behind it, leaving the rest of the set alone (AC-015
	 * BRIEF). The photo is looked up within its office: an identifier of a photo of another office is
	 * as good as an identifier of no photo at all.
	 *
	 * The file leaves the storage once its row is gone, so a storage that refuses to let it go is logged
	 * rather than answered with: the photo is already removed, and a refusal would only send the client
	 * back for a photo nobody has any more.
	 */
	public function deletePhoto(int $officeId, int $photoId, int $userId): Result
	{
		$result = new Result();

		if (!$this->canManagePhotos($officeId, $userId))
		{
			return $result->addError($this->getAccessDeniedError());
		}

		$photo = $this->findPhoto($officeId, $photoId);
		if ($photo === null)
		{
			return $result->addError(new Error('Office photo not found', self::ERROR_PHOTO_NOT_FOUND));
		}

		$deleteResult = $this->officePhotoRepository->delete($photoId);
		if (!$deleteResult->isSuccess())
		{
			$this->logFailure(__FUNCTION__, implode('; ', $deleteResult->getErrorMessages()));

			return $result->addError(new Error('Failed to remove the photo', self::ERROR_DELETE_FAILED));
		}

		$this->discardFiles([(int)$photo->getFileId()]);

		return $result;
	}

	/**
	 * @param int[] $fileIds
	 */
	public function deleteFiles(array $fileIds): void
	{
		foreach ($fileIds as $fileId)
		{
			$this->deleteStoredFile((int)$fileId);
		}
	}

	/**
	 * The right of the circle of custody, asked of the office the photo hangs on: changing the photos
	 * of an office is changing its card.
	 */
	protected function canManagePhotos(int $officeId, int $userId): bool
	{
		return OfficeAccessController::getInstance($userId)
			->check(OfficeAction::UPDATE, OfficeModel::createFromId($officeId))
		;
	}

	/**
	 * A file leaves the storage on behalf of the system user and without asking the access model of
	 * the storage. The right has already been answered by the domain - by the command that removed the
	 * office or by the removal of the photo above - while the access model computes every answer from
	 * the circle of custody, which the system user is not in: asking it here would keep every file of
	 * every closed office forever.
	 *
	 * What is still asked is whether the file belongs to the module: an identifier reaches this method
	 * from a row of the photo table, and a foreign file is not ours to remove.
	 */
	protected function deleteStoredFile(int $fileId): void
	{
		if ($fileId <= 0 || !Loader::includeModule('disk'))
		{
			return;
		}

		$file = File::getById($fileId);
		if ($file === null || !$this->isOwnFile($file))
		{
			return;
		}

		if (!$file->delete(SystemUser::SYSTEM_USER_ID))
		{
			$this->logFailure(__FUNCTION__, 'file ' . $fileId . ' stays in the storage');
		}
	}

	/**
	 * Moves the uploaded files into the storage of the module. The folder of the upload is not the one
	 * the check-in flow picks: a photo of an office goes to the storage of the module and never to the
	 * personal disk of the employee who sent it.
	 *
	 * A photo that did not make it is a refusal and not a smaller answer. A token the uploader will not
	 * unwrap - forged, expired or never finished uploading - and a file the storage would not take are
	 * both invisible in the set of photos a client reads back afterwards, so neither is allowed to pass
	 * as success: the batch reaches the storage whole or the caller hears that it did not.
	 *
	 * @param string[] $tokens
	 * @return Result Data: fileIds - identifiers of the files as the storage of the module keeps them.
	 */
	protected function storeUploadedFiles(array $tokens, int $userId): Result
	{
		$result = new Result();

		if (!Loader::includeModule('ui'))
		{
			return $result->addError(new Error('Module "ui" is not installed', self::ERROR_SAVE_FAILED));
		}

		$pendingFiles = $this->getPendingFiles($tokens);

		$fileIds = $pendingFiles->getFileIds();
		if (count($fileIds) !== count($tokens))
		{
			$this->logFailure(__FUNCTION__, $this->describeRejectedTokens($pendingFiles));

			return $result->addError($this->getStoreFailedError());
		}

		$storedFileIds = $this->moveFilesToStorage($fileIds, $userId);
		if (count($storedFileIds) !== count($fileIds))
		{
			// What did get in goes back out, so a refused addition leaves nothing behind to collect.
			$this->deleteFiles($storedFileIds);

			return $result->addError($this->getStoreFailedError());
		}

		$pendingFiles->makePersistent();

		return $result->setData(['fileIds' => $storedFileIds]);
	}

	/**
	 * The tokens are unwrapped by the controller that issued them.
	 *
	 * @param string[] $tokens
	 */
	protected function getPendingFiles(array $tokens): PendingFileCollection
	{
		return (new Uploader(new UploadController([])))->getPendingFiles($tokens);
	}

	/**
	 * @param int[] $fileIds
	 * @return int[] The files the storage took, in the order they were given: a shorter answer than the
	 *               batch means the storage refused one of them and the rest were not attempted.
	 */
	protected function moveFilesToStorage(array $fileIds, int $userId): array
	{
		$folder = $this->getStorageFolder();
		if ($folder === null)
		{
			$this->logFailure(__FUNCTION__, 'the office photo storage could not be obtained');

			return [];
		}

		return $this->addFilesToFolder($folder, $fileIds, $userId);
	}

	/**
	 * @param int[] $fileIds
	 * @return array<int, array{url: string, previewUrl: string}> Keyed by the identifier of the file.
	 */
	protected function loadFileLinks(array $fileIds): array
	{
		if ($fileIds === [] || !Loader::includeModule('disk'))
		{
			return [];
		}

		$urlManager = Driver::getInstance()->getUrlManager();

		$links = [];
		/** @var File $file */
		foreach (File::loadBatchById($fileIds) as $file)
		{
			$links[(int)$file->getId()] = [
				'url' => $urlManager->getUrlForShowFile($file),
				'previewUrl' => $urlManager->getUrlForShowFile($file, [
					'width' => self::PREVIEW_WIDTH,
					'height' => self::PREVIEW_HEIGHT,
				]),
			];
		}

		return $links;
	}

	private function getStorageFolder(): ?Folder
	{
		return (new StorageProvider())->getStorage()?->getRootObject();
	}

	/**
	 * @param OfficePhoto[] $rows
	 */
	private function buildPhotos(array $rows): Item\Collection\OfficePhotoCollection
	{
		$links = $this->loadFileLinks($this->collectFileIds($rows));

		$photos = [];
		foreach ($rows as $row)
		{
			$photo = $this->buildPhoto($row, $links);
			if ($photo !== null)
			{
				$photos[] = $photo;
			}
		}

		return new Item\Collection\OfficePhotoCollection($photos);
	}

	/**
	 * A client is answered with the identifier of the row and two links to the file: the identifier of
	 * the file itself is of no use to it and never leaves the module.
	 *
	 * @param array<int, array{url: string, previewUrl: string}> $links
	 */
	private function buildPhoto(OfficePhoto $row, array $links): ?Item\OfficePhoto
	{
		$link = $links[(int)$row->getFileId()] ?? null;
		if ($link === null)
		{
			// The file is no longer in the storage: a row pointing at nothing is nothing to show.
			return null;
		}

		return new Item\OfficePhoto(
			id: (int)$row->getId(),
			url: $link['url'],
			previewUrl: $link['previewUrl'],
		);
	}

	/**
	 * The office is looked up a second time here, and this time while holding its lock. The check at
	 * the start of the addition is separated from the insert by the move of the files into the storage,
	 * and an office closed in that gap would otherwise be given rows and files nothing can reach any
	 * more: the removal never saw them, and asking for the office again answers that it is not there.
	 * The removal of an office takes the same lock, so the two operations never overlap.
	 *
	 * The lock is of one office and of this pair of operations alone: neither the count behind the
	 * limit nor the order number is read under it, and both stay as loose as O-09 and O-09a BRIEF
	 * accepted them.
	 *
	 * @param int[] $fileIds
	 */
	private function saveRows(int $officeId, array $fileIds, int $userId): Result
	{
		$result = new Result();

		if (!$this->officeRepository->lock($officeId))
		{
			$this->logFailure(__FUNCTION__, 'office ' . $officeId . ' is held by another operation');
			$this->discardFiles($fileIds);

			return $result->addError($this->getStoreFailedError());
		}

		try
		{
			if ($this->officeRepository->getById($officeId) === null)
			{
				$this->discardFiles($fileIds);

				return $result->addError(new Error('Office not found', self::ERROR_OFFICE_NOT_FOUND));
			}

			return $this->addRows($officeId, $fileIds, $userId);
		}
		finally
		{
			$this->officeRepository->unlock($officeId);
		}
	}

	/**
	 * The order number of a new row is the highest the office has given out plus one (O-09a BRIEF).
	 * Numbers are read beside the insert, so two simultaneous additions may share one - the order of
	 * such a pair is settled by the identifier of the row when the photos are read back.
	 *
	 * The batch is written whole or not at all, and a failure of it is an answer rather than a line in
	 * the log: by this point the files are already in the storage and the temporary rows of the uploader
	 * are gone, so a row that did not get in - refused or raised - takes the whole batch back out of the
	 * storage, and the caller hears that the photos it sent were not saved.
	 *
	 * @param int[] $fileIds
	 */
	private function addRows(int $officeId, array $fileIds, int $userId): Result
	{
		$result = new Result();

		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			$sort = $this->officePhotoRepository->getMaxSortByOfficeId($officeId);

			foreach ($fileIds as $fileId)
			{
				++$sort;

				$addResult = $this->officePhotoRepository->add($officeId, (int)$fileId, $sort, $userId);
				if (!$addResult->isSuccess())
				{
					$connection->rollbackTransaction();
					$this->logFailure(__FUNCTION__, implode('; ', $addResult->getErrorMessages()));
					$this->discardFiles($fileIds);

					return $result->addError($this->getStoreFailedError());
				}
			}

			$connection->commitTransaction();
		}
		catch (\Throwable $exception)
		{
			$connection->rollbackTransaction();
			$this->logFailure(__FUNCTION__, $this->describeThrowable($exception));
			$this->discardFiles($fileIds);

			return $result->addError($this->getStoreFailedError());
		}

		return $result;
	}

	/**
	 * Files no row points at any more. The storage refusing to let one go is logged and no more: the
	 * answer the caller gets is about the rows, and a file left behind does not change it.
	 *
	 * @param int[] $fileIds
	 */
	private function discardFiles(array $fileIds): void
	{
		try
		{
			$this->deleteFiles($fileIds);
		}
		catch (\Throwable $exception)
		{
			$this->logFailure(__FUNCTION__, $this->describeThrowable($exception));
		}
	}

	private function findPhoto(int $officeId, int $photoId): ?OfficePhoto
	{
		foreach ($this->officePhotoRepository->getListByOfficeId($officeId) as $photo)
		{
			if ((int)$photo->getId() === $photoId)
			{
				return $photo;
			}
		}

		return null;
	}

	/**
	 * @param int[] $fileIds
	 * @return int[]
	 */
	private function addFilesToFolder(Folder $folder, array $fileIds, int $userId): array
	{
		$storedFileIds = [];

		foreach ($fileIds as $fileId)
		{
			$fileArray = CFile::getFileArray($fileId);
			if (!$fileArray)
			{
				$this->logFailure(__FUNCTION__, 'file ' . $fileId . ' is no longer in the upload');

				break;
			}

			$file = $folder->addFile(
				[
					'NAME' => Text::correctFilename($fileArray['ORIGINAL_NAME']),
					'FILE_ID' => $fileId,
					'SIZE' => $fileArray['FILE_SIZE'],
					'CREATED_BY' => $userId,
				],
				[],
				true,
			);

			if (!$file)
			{
				// The rest of the batch is not attempted: it is refused whole either way.
				$this->logFailure(__FUNCTION__, 'file ' . $fileId . ': ' . $this->describeFolderRefusal($folder));

				break;
			}

			$storedFileIds[] = (int)$file->getId();
		}

		return $storedFileIds;
	}

	/**
	 * The reasons the uploader gave for the tokens it would not unwrap. The tokens themselves stay out
	 * of the log: they are signed and belong to the session that got them.
	 */
	private function describeRejectedTokens(PendingFileCollection $pendingFiles): string
	{
		$codes = [];
		foreach ($pendingFiles as $pendingFile)
		{
			if ($pendingFile->isValid())
			{
				continue;
			}

			foreach ($pendingFile->getErrors() as $error)
			{
				$codes[] = (string)$error->getCode();
			}
		}

		return $codes === []
			? 'the uploader answered with fewer files than tokens and gave no reason'
			: 'the uploader refused the tokens: ' . implode(', ', $codes)
		;
	}

	private function describeFolderRefusal(Folder $folder): string
	{
		$messages = array_map(
			static fn (Error $error): string => (string)$error->getMessage(),
			$folder->getErrors(),
		);

		return $messages === [] ? 'the storage gave no reason' : implode('; ', $messages);
	}

	private function isOwnFile(File $file): bool
	{
		$storage = $file->getStorage();

		return $storage !== null && $storage->getProxyType() instanceof ProxyType;
	}

	/**
	 * @param OfficePhoto[] $rows
	 * @return int[]
	 */
	private function collectFileIds(array $rows): array
	{
		return array_values(array_unique(array_map(
			static fn (OfficePhoto $row): int => (int)$row->getFileId(),
			$rows,
		)));
	}

	/**
	 * @return string[]
	 */
	private function normalizeTokens(array $photoTokens): array
	{
		return array_values(array_filter(
			array_map(static fn ($token): string => is_string($token) ? trim($token) : '', $photoTokens),
			static fn (string $token): bool => $token !== '',
		));
	}

	private function getAccessDeniedError(): Error
	{
		return new Error('Access to the office directory denied', self::ERROR_ACCESS_DENIED);
	}

	/**
	 * What a client is told when a photo did not reach the storage: the reason of it stays in the log.
	 */
	private function getStoreFailedError(): Error
	{
		return new Error('Failed to save the photos of the office', self::ERROR_SAVE_FAILED);
	}

	private function logFailure(string $operation, string $reason): void
	{
		AddMessage2Log(static::class . '::' . $operation . ' failed: ' . $reason, 'stafftrack');
	}

	/**
	 * The class of a failure and the frame it was raised in, never its message: a storage error carries
	 * the query it failed on, and that query carries the coordinates of the office.
	 */
	private function describeThrowable(\Throwable $exception): string
	{
		return $exception::class . ' at ' . $exception->getFile() . ':' . $exception->getLine();
	}
}
