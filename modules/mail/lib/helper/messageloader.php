<?php

namespace Bitrix\Mail\Helper;

use Bitrix\Mail\Internals\MessageAccessTable;
use Bitrix\Mail\Internals\MessageClosureTable as MessageChainTable;
use Bitrix\Mail\MailMessageTable;
use Bitrix\Mail\MailMessageUidTable;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\UI\PageNavigation;
use Bitrix\Main;
use Bitrix\Mail\MailboxTable;
use Bitrix\Main\ORM;
use Bitrix\Mail\Helper\Dto\MessageContact;
use Bitrix\Main\Mail\Address;
use Bitrix\Mail\Helper\Dto\MailMessage;

class MessageLoader
{
	private const VISIBLE_UID_FILTERS = [
		'==MESSAGE_UID.DELETE_TIME' => 0,
		'!@MESSAGE_UID.IS_OLD' => MailMessageUidTable::HIDDEN_STATUSES,
	];

	public static function buildMailMessageListQuery(PageNavigation $navigation, string $dirPath, array $basicFilters): Query
	{
		$messageAccessSubQuery = (new Query(MessageAccessTable::getEntity()))
			->addFilter('=MAILBOX_ID', new Main\DB\SqlExpression('%s'))
			->addFilter('=MESSAGE_ID', new Main\DB\SqlExpression('%s'));

		$messageChainSubQuery = (new Query(MessageChainTable::getEntity()))
			->addFilter('=PARENT_ID', new Main\DB\SqlExpression('%s'))
			->addFilter('!=MESSAGE_ID', new Main\DB\SqlExpression('%s'));

		$query = new Query(MailMessageTable::getEntity());
		$query
			->setSelect([
				'ID',
			])
			->setFilter(array_merge($basicFilters, [
				'=MESSAGE_UID.DIR_MD5' => md5($dirPath),
				self::VISIBLE_UID_FILTERS,
			]))
			->setGroup(['ID'])
			->setOrder(['FIELD_MAX_SORT' => 'DESC', 'ID' => 'DESC'])
			->setOffset($navigation->getOffset())
			->setLimit($navigation->getLimit());

		$runtimeFields = [
			new Reference(
				'MESSAGE_UID',
				MailMessageUidTable::class,
				[
					'=this.MAILBOX_ID' => 'ref.MAILBOX_ID',
					'=this.ID' => 'ref.MESSAGE_ID',
				],
				['join_type' => 'INNER'],
			),
			new ExpressionField(
				'MESSAGE_ACCESS', "EXISTS(" . $messageAccessSubQuery->getQuery() . ")", ['MAILBOX_ID', 'ID']
			),
			new ExpressionField(
				'MESSAGE_CLOSURE', "EXISTS(" . $messageChainSubQuery->getQuery() . ")", ['ID', 'ID']
			),
			new ExpressionField('FIELD_MAX_SORT', 'MAX(%s)', ['FIELD_DATE']),
		];

		foreach ($runtimeFields as $field)
		{
			$query->registerRuntimeField($field);
		}

		return $query;
	}

	public static function buildMailMessagesDetailsQuery(
		array $itemIds,
		array $filters,
	): Query
	{

		$additionalSelect = [];

		if (Main\Loader::includeModule('crm'))
		{
			$additionalSelect['CRM_ACTIVITY_OWNER_TYPE_ID'] = 'MESSAGE_ACCESS.CRM_ACTIVITY.OWNER_TYPE_ID';
			$additionalSelect['CRM_ACTIVITY_OWNER_ID'] = 'MESSAGE_ACCESS.CRM_ACTIVITY.OWNER_ID';
		}

		$query = new Query(MailMessageTable::getEntity());
		$query
			->setSelect(
				array_merge($additionalSelect,
					[
					'BIND_ENTITY_TYPE' => 'MESSAGE_ACCESS.ENTITY_TYPE',
					'BIND_ENTITY_ID' => 'MESSAGE_ACCESS.ENTITY_ID',

					'UID_ID' => 'MESSAGE_UID.ID',
					'IS_SEEN' => 'MESSAGE_UID.IS_SEEN',

					'OPTIONS',
					'BODY',
					'MESSAGE_ID' => 'ID',
					'SUBJECT',
					'FIELD_FROM',
					'FIELD_TO',
					'FIELD_DATE',
					'HEADER',
					'MAILBOX_ID',
					'ATTACHMENTS',
					'MAILBOX_EMAIL' => 'MAILBOX.EMAIL',
				]),
			)
			->setFilter(array_merge(
					[
						'@ID' => $itemIds
					],
					$filters,
					self::VISIBLE_UID_FILTERS
				)
			)
			->setOrder([
				'FIELD_DATE' => 'DESC',
				'ID' => 'DESC',
				'MESSAGE_UID.MSG_UID' => 'ASC',
			])
			->registerRuntimeField(
				new ORM\Fields\Relations\Reference(
					'MESSAGE_UID',
					MailMessageUidTable::class,
					[
						'=this.MAILBOX_ID' => 'ref.MAILBOX_ID',
						'=this.ID' => 'ref.MESSAGE_ID',
					],
					['join_type' => 'INNER']
				)
			)
			->registerRuntimeField(
				new ORM\Fields\Relations\Reference(
					'MAILBOX',
					MailboxTable::class,
					[
						'=this.MAILBOX_ID' => 'ref.ID',
					],
					['join_type' => 'INNER'],
				)
			)
			->registerRuntimeField(
				new ORM\Fields\Relations\Reference(
					'MESSAGE_ACCESS',
					MessageAccessTable::class,
					[
						'=this.MAILBOX_ID' => 'ref.MAILBOX_ID',
						'=this.ID' => 'ref.MESSAGE_ID',
					]
				)
			);

		return $query;
	}

	private static function abbreviateText(string $text, int $length = 50): string
	{
		return trim(preg_replace('/\s+/', ' ', mb_substr($text, 0, $length)));
	}

	private static function addBinding(MailMessage $message, array $row): void
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

		if ($entityBindId > 0 && $entityBindType != '')
		{
			switch ($entityBindType)
			{
				case MessageAccessTable::ENTITY_TYPE_IM_CHAT:
					$message->chatBindId = $entityBindId;
					break;
				case MessageAccessTable::ENTITY_TYPE_TASKS_TASK:
					$message->taskBindId = $entityBindId;
					break;
			}
		}
	}

	public static function mergeIdenticalMessagesInList(Query $query, bool $hideReadStatuses = false): array
	{
		$messageList = [];

		$queryResult = $query->exec();

		while ($row = $queryResult->fetch())
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

		usort($sortedMessageList, static function($a, $b) {
			if ($a->date === $b->date) {
				return $b->id <=> $a->id;
			}
			return $b->date <=> $a->date;
		});

		return $sortedMessageList;
	}

	public static function getMessageList(array $basicFilters, string $dirPath, PageNavigation $navigation, bool $hideReadStatuses = false): array
	{
		$items = [];

		$query = self::buildMailMessageListQuery($navigation, $dirPath, $basicFilters);
		$itemIds = $query->exec()->fetchAll();

		if (empty($itemIds))
		{
			return $items;
		}

		$itemIdsList = array_column($itemIds, 'ID');

		$query = self::buildMailMessagesDetailsQuery(
			$itemIdsList,
			$basicFilters,
		);

		return self::mergeIdenticalMessagesInList($query, $hideReadStatuses);
	}

	public static function buildContactList($fieldValue): array
	{
		$addressList = Message::parseAddressList($fieldValue);

		$processedAddressesList = [];

		foreach ($addressList as $address)
		{
			$processedAddress = new Address($address);
			if ($processedAddress->validate())
			{
				$messageContact = new MessageContact();
				$messageContact->email = $processedAddress->getEmail();
				$messageContact->name = $processedAddress->getName();

				if (empty($messageContact->name))
				{
					$messageContact->name = $messageContact->email;
				}

				$processedAddressesList[] = $messageContact;
			}
		}

		return $processedAddressesList;
	}
}