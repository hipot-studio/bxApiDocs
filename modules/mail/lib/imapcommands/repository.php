<?php
namespace Bitrix\Mail\ImapCommands;

use Bitrix\Mail;
use Bitrix\Mail\Internal\Service\Label\LabelCountersService;
use Bitrix\Mail\Internal\Service\Message\Conversation\ConversationBackfill;
use Bitrix\Mail\Internal\Service\SourceGeneration\GenerationScope;
use Bitrix\Main;
use Bitrix\Main\Entity\ReferenceField;
use \Bitrix\Mail\Helper\MessageFolder;

class Repository
{
	private const NAMED_PLACEMENTS_CHUNK_SIZE = 500;

	private $mailboxId;
	private $messagesIds;

	public function __construct($mailboxId, $messagesIds)
	{
		$this->mailboxId = $mailboxId;
		$this->messagesIds = $messagesIds;
	}

	/**
	 * The generation of the physical rows a user command may touch: the active source of
	 * the mailbox. A prepared generation is filled by the migration import only and stays
	 * invisible here.
	 */
	private function getGenerationScope(): GenerationScope
	{
		return GenerationScope::forMailbox((int)$this->mailboxId);
	}

	private function applyGenerationScope(Main\ORM\Query\Query $query): void
	{
		foreach ($this->getGenerationScope()->apply([]) as $condition => $value)
		{
			$query->addFilter($condition, $value);
		}
	}

	public function getMailbox($mailboxUserId = null)
	{
		return Mail\MailboxTable::getUserMailbox($this->mailboxId, $mailboxUserId);
	}

	public function deleteOldMessages($folderCurrentName)
	{
		$connection = Main\Application::getInstance()->getConnection();
		$entity = Mail\MailMessageUidTable::getEntity();

		$connection->query(sprintf(
			'DELETE FROM %s WHERE %s',
			$connection->getSqlHelper()->quote($entity->getDbTableName()),
			Main\Entity\Query::buildFilterSql($entity, $this->getGenerationScope()->apply([
				'=MAILBOX_ID' => (int)$this->mailboxId,
				'=DIR_MD5' => md5($folderCurrentName),
				'=MSG_UID' => 0,
			]))
		));

		return $connection->getAffectedRowsCount();
	}

	public function markMessagesUnseen($messages, $mailbox)
	{
		$this->setMessagesSeen('N', $messages, $mailbox);
	}

	public function markMessagesSeen($messages, $mailbox)
	{
		$this->setMessagesSeen('Y', $messages, $mailbox);
	}

	protected function setMessagesSeen($isSeen, $messages, $mailbox)
	{
		$messagesIds = [];

		foreach ($this->messagesIds as $index => $messageId)
		{
			$messagesIds[$index] = $messageId;
		}

		if (empty($messagesIds) || empty($messages) || empty($mailbox))
		{
			return;
		}

		$mailsData = [];

		foreach ($messages as $messageData)
		{
			$mailsData[] = [
				'HEADER_MD5' => $messageData['HEADER_MD5'],
				'MAILBOX_USER_ID' => $mailbox['USER_ID'],
				'IS_SEEN' => $isSeen,
			];
		}

		$mailboxId = intval($this->mailboxId);

		Mail\MailMessageUidTable::updateList(
			$this->getGenerationScope()->apply([
				'=MAILBOX_ID' => $mailboxId,
				'@ID' => $messagesIds,
			]),
			[
				'IS_SEEN' => $isSeen,
			],
			$mailsData
		);

		$dirWithMessagesId = MessageFolder::getDirIdForMessages($mailboxId,$messagesIds);

		if($isSeen === 'Y')
		{
			MessageFolder::decreaseDirCounter($mailboxId, $dirWithMessagesId, count($messagesIds));
		}
		else
		{
			MessageFolder::increaseDirCounter($mailboxId, null, $dirWithMessagesId, count($messagesIds));
		}

		\Bitrix\Mail\Helper::updateMailboxUnseenCounter($mailboxId);

		(new \Bitrix\Mail\Internal\Service\Label\LabelCountersService())
			->recalculateForUidRows($mailboxId, $messagesIds);
	}

	public function updateMessageFieldsAfterMove($messages, $folderNewName, $mailbox)
	{
		$messagesIds = [];
		foreach ($messages as $message)
		{
			$messagesIds[] = $message['ID'];
		}
		if (empty($messagesIds))
		{
			return;
		}

		$mailsData = [];
		foreach ($messages as $messageData)
		{
			$mailsData[] = [
				'HEADER_MD5' => $messageData['HEADER_MD5'],
				'MAILBOX_USER_ID' => $mailbox['USER_ID'],
				'OLD_DIR_MD5' => $messageData['DIR_MD5'],
				'INTERNALDATE' => $messageData['INTERNALDATE'],
				'IS_OLD' => $messageData['IS_OLD'],
				'DELETE_TIME' => $messageData['DELETE_TIME'],
				'MESSAGE_ID' => $messageData['MESSAGE_ID'],
			];
		}

		Mail\MailMessageUidTable::updateList(
			$this->getGenerationScope()->apply([
				'=MAILBOX_ID' => intval($this->mailboxId),
				'@ID' => $messagesIds,
			]),
			[
				'MSG_UID' => 0,
				'DIR_MD5' => md5($folderNewName),
			],
			$mailsData
		);
	}

	public function addMailsToBlacklist($blacklistMails, $userId)
	{
		$result = new Main\Result();
		$result->setData([Mail\BlacklistTable::addMailsBatch($blacklistMails, $userId)]);
		return $result;
	}

	/**
	 * Used to delete small sample of messages from the database ( at the user's request ).
	 *
	 * @param array $messagesToDelete Each message must contain the "MESSAGE_ID" field.
	 * @param $mailboxUserId
	 *
	 * @return ?int Number of deleted logical messages or null for an empty input.
	 */
	public function deleteMailsCompletely(array $messagesToDelete, $mailboxUserId): ?int
	{
		// @TODO: make a log optional
		/*$messageToLog = [
			'cause' => 'deleteMailsCompletely',
			'filter' => 'manual deletion of messages',
			'removedMessages'=>$messagesToDelete,
		];
		AddMessage2Log($messageToLog);*/

		if (empty($messagesToDelete))
		{
			return null;
		}

		$messagesById = [];

		foreach ($messagesToDelete as $message)
		{
			$messageId = (int)($message['MESSAGE_ID'] ?? 0);
			if ($messageId > 0 && !isset($messagesById[$messageId]))
			{
				$messagesById[$messageId] = $message;
			}
		}

		$ids = array_keys($messagesById);
		if (empty($ids))
		{
			return 0;
		}

		$mailFieldsForEvent = [];

		foreach ($messagesById as $messageId => $item)
		{
			$mailFieldsForEvent[] = [
				'HEADER_MD5' => $item['HEADER_MD5'] ?? '',
				'MESSAGE_ID' => $messageId,
				'MAILBOX_USER_ID' => $mailboxUserId,
			];
		}

		/*
			The only deliberately generation-wide deletion: the user removes the logical letter,
			not one of its physical positions, so every generation loses its placement of it.
			The command that got here was still admitted through the active generation alone.
		*/
		Mail\MailMessageUidTable::deleteList(
			[
				'=MAILBOX_ID' => $this->mailboxId,
				'!=MESSAGE_ID' => 0,
				'@MESSAGE_ID' => $ids,
			],
			$mailFieldsForEvent
		);

		Mail\Internals\MailMessageMarkTable::deleteByMessages((int)$this->mailboxId, $ids);

		// The uid rows are gone from the server, not from deleteList above: it gets event data without ID.
		(new LabelCountersService())->handleMessagesDeleted((int)$this->mailboxId, $ids);

		$affectedRows = $this->deleteMailMessageRows($ids);

		/*
			After the message rows themselves are gone, and not before: while a message is stored it
			still connects the closure, and the rest of its conversation would be read back as one
			component. Each removal checks what is left of the conversation and splits it into the
			components it really consists of.
		*/
		(new ConversationBackfill())->removeMessages((int)$this->mailboxId, $ids);

		return $affectedRows;
	}

	/**
	 * @return int Logical messages that have been deleted.
	 */
	private function deleteMailMessageRows(array $ids): int
	{
		$ids = array_values(array_unique(array_filter(
			array_map('intval', $ids),
			static fn(int $id): bool => $id > 0,
		)));

		if (empty($ids))
		{
			return 0;
		}

		$existingIds = [];
		$messages = Mail\MailMessageTable::query()
			->setSelect(['ID'])
			->whereIn('ID', $ids)
			->exec()
		;
		while ($message = $messages->fetch())
		{
			$existingIds[] = (int)$message['ID'];
		}

		/*
			Through the shared deletion of a logical message, so the letter takes its attachments,
			bindings and thread closure with it. The background cleanup cannot do it later: it
			works from the placements, and they are already gone.
		*/
		foreach ($existingIds as $id)
		{
			\CMailMessage::delete($id, (int)$this->mailboxId);
		}

		return count($existingIds);
	}

	/**
	 * The rows the command names, with what tells whether they still address their letters: the folder
	 * they lie in and the version of it their number was written under.
	 */
	public function getNamedPlacements(): iterable
	{
		if (empty($this->messagesIds))
		{
			return;
		}

		$count = count($this->messagesIds);
		for ($offset = 0; $offset < $count; $offset += self::NAMED_PLACEMENTS_CHUNK_SIZE)
		{
			$query = Mail\MailMessageUidTable::query()
				->addSelect('ID')
				->addSelect('MESSAGE_ID')
				->addSelect('DIR_MD5')
				->addSelect('DIR_UIDV')
				->addSelect('DATE_INSERT')
				->addSelect('DELETE_TIME')
				->addSelect('GENERATION_ID')
				->where('MAILBOX_ID', $this->mailboxId)
				->whereIn(
					'ID',
					array_slice($this->messagesIds, $offset, self::NAMED_PLACEMENTS_CHUNK_SIZE),
				)
				->where('MESSAGE_ID', '>', 0)
				->addFilter('==DELETE_TIME', 0)
			;
			$this->applyGenerationScope($query);

			$rows = $query->exec();
			while ($row = $rows->fetch())
			{
				yield $row;
			}
		}
	}

	public function getMessages()
	{
		if (empty($this->messagesIds))
		{
			return [];
		}
		$messages = [];

		// The command names uid rows of the active generation only, in any order
		$selectedQuery = Mail\MailMessageUidTable::query()
			->addSelect('MESSAGE_ID')
			->where('MAILBOX_ID', $this->mailboxId)
			->whereIn('ID', $this->messagesIds)
			->whereNot('MSG_UID', 0)
			->where('MESSAGE_ID', '>', 0)
			->addFilter('==DELETE_TIME', 0)
		;
		$this->applyGenerationScope($selectedQuery);

		$messagesSelected = $selectedQuery->exec()->fetchAll();
		if ($messagesSelected)
		{
			$messagesSelectedIds = array_map(
				function ($item)
				{
					return $item['MESSAGE_ID'];
				},
				$messagesSelected
			);
			if (empty($messagesSelectedIds))
			{
				return [];
			}
			/*
				The sibling placements of the same logical message: the active generation
				only, so a copy left by a previous physical source is never commanded.
			*/
			$placementsQuery = Mail\MailMessageUidTable::query()
				->registerRuntimeField(
					'',
					new ReferenceField(
						'ref',
						Mail\MailMessageTable::class,
						['=this.MESSAGE_ID' => 'ref.ID']
					)
				)
				->addSelect('ID')
				->addSelect('MAILBOX_ID')
				->addSelect('DIR_MD5')
				->addSelect('DIR_UIDV')
				// what tells which version of a folder is in force, without asking the server
				->addSelect('DATE_INSERT')
				->addSelect('MSG_UID')
				->addSelect('HEADER_MD5')
				->addSelect('IS_SEEN')
				->addSelect('SESSION_ID')
				->addSelect('MESSAGE_ID')
				->addSelect('INTERNALDATE')
				->addSelect('IS_OLD')
				->addSelect('DELETE_TIME')
				->addSelect('ref.FIELD_FROM', 'FIELD_FROM')
				->whereIn('MESSAGE_ID', $messagesSelectedIds)
				->where('MAILBOX_ID', $this->mailboxId)
				->whereNot('MSG_UID', 0)
			;
			$this->applyGenerationScope($placementsQuery);

			$messages = PlacementFolderVersionFilter::dropPreviousVersions($placementsQuery->exec()->fetchAll());
		}

		return $messages;
	}
}
