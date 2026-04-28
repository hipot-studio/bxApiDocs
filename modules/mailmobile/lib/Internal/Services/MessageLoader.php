<?php

namespace Bitrix\MailMobile\Internal\Services;

use Bitrix\Mail\Helper\Dto\MailMessage;
use Bitrix\Mail\Helper\MailMessageChainProvider;
use Bitrix\Mail\Helper\Message\Loader\MessageFilter;
use Bitrix\Mail\Helper\Message\Loader\QueryBuilder;
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

		return self::aggregateMessages($query->fetchAll(), $hideReadStatuses);
	}

	private static function aggregateMessages(array $rows, bool $hideReadStatuses = false): array
	{
		$messageList = [];

		foreach($rows as $row)
		{
			if (!array_key_exists($row['MESSAGE_ID'], $messageList))
			{
				$message = new MailMessage();
				$message->abbreviatedText = self::abbreviateText($row['BODY']);
				$message->id = (int)$row['MESSAGE_ID'];
				$message->uidId = $row['UID_ID'].'-'.$row['MAILBOX_ID'];
				$message->subject = self::abbreviateText($row['SUBJECT']);
				MailMessageChainProvider::fillRecipients($message, $row);
				$message->date = (int)($row['FIELD_DATE']->getTimestamp());
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
		return trim(preg_replace('/\s+/', ' ', mb_substr($text, 0, 50)));
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