<?php

namespace Bitrix\MailMobile\Internal\Services;

use Bitrix\Mail\Helper\Message;
use Bitrix\Mail\Helper\Dto\MailMessage;
use Bitrix\Mail\Helper\MailMessageChainProvider;
use Bitrix\Mail\Helper\Message\Loader\MessageFilter;
use Bitrix\Mail\Helper\Message\Loader\QueryBuilder;
use Bitrix\Mail\Internals\MailboxDirectoryTable;
use Bitrix\Mail\Internals\MessageAccessTable;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\LoaderException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Main\UI\PageNavigation;

class MessageLoader
{
	/**
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @throws ArgumentException
	 * @throws LoaderException
	 */
	public static function getMessageList(MessageFilter $filter, PageNavigation $navigation, bool $hideReadStatuses = false): array
	{
		$query = QueryBuilder::buildMailMessageListQuery(
			$filter->getArray(),
			$navigation->getLimit(),
			$navigation->getOffset(),
		);

		$itemIds = array_column($query->fetchAll(), 'DISTINCT_ID');

		if (empty($itemIds))
		{
			return [];
		}

		$query = QueryBuilder::buildMobileMessagesDetailsQuery(
			$itemIds,
			$filter->getArray(),
		);

		$rows = $query->fetchAll();
		$folderIds = self::buildFolderIdMap($rows);

		return self::aggregateMessages($rows, $folderIds, $hideReadStatuses);
	}

	/**
	 * @param array $rows
	 * @return array<int, array<string, int>> Nested map: [mailboxId][dirMd5] => folderId
	 */
	private static function buildFolderIdMap(array $rows): array
	{
		if (empty($rows))
		{
			return [];
		}

		$result = MailboxDirectoryTable::getList([
			'select' => ['ID', 'MAILBOX_ID', 'DIR_MD5'],
			'filter' => [
				'@MAILBOX_ID' => array_unique(array_map('intval', array_column($rows, 'MAILBOX_ID'))),
				'@DIR_MD5' => array_unique(array_column($rows, 'DIR_MD5')),
			],
		]);

		$map = [];
		while ($dir = $result->fetch())
		{
			$map[(int)$dir['MAILBOX_ID']][$dir['DIR_MD5']] = (int)$dir['ID'];
		}

		return $map;
	}

	/**
	 * @param array $rows
	 * @param array<int, array<string, int>> $folderIds Nested map: [mailboxId][dirMd5] => folderId
	 * @param bool $hideReadStatuses
	 * @return array
	 */
	private static function aggregateMessages(array $rows, array $folderIds, bool $hideReadStatuses = false): array
	{
		$messageList = [];

		foreach($rows as $row)
		{
			if (!array_key_exists($row['MESSAGE_ID'], $messageList))
			{
				$message = new MailMessage();
				$message->id = (int)$row['MESSAGE_ID'];
				$message->uidId = $row['UID_ID'].'-'.$row['MAILBOX_ID'];
				$message->mailboxId = (int)$row['MAILBOX_ID'];
				$message->folderId = $folderIds[$message->mailboxId][$row['DIR_MD5'] ?? ''] ?? null;
				$message->subject = (string)$row['SUBJECT'];
				$message->abbreviatedText = self::abbreviateText(
					Message::getDisplaySnippet((string)$row['BODY'], $message->subject),
				);
				MailMessageChainProvider::fillRecipients($message, $row);
				$message->date = (int)(($row['INTERNALDATE'] ?? $row['FIELD_DATE'])->getTimestamp());
				$messageList[$row['MESSAGE_ID']] = $message;

				if (isset($row['OPTIONS']['attachments']) &&  isset($row['OPTIONS']['attachments']) > 0)
				{
					$message->withAttachments = (int)($row['OPTIONS']['attachments']);
				}
			}

			self::addBinding($messageList[$row['MESSAGE_ID']], $row);

			if ($row['IS_SEEN'] === 'Y' || $hideReadStatuses)
			{
				$messageList[$row['MESSAGE_ID']]->isRead = true;
			}
		}

		$sortedMessageList = array_values($messageList);

		/*
		 * Sorting must be deterministic to avoid inconsistencies on list refresh:
		 * if dates are equal, fall back to id comparison
		 */
		usort($sortedMessageList, static function($a, $b) {
			if ($a->date === $b->date) {
				return $b->id <=> $a->id;
			}
			return $b->date <=> $a->date;
		});

		return $sortedMessageList;
	}

	private static function abbreviateText(string $text): string
	{
		$text = str_replace(["\r\n", "\n", "\r", "\t"], ' ', mb_substr($text, 0, 50));

		while (str_contains($text, '  '))
		{
			$text = str_replace('  ', ' ', $text);
		}

		return trim($text);
	}

	/**
	 * @param MailMessage $message
	 * @param array $row
	 * @return void
	 */
	public static function addBinding(MailMessage $message, array $row): void
	{
		$crmBindId = (int)($row['CRM_ACTIVITY_OWNER_ID'] ?? 0);
		$crmBindTypeId = (int)($row['CRM_ACTIVITY_OWNER_TYPE_ID'] ?? 0);

		if ($crmBindId > 0 && $crmBindTypeId > 0)
		{
			$message->crmBindId = $crmBindId;
			$message->crmBindTypeId = $crmBindTypeId;

			return;
		}

		$entityBindId = (int)($row['BIND_ENTITY_ID'] ?? 0);
		$entityBindType = $row['BIND_ENTITY_TYPE'] ?? '';

		if ($entityBindId > 0 && $entityBindType !== '')
		{
			switch ($entityBindType)
			{
				case MessageAccessTable::ENTITY_TYPE_IM_CHAT:
					$message->chatBindId = $entityBindId;
					break;
				case MessageAccessTable::ENTITY_TYPE_TASKS_TASK:
					$message->taskBindId = $entityBindId;
					break;
				case MessageAccessTable::ENTITY_TYPE_CALENDAR_EVENT:
					$message->eventBindId = $entityBindId;
					break;
			}
		}
	}

}
