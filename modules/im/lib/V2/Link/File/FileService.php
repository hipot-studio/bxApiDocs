<?php

namespace Bitrix\Im\V2\Link\File;

use Bitrix\Disk\Driver;
use Bitrix\Disk\File;
use Bitrix\Disk\Security\DiskSecurityContext;
use Bitrix\Im\Model\ChatTable;
use Bitrix\Im\Model\LinkFileTable;
use Bitrix\Im\Model\MessageParamTable;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Common\ContextCustomer;
use Bitrix\Im\V2\Link\Push;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Result;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Query\Query;

class FileService
{
	use ContextCustomer;
	public const SAVED_DISK_FILE_IDS = 'SAVED_DISK_FILE_IDS';

	protected const ADD_FILE_EVENT = 'fileAdd';
	protected const DELETE_FILE_EVENT = 'fileDelete';

	protected bool $isMigrationFinished;

	public function __construct()
	{
		$this->isMigrationFinished = self::isLinkMigrationFinished();
	}

	/**
	 * Whether the file belongs to a message cleared for everyone. The message is unreachable the moment
	 * the border of the chat is set, while the file itself waits for the scheduled removal; the border
	 * is chat-wide, so the answer needs no user.
	 *
	 * Only the chat the file belongs to is asked. The commit path attaches an already uploaded file by
	 * id, without copying it and without refusing one stored elsewhere ({@see \CIMDisk::uploadFromLocalDisk()}
	 * logs what it does not recognise), so the links of a single file are written by chats that do not
	 * own it, and a border of theirs would close a file they are not allowed to delete either
	 * ({@see \Bitrix\Im\V2\Chat\Cleanup\ChatContentCollector::deleteChatFiles()} keeps a file living
	 * outside the chat folder). The owner is the chat whose disk folder holds the file, and the oldest
	 * message of that chat showing it decides for the rest: the answer for a message only gets stricter
	 * as its id gets smaller (a comment chat adds the border of its post, which no id of a comment moves
	 * either). A file kept outside every chat folder belongs to nobody here, and the cleanup leaves it
	 * where it is as well.
	 *
	 * This answers the download path of every signed url, which names no chat of its own
	 * ({@see \CIMDisk::GetFileLink()} signs the id of the file alone) and is asked without
	 * authentication, as often as whoever holds the url likes. The owner bounds that answer: it is one
	 * chat, however many rows the attachments of the file have written.
	 *
	 * The answer outlives the rows it started from. A cleanup takes the links of the cleared messages
	 * away with their content, and a file the cleanup kept would otherwise be answered for by nothing
	 * at all and open again. A file the owner keeps for a message of its own above the border keeps the
	 * rows of the cleared messages with it, so the oldest of them goes on answering
	 * ({@see \Bitrix\Im\V2\Chat\Cleanup\ChatContentCollector::collectClearedFileRows()}); a file kept
	 * for a message of another chat loses them, and a file left in the folder of a chat that has cleared
	 * its history, with nothing of that chat pointing at it any more, stays closed for that:
	 * {@see self::isOwnerChatCleared()}.
	 *
	 * @param int|null $diskFolderId the folder of the file, when the caller is already holding it
	 */
	public static function isBehindChatHistoryBorder(int $diskFileId, ?int $diskFolderId = null): bool
	{
		$diskFolderId ??= self::getDiskFolderId($diskFileId);

		if ($diskFolderId <= 0)
		{
			return false;
		}

		$hasLinks = false;

		if (self::hasLinkBehindChatHistoryBorder($diskFileId, $diskFolderId, $hasLinks))
		{
			return true;
		}

		// The two tables are one set of links while the migration is on its way: a cleared message may be
		// represented by its FILE_ID param alone, and the row of a message nobody has cleared would
		// otherwise answer for the whole file.
		if ($hasLinks && self::isLinkMigrationFinished())
		{
			return false;
		}

		$hasParams = false;

		if (self::isBehindChatHistoryBorderByMessageParam($diskFileId, $diskFolderId, $hasParams))
		{
			return true;
		}

		return !$hasLinks && !$hasParams && self::isOwnerChatCleared($diskFileId, $diskFolderId);
	}

	/**
	 * A file left in the folder of a chat that has cleared its history, with nothing of that chat showing
	 * it any more. The rows of the cleared messages cannot answer for it: the cleanup takes them with the
	 * content when nothing of this chat shows the file any more, and a file kept alive by a message of
	 * another chat would start downloading again the moment they went. What stays is the border of the
	 * chat, and it stays for good - so the revocation does too.
	 *
	 * Asked only when neither table holds anything about the file: a row above the border has already
	 * answered by then, and so has a row behind it.
	 */
	private static function isOwnerChatCleared(int $diskFileId, int $diskFolderId): bool
	{
		$chatId = self::getChatIdByDiskFolderId($diskFolderId);

		// Whether the chat has a border at all, asked of the message older than every message there is.
		// A comment chat never gets a border of its own and answers by the post it hangs on, so the
		// question belongs to the chat rather than to the param holding the border.
		if ($chatId <= 0 || !Chat::getInstance($chatId)->isMessageBehindHistoryBorder(0))
		{
			return false;
		}

		// Asked of the rows as a whole, a row without a message included: such a row names no history, so
		// the cleanup has taken nothing away from the file behind it. No write path produces one today -
		// every insert goes through FileCollection::save() with a message already saved - so this is the
		// same guard the cleanup keeps on its side, not a case reached in practice.
		return LinkFileTable::query()
			->setSelect(['ID'])
			->where('DISK_FILE_ID', $diskFileId)
			->where('CHAT_ID', $chatId)
			->setLimit(1)
			->fetch() === false
		;
	}

	private static function getChatIdByDiskFolderId(int $diskFolderId): int
	{
		$chat = ChatTable::query()
			->setSelect(['ID'])
			->where('DISK_FOLDER_ID', $diskFolderId)
			->setLimit(1)
			->fetch()
		;

		return (int)($chat['ID'] ?? 0);
	}

	private static function hasLinkBehindChatHistoryBorder(int $diskFileId, int $diskFolderId, bool &$hasLinks): bool
	{
		$chats = LinkFileTable::query()
			->setSelect(['CHAT_ID'])
			->registerRuntimeField(new ExpressionField('OLDEST_MESSAGE_ID', 'MIN(%s)', 'MESSAGE_ID'))
			->addSelect('OLDEST_MESSAGE_ID')
			->where('DISK_FILE_ID', $diskFileId)
			->where('MESSAGE_ID', '>', 0)
			->where('CHAT.DISK_FOLDER_ID', $diskFolderId)
			->setGroup(['CHAT_ID'])
			->fetchAll()
		;

		foreach ($chats as $chat)
		{
			$hasLinks = true;

			if (self::isChatBehindBorder((int)$chat['CHAT_ID'], (int)$chat['OLDEST_MESSAGE_ID']))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * An attachment older than b_im_link_file, or one the migration has not reached, has no link row at
	 * all, and its only trace of a chat is the FILE_ID param of the message showing it. The owner is
	 * looked for the same way, so a file shown elsewhere as well is not closed by a chat it does not
	 * belong to.
	 */
	private static function isBehindChatHistoryBorderByMessageParam(
		int $diskFileId,
		int $diskFolderId,
		bool &$hasParams,
	): bool
	{
		$chats = MessageParamTable::query()
			->setSelect(['CHAT_ID' => 'MESSAGE.CHAT_ID'])
			->registerRuntimeField(new ExpressionField('OLDEST_MESSAGE_ID', 'MIN(%s)', 'MESSAGE_ID'))
			->addSelect('OLDEST_MESSAGE_ID')
			->where('PARAM_NAME', Message\Params::FILE_ID)
			->where('PARAM_VALUE', (string)$diskFileId)
			->where('MESSAGE.CHAT.DISK_FOLDER_ID', $diskFolderId)
			->setGroup(['MESSAGE.CHAT_ID'])
			->fetchAll()
		;

		foreach ($chats as $chat)
		{
			$hasParams = true;

			if (self::isChatBehindBorder((int)$chat['CHAT_ID'], (int)$chat['OLDEST_MESSAGE_ID']))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * The folder holding the file, which is what names its owner. Read from Disk only when the caller
	 * has not read it already: the download path loads the file before it asks anything about it.
	 */
	private static function getDiskFolderId(int $diskFileId): int
	{
		if ($diskFileId <= 0 || !Loader::includeModule('disk'))
		{
			return 0;
		}

		return (int)(File::getById($diskFileId)?->getParentId() ?? 0);
	}

	/**
	 * A file reaches the same chat through both tables while the migration is on its way, and the answer
	 * for a chat is the same whichever table asked for it.
	 */
	private static function isChatBehindBorder(int $chatId, int $oldestMessageId): bool
	{
		if ($chatId <= 0 || $oldestMessageId <= 0)
		{
			return false;
		}

		return Chat::getInstance($chatId)->isMessageBehindHistoryBorder($oldestMessageId);
	}

	public static function isLinkMigrationFinished(): bool
	{
		return Option::get('im', 'im_link_file_migration', 'N') === 'Y';
	}

	public function save(Message $message): Result
	{
		$links = $this->buildLinks($message);
		$result = $this->saveInternal($links);

		if ($result->isSuccess())
		{
			$result->setData(array_merge(
				$result->getData(),
				[self::SAVED_DISK_FILE_IDS => $this->getSavedDiskFileIds($message, $links)],
			));
		}

		return $result;
	}

	protected function buildLinks(Message $message): FileCollection
	{
		return FileCollection::linkEntityToMessage($message->getFiles()->getUnique(), $message);
	}

	/**
	 * @return int[]
	 */
	private function getSavedDiskFileIds(Message $message, FileCollection $links): array
	{
		$savedIdSet = [];
		foreach ($links->getEntityIds() as $id)
		{
			$id = (int)$id;
			if ($id > 0)
			{
				$savedIdSet[$id] = true;
			}
		}

		$result = [];
		foreach ($message->getFileIds() as $id)
		{
			$id = (int)$id;
			if ($id > 0 && isset($savedIdSet[$id]))
			{
				$result[] = $id;
			}
		}

		return $result;
	}

	/**
	 * @param File[] $files
	 * @param Message $message
	 * @return Result
	 */
	public function saveFilesFromMessage(array $files, Message $message): Result
	{
		$result = new Result();

		if (empty($files))
		{
			return $result;
		}

		$entities = (new \Bitrix\Im\V2\Entity\File\FileCollection($files, $message->getChatId()))->getUnique();
		/** @var FileCollection $links */
		$links = FileCollection::linkEntityToMessage($entities, $message);

		return $this->saveInternal($links);
	}

	/**
	 * @param int[] $previousFileIds
	 * @param int[] $currentFileIds
	 */
	public function synchronizeFilesFromMessage(
		array $previousFileIds,
		array $currentFileIds,
		Message $message,
	): Result
	{
		$previousFileIds = array_values(array_unique(array_map('intval', $previousFileIds)));
		$currentFileIds = array_values(array_unique(array_map('intval', $currentFileIds)));

		$addedFileIds = array_values(array_diff($currentFileIds, $previousFileIds));
		$deletedFileIds = array_values(array_diff($previousFileIds, $currentFileIds));

		$result = $this->saveFilesFromMessage($addedFileIds, $message);
		$deleteResult = $this->deleteFilesByDiskFileIds($deletedFileIds, $message);

		return $result->addErrors($deleteResult->getErrors());
	}

	protected function saveInternal(FileCollection $links): Result
	{
		$result = new Result();
		$saveResult = $links->save();

		if ($links->count() === 0)
		{
			return $result;
		}

		if (!$saveResult->isSuccess())
		{
			$result->addErrors($saveResult->getErrors());
		}

		if ($saveResult->isSuccess())
		{
			foreach ($links as $link)
			{
				Push::getInstance()
					->setContext($this->context)
					->sendFull($link, self::ADD_FILE_EVENT, ['CHAT_ID' => $link->getChatId()])
				;
			}
		}

		return $result;
	}

	public function deleteFilesByDiskFileId(int $diskFileId): Result
	{
		$links = FileCollection::getByDiskFileId($diskFileId);

		return $this->deleteFiles($links);
	}

	public function deleteFilesByDiskFileIds(array $diskFileIds, Message $message): Result
	{
		if (empty($diskFileIds))
		{
			return new Result();
		}

		$links = FileCollection::getByDiskFileIds($diskFileIds, $message);

		return $this->deleteFiles($links);
	}

	public function deleteFilesByMessageIds(array $messageIds): Result
	{
		$links = FileCollection::getByMessageIds($messageIds);

		return $this->deleteFiles($links);
	}

	protected function deleteFiles(FileCollection $links): Result
	{
		$result = new Result();

		if ($links->isEmpty())
		{
			return $result;
		}

		$deleteResult = $links->delete();

		if (!$deleteResult->isSuccess())
		{
			return $result->addErrors($deleteResult->getErrors());
		}

		if (!$this->isMigrationFinished)
		{
			return $result;
		}

		foreach ($links as $link)
		{
			Push::getInstance()
				->setContext($this->context)
				->sendIdOnly($link, self::DELETE_FILE_EVENT, ['CHAT_ID' => $link->getChatId()])
			;
		}

		return $result;
	}

	public function isMigrationFinished(): bool
	{
		return $this->isMigrationFinished;
	}

	public function getFilesBeforeMigrationFinished(int $chatId, int $limit, ?int $lastId = null, ?string $filename = null): \Bitrix\Im\V2\Entity\File\FileCollection
	{
		$folderModel = \CIMDisk::getFolderModel($chatId, false);
		if ($folderModel === false)
		{
			return new \Bitrix\Im\V2\Entity\File\FileCollection();
		}
		$relation = \CIMChat::GetRelationById($chatId, $this->getContext()->getUserId(), true, false);
		$filter = Query::filter()
			->where('PARENT_ID', $folderModel->getId())
			->where('STORAGE_ID', $folderModel->getStorageId())
			->where('ID', '>', $relation['LAST_FILE_ID'])
		;
		if (isset($lastId))
		{
			$filter->where('ID', '<', $lastId);
		}
		if (isset($filename))
		{
			$clearFileName = str_replace("%", '', $filename);
			$filter->whereLike('NAME', "$clearFileName%");
		}
		$parameters = [
			'filter' => $filter,
			'with' => ['CREATE_USER'],
			'limit' => $limit,
			'order' => ['ID' => 'DESC'],
		];
		$securityContext = new DiskSecurityContext($this->getContext()->getUserId());
		$parameters = Driver::getInstance()->getRightsManager()->addRightsCheck($securityContext, $parameters, ['ID', 'CREATED_BY']);
		$diskFiles = File::getModelList($parameters);

		return new \Bitrix\Im\V2\Entity\File\FileCollection($diskFiles, $chatId);
	}
}
