<?php

namespace Bitrix\Im\Update;

use Bitrix\Im\Model\EO_LinkFile;
use Bitrix\Im\Model\EO_Message;
use Bitrix\Im\Model\EO_Message_Collection;
use Bitrix\Im\Model\FileParamTable;
use Bitrix\Im\Model\LinkFileTable;
use Bitrix\Im\Model\MessageParamTable;
use Bitrix\Im\Model\MessageTable;
use Bitrix\Im\V2\Entity;
use Bitrix\Im\V2\Entity\File\FileError;
use Bitrix\Im\V2\Entity\File\Param\BaseParam;
use Bitrix\Im\V2\Entity\File\Param\ParamName;
use Bitrix\Im\V2\Link\File\FileCollection;
use Bitrix\Im\V2\Link\File\FileItem;
use Bitrix\Im\V2\Link\File\Subtype;
use Bitrix\Im\V2\Message\Params;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Diag\LoggerFactory;
use Psr\Log\LoggerInterface;

/**
 * Adds the sidebar records of the video notes sent before the release and moves the records left in the
 * media subtype to the video note one.
 *
 * The walk goes over the file params carrying the video note flag and looks the messages up backwards,
 * so its size is the number of video notes of the portal, not the whole file history. Both halves of the
 * walk are bounded: a portion holds a limited number of files, and an iteration reads a limited number of
 * the messages those files belong to, keeping its place in both by a composite cursor (see run()).
 *
 * A plain agent rather than a Stepper: Stepper binds its agent with an interval of one second and only
 * stretches it when a single iteration runs longer than twenty seconds, so a walk of cheap portions wakes
 * up as often as the portal runs agents at all. The walk is bound by hand once the feature is delivered
 * (see bind()), and until then no agent exists.
 */
final class VideoNoteLinkMigration
{
	public const OPTION_NAME = 'im_video_note_link_migration';
	public const OPTION_NAME_LIMIT = 'im_video_note_link_migration_limit';
	public const OPTION_NAME_ITERATION_COUNT = 'im_video_note_link_migration_iteration';
	public const LIMIT_DEFAULT = 500;
	public const LIMIT_MAX = 1000;
	public const ITERATION_COUNT_DEFAULT = 4;
	public const ITERATION_COUNT_MAX = 10;
	public const INTERVAL = 600;
	private const MODULE_ID = 'im';
	private const LOGGER_ID = 'im.VideoNoteLinkMigration';
	private const MESSAGE_CHUNK_SIZE = 500;

	private function __construct(
		private int $lastDiskFileId,
		private bool $hasLostPortions,
		private int $lastMessageId,
	)
	{
	}

	/**
	 * Binds the walk by hand: it is a step of the delivery rather than of an update, because the records it
	 * creates become visible in the tab at once and the clients must be released first.
	 */
	public static function bind(int $delay = self::INTERVAL): void
	{
		\CAgent::AddAgent(
			self::getAgentName(0, false, 0),
			self::MODULE_ID,
			'N',
			self::INTERVAL,
			'',
			'Y',
			\ConvertTimeStamp(time() + \CTimeZone::GetOffset() + $delay, 'FULL'),
			existError: false,
		);
	}

	/**
	 * The cursor and the loss flag travel in the name of the agent, which the core rewrites with the value
	 * returned here: the walk keeps no state of its own, so nothing is left behind when it is over.
	 *
	 * The cursor is a pair. $lastDiskFileId is the file param the walk reached, $lastMessageId tells how far
	 * inside that file it got: a zero means the file is walked whole and the next run opens with the file
	 * params above it, a value above zero means the file is walked up to that message and the next run opens
	 * with the same file, reading its messages above that id. The message half is the last parameter and
	 * defaults to zero, so a name of the former shape, already bound on a portal, stays callable.
	 */
	public static function run(int $lastDiskFileId = 0, int $hasLostPortions = 0, int $lastMessageId = 0): string
	{
		return (new self($lastDiskFileId, $hasLostPortions !== 0, $lastMessageId))->walk();
	}

	private function walk(): string
	{
		$numOfIterations = $this->getIterationCount();

		for ($i = 0; $i < $numOfIterations; ++$i)
		{
			if (!$this->makeMigrationIteration())
			{
				return '';
			}
		}

		return self::getAgentName($this->lastDiskFileId, $this->hasLostPortions, $this->lastMessageId);
	}

	private function makeMigrationIteration(): bool
	{
		if (!$this->isLinkFileMigrationFinished())
		{
			return true;
		}

		$fileParams = $this->getVideoNoteFileParams();

		if (empty($fileParams))
		{
			$this->finishWalk();

			return false;
		}

		$portion = $this->readPortion($fileParams);
		$errors = [];

		if (!empty($portion['messageParams']))
		{
			$fileEntities = $this->loadFileEntities($portion['fileIds']);

			foreach ($this->collectLinks($portion['messageParams'], $fileEntities) as $links)
			{
				if ($links->isEmpty())
				{
					continue;
				}

				$saveResult = $links->save(true);

				// the gate is cached in the process too, so a portion refused by it waits for the next run
				if ($saveResult->getErrorCollection()->getErrorByCode(FileError::SAVE_BEFORE_MIGRATION_ERROR) !== null)
				{
					return true;
				}

				if (!$saveResult->isSuccess())
				{
					$errors = array_merge($errors, $saveResult->getErrorMessages());
				}
			}
		}

		if (!empty($errors))
		{
			// the cursor moves on anyway, so the boundaries of the lost portion go to the log to repeat it
			// by hand, and the walk remembers the loss to answer "is the history recounted" honestly
			$this->hasLostPortions = true;

			$this->getLogger()?->error(
				'Video note sidebar records of the portion were not saved',
				[
					'fromDiskFileId' => $this->lastDiskFileId,
					'fromMessageId' => $this->lastMessageId,
					'toDiskFileId' => $portion['lastDiskFileId'],
					'toMessageId' => $portion['lastMessageId'],
					'errors' => $errors,
				],
			);
		}

		$this->lastDiskFileId = $portion['lastDiskFileId'];
		$this->lastMessageId = $portion['lastMessageId'];

		return true;
	}

	/**
	 * Reads the messages of the portion up to the ceiling of the iteration and tells where the cursor stops.
	 *
	 * The files are walked one by one because the messages of one file are read by its own keyset: the index
	 * (PARAM_NAME, PARAM_VALUE, MESSAGE_ID) gives an ordered read only for a single value of the param, so a
	 * common IN over the files of the portion would have to sort what it read. Once the ceiling is spent the
	 * cursor stops inside the file it was spent on, and the next iteration opens with the same file.
	 *
	 * @param array<int,array{DISK_FILE_ID:int,PARAM_VALUE:string|null}> $fileParams
	 * @return array{
	 *     messageParams: array<int,array{MESSAGE_ID:int,PARAM_VALUE:string}>,
	 *     fileIds: int[],
	 *     lastDiskFileId: int,
	 *     lastMessageId: int
	 * }
	 */
	private function readPortion(array $fileParams): array
	{
		// only the file the previous iteration stopped inside of resumes from a message id
		$resumeDiskFileId = $this->lastMessageId > 0 ? $this->lastDiskFileId : 0;
		$restOfIteration = $this->getMessageChunkSize();
		$portion = [
			'messageParams' => [],
			'fileIds' => [],
			'lastDiskFileId' => $this->lastDiskFileId,
			'lastMessageId' => $this->lastMessageId,
		];

		foreach ($fileParams as $fileParam)
		{
			$diskFileId = (int)$fileParam['DISK_FILE_ID'];

			// a file whose flag is not set holds nothing to walk, but the cursor still steps over it
			if (!$this->isVideoNoteParam($fileParam))
			{
				$portion['lastDiskFileId'] = $diskFileId;
				$portion['lastMessageId'] = 0;

				continue;
			}

			$fromMessageId = $diskFileId === $resumeDiskFileId ? $this->lastMessageId : 0;
			$messageParams = $this->readMessageParams($diskFileId, $fromMessageId, $restOfIteration);
			$restOfIteration -= count($messageParams);

			if (!empty($messageParams))
			{
				$portion['fileIds'][] = $diskFileId;
				$portion['messageParams'] = array_merge($portion['messageParams'], $messageParams);
			}

			$portion['lastDiskFileId'] = $diskFileId;
			$portion['lastMessageId'] = 0;

			if ($restOfIteration <= 0)
			{
				// the file may hold more messages than were read, so the cursor stops inside it
				$portion['lastMessageId'] = (int)$messageParams[array_key_last($messageParams)]['MESSAGE_ID'];

				break;
			}
		}

		return $portion;
	}

	/**
	 * The option answers "is the history recounted" for the operator and for QA, so a walk that lost a portion
	 * to a save error must not claim it: the boundaries of the lost portions are already in the log, and the
	 * answer stays negative until they are repeated by hand.
	 */
	private function finishWalk(): void
	{
		if ($this->hasLostPortions)
		{
			$this->getLogger()?->error('The walk over the video notes is over, but portions of it were lost');

			return;
		}

		Option::set(self::MODULE_ID, self::OPTION_NAME, 'Y');
	}

	private static function getAgentName(int $lastDiskFileId, bool $hasLostPortions, int $lastMessageId): string
	{
		return self::class . '::run(' . $lastDiskFileId . ', ' . (int)$hasLostPortions . ', ' . $lastMessageId . ');';
	}

	/**
	 * The registry is skipped on purpose: a record about lost data must not wait for the logger to be enabled.
	 */
	private function getLogger(): ?LoggerInterface
	{
		return (new LoggerFactory())->createById(self::LOGGER_ID, [], false);
	}

	/**
	 * At least one iteration per run: a zero in the option would leave the agent rescheduling itself forever
	 * without ever reading a portion. The ceiling is here for the same reason the size of the portion has one:
	 * the messages of a portion are read file by file, so the number of those reads per run is the number of
	 * the iterations times the ceiling of the messages, and a raised option would stretch a single run without
	 * a limit. Measured on a local portal, one such read costs 0.158 ms and a whole iteration of 500 of them
	 * costs 79 ms, so a run of the ceiling below stays under a second of reading.
	 */
	private function getIterationCount(): int
	{
		$count = (int)Option::get(self::MODULE_ID, self::OPTION_NAME_ITERATION_COUNT, self::ITERATION_COUNT_DEFAULT);

		return max(1, min($count, self::ITERATION_COUNT_MAX));
	}

	/**
	 * The portion is tuned by an option on delivery, and the whole portion of the file params is read into
	 * memory at once, so the size is capped here rather than left to the value in the option.
	 */
	private function getLimit(): int
	{
		$limit = (int)Option::get(self::MODULE_ID, self::OPTION_NAME_LIMIT, self::LIMIT_DEFAULT);

		return max(1, min($limit, self::LIMIT_MAX));
	}

	/**
	 * The ceiling of the message rows one iteration reads: without it a single file forwarded thousands of
	 * times would stretch the run by itself. The ceiling never exceeds the portion of the files, so the
	 * option that tunes the load of the walk stays the only one and a portion narrowed for a slow portal
	 * narrows the walk of the forwards too.
	 */
	private function getMessageChunkSize(): int
	{
		return min($this->getLimit(), self::MESSAGE_CHUNK_SIZE);
	}

	/**
	 * Saving the sidebar records is closed until the file link migration is over: it creates the links of the
	 * video notes itself, so waiting for it costs nothing and leaves nothing behind.
	 */
	private function isLinkFileMigrationFinished(): bool
	{
		return Option::get(self::MODULE_ID, LinkFileMigration::OPTION_NAME, 'N') === 'Y';
	}

	/**
	 * The cursor goes by the id of the disk file rather than by the id of the row: the unique index of the
	 * table starts with that column, so the portion is read as an index range and the params of the other
	 * names are skipped inside the index. The pair (disk file, param name) is unique, so one file gives the
	 * cursor at most one row and no file is read twice.
	 *
	 * The file the previous iteration stopped inside of opens the portion again, because its messages above
	 * the cursor are still unread.
	 *
	 * @return array<int,array{DISK_FILE_ID:int,PARAM_VALUE:string|null}>
	 */
	private function getVideoNoteFileParams(): array
	{
		$query = FileParamTable::query()
			->setSelect(['DISK_FILE_ID', 'PARAM_VALUE'])
			->where('PARAM_NAME', ParamName::IsVideoNote->value)
			->setOrder(['DISK_FILE_ID' => 'ASC'])
			->setLimit($this->getLimit())
		;

		if ($this->lastMessageId > 0)
		{
			$query->where('DISK_FILE_ID', '>=', $this->lastDiskFileId);
		}
		else
		{
			$query->where('DISK_FILE_ID', '>', $this->lastDiskFileId);
		}

		return $query->fetchAll();
	}

	/**
	 * @param array{DISK_FILE_ID:int,PARAM_VALUE:string|null} $fileParam
	 */
	private function isVideoNoteParam(array $fileParam): bool
	{
		$fileId = (int)$fileParam['DISK_FILE_ID'];
		$param = BaseParam::getInstance($fileId, ParamName::IsVideoNote, (string)$fileParam['PARAM_VALUE']);

		return $param->getValue() === true;
	}

	/**
	 * One file belongs to as many messages as it was forwarded to, so the messages of a file are read by
	 * their own keyset: the index (PARAM_NAME, PARAM_VALUE, MESSAGE_ID) serves an equality by the file and
	 * an ordered read by the message, so the limit bounds the read itself rather than the answer of it.
	 *
	 * @return array<int,array{MESSAGE_ID:int,PARAM_VALUE:string}>
	 */
	private function readMessageParams(int $diskFileId, int $lastMessageId, int $limit): array
	{
		return MessageParamTable::query()
			->setSelect(['MESSAGE_ID', 'PARAM_VALUE'])
			->where('PARAM_NAME', Params::FILE_ID)
			->where('PARAM_VALUE', (string)$diskFileId)
			->where('MESSAGE_ID', '>', $lastMessageId)
			->setOrder(['MESSAGE_ID' => 'ASC'])
			->setLimit($limit)
			->fetchAll()
		;
	}

	/**
	 * @param int[] $fileIds
	 */
	private function loadFileEntities(array $fileIds): Entity\File\FileCollection
	{
		$fileEntities = Entity\File\FileCollection::initByDiskFilesIds($fileIds);
		// the subtype is calculated from the file params: one query for the portion instead of one per file
		$fileEntities->loadParams();

		return $fileEntities;
	}

	/**
	 * The records to create and the records to move to the video note subtype are kept apart: a collection
	 * saves an insert and an update by two queries and returns the result of the last one, so a refused
	 * insert would leave no trace behind a successful update.
	 *
	 * @param array<int,array{MESSAGE_ID:int,PARAM_VALUE:string}> $messageParams
	 * @return FileCollection[]
	 */
	private function collectLinks(array $messageParams, Entity\File\FileCollection $fileEntities): array
	{
		$newLinks = new FileCollection();
		$movedLinks = new FileCollection();
		$messageIds = $this->getMessageIds($messageParams);
		$messages = $this->getMessages($messageIds);
		$existingEntities = $this->getExistingLinkEntities($messageIds, $this->getChunkFileIds($messageParams));

		foreach ($messageParams as $messageParam)
		{
			$messageId = (int)$messageParam['MESSAGE_ID'];
			$fileId = (int)$messageParam['PARAM_VALUE'];
			$existingEntity = $existingEntities[$this->getLinkKey($messageId, $fileId)] ?? null;
			$fileEntity = $fileEntities->getById($fileId);

			if ($existingEntity !== null)
			{
				// an existing record carries the chat, the author and the date already, so it is moved to
				// the right subtype even when its message is gone. A file still on disk decides on its own:
				// its id comes back into use, nothing deletes the params of the deleted file, and an
				// inherited flag alone would send a plain file to the tab of the video notes.
				if ($fileEntity !== null && !$this->isVideoNoteFile($fileEntity))
				{
					continue;
				}

				if ($existingEntity->getSubtype() !== Subtype::VideoNote->value)
				{
					// the raw column is read: the getter of a record with NULL there would query the file
					$movedLinks->add((new FileItem($existingEntity))->setSubtype(Subtype::VideoNote));
				}

				continue;
			}

			$message = $messages->getByPrimary($messageId);

			if ($fileEntity === null || $message === null)
			{
				continue;
			}

			$link = $this->createLink($message, $fileEntity);

			// the send path skips the files of the system user, so the walk of the history skips them too
			if ($link->needToAddLink())
			{
				$newLinks->add($link);
			}
		}

		return [$newLinks, $movedLinks];
	}

	/**
	 * The subtype is calculated from the loaded file the same way the send path calculates it, so the flag
	 * of a file that is no longer a video moves nothing.
	 */
	private function isVideoNoteFile(Entity\File\FileItem $fileEntity): bool
	{
		return (new FileItem())->setEntity($fileEntity)->getSubtype() === Subtype::VideoNote;
	}

	/**
	 * @param array<int,array{MESSAGE_ID:int,PARAM_VALUE:string}> $messageParams
	 * @return int[]
	 */
	private function getMessageIds(array $messageParams): array
	{
		// one message carrying several video notes repeats its id across the chunk
		return array_values(array_unique(array_map('intval', array_column($messageParams, 'MESSAGE_ID'))));
	}

	/**
	 * @param array<int,array{MESSAGE_ID:int,PARAM_VALUE:string}> $messageParams
	 * @return int[]
	 */
	private function getChunkFileIds(array $messageParams): array
	{
		return array_values(array_unique(array_map('intval', array_column($messageParams, 'PARAM_VALUE'))));
	}

	/**
	 * @param int[] $messageIds
	 */
	private function getMessages(array $messageIds): EO_Message_Collection
	{
		return MessageTable::query()
			->setSelect(['ID', 'AUTHOR_ID', 'DATE_CREATE', 'CHAT_ID'])
			->whereIn('ID', $messageIds)
			->fetchCollection()
		;
	}

	/**
	 * The messages of the chunk bound the read: a file forwarded over and over has as many records as it has
	 * messages, so reading them by the files alone would grow with the whole history of the file.
	 *
	 * @param int[] $messageIds
	 * @param int[] $fileIds
	 * @return array<string,EO_LinkFile>
	 */
	private function getExistingLinkEntities(array $messageIds, array $fileIds): array
	{
		$entities = LinkFileTable::query()
			->setSelect(['ID', 'MESSAGE_ID', 'CHAT_ID', 'SUBTYPE', 'DISK_FILE_ID', 'DATE_CREATE', 'AUTHOR_ID'])
			->whereIn('MESSAGE_ID', $messageIds)
			->whereIn('DISK_FILE_ID', $fileIds)
			->fetchCollection()
		;

		$existing = [];

		foreach ($entities as $entity)
		{
			$key = $this->getLinkKey((int)$entity->getMessageId(), (int)$entity->getDiskFileId());
			$existing[$key] = $entity;
		}

		return $existing;
	}

	private function createLink(EO_Message $message, Entity\File\FileItem $fileEntity): FileItem
	{
		$link = new FileItem();
		$link
			->setChatId((int)$message->getChatId())
			->setAuthorId((int)$message->getAuthorId())
			->setMessageId((int)$message->getId())
			->setEntity($fileEntity)
			->setDateCreate($message->getDateCreate())
		;

		return $link;
	}

	private function getLinkKey(int $messageId, int $fileId): string
	{
		return "{$messageId}:{$fileId}";
	}
}
