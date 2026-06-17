<?php

declare(strict_types=1);

namespace Bitrix\Mail\Helper\Message;

use Bitrix\Mail\Helper\Dto\Message\SearchMessagesDto;
use Bitrix\Mail\Helper\MailboxAccess;
use Bitrix\Mail\Helper\Message;
use Bitrix\Mail\Helper\Message\Loader\MessageFilter;
use Bitrix\Mail\Helper\Message\Loader\QueryBuilder;
use Bitrix\Mail\Helper\MessageFolder;
use Bitrix\Mail\Internals\MessageAccessTable;
use Bitrix\Mail\MailboxTable;
use Bitrix\Mail\MailMessageTable;
use Bitrix\Mail\MailMessageUidTable;
use Bitrix\Main\LoaderException;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;

class MessageSearch
{
	/**
	 * Searches messages across user's mailboxes.
	 *
	 * @throws SystemException|LoaderException
	 */
	public function search(SearchMessagesDto $dto, int $userId): array
	{
		$mailboxIds = $this->resolveMailboxIds($dto->mailboxId, $userId);

		if (empty($mailboxIds))
		{
			return [];
		}

		$dto = $this->resolveFolderInDto($dto, $mailboxIds);

		$messageFilter = (new MessageFilter($mailboxIds, []));
		$messageFilter->applyFromDto($dto);
		$filter = $messageFilter->getArray();

		$listQuery = QueryBuilder::buildMailMessageListQuery(
			$filter,
			$dto->limit > 0 ? $dto->limit : SearchMessagesDto::DEFAULT_LIMIT,
			max(0, $dto->offset),
		);

		$itemIds = array_column($listQuery->fetchAll(), 'DISTINCT_ID');
		if (empty($itemIds))
		{
			return [];
		}

		$detailsQuery = QueryBuilder::buildDefaultMessagesDetailsQuery(
			$itemIds,
			$filter
		);

		return $this->formatMessages($detailsQuery->fetchAll());
	}

	/**
	 * @return int[]
	 * @throws SystemException
	 */
	private function resolveMailboxIds(?int $mailboxId, int $userId): array
	{
		if (is_null($mailboxId))
		{
			return array_keys(MailboxTable::getUserMailboxes($userId));
		}

		if ($mailboxId <= 0)
		{
			throw new SystemException('Invalid mailboxId.');
		}

		if (!MailboxAccess::hasUserAccessToMailbox($mailboxId, $userId, true))
		{
			throw new SystemException('User does not have access to this mailbox');
		}

		return [$mailboxId];
	}

	private function resolveFolderInDto(SearchMessagesDto $dto, array $mailboxIds): SearchMessagesDto
	{
		if ($dto->folder === null || trim($dto->folder) === '')
		{
			return $dto;
		}

		$resolvedPath = $this->resolveFolderPath($dto->folder, $mailboxIds);
		if ($resolvedPath === null || $resolvedPath === $dto->folder)
		{
			return $dto;
		}

		return new SearchMessagesDto(
			mailboxId: $dto->mailboxId,
			searchQuery: $dto->searchQuery,
			dateFrom: $dto->dateFrom,
			dateTo: $dto->dateTo,
			isSeen: $dto->isSeen,
			hasAttachments: $dto->hasAttachments,
			folder: $resolvedPath,
			limit: $dto->limit,
			offset: $dto->offset,
		);
	}

	private function resolveFolderPath(string $folder, array $mailboxIds): ?string
	{
		foreach ($mailboxIds as $mailboxId)
		{
			$resolved = MessageFolder::resolveFolderPath($folder, $mailboxId);
			if ($resolved !== null)
			{
				return $resolved;
			}
		}

		return null;
	}

	/**
	 * Returns full message content by ID with access check.
	 *
	 * @throws SystemException
	 */
	public function getMessageContent(int $messageId, int $userId): array
	{
		$message = Message::getWithAccessCheck($messageId, $userId);
		if ($message === null)
		{
			throw new SystemException('Message not found or access denied.');
		}

		return [
			'id' => $messageId,
			'subject' => $message['SUBJECT'] ?? '',
			'from' => $message['FIELD_FROM'] ?? '',
			'to' => $message['FIELD_TO'] ?? '',
			'cc' => $message['FIELD_CC'] ?? '',
			'date' => ($message['INTERNALDATE'] ?? $message['FIELD_DATE']) instanceof DateTime
				? ($message['INTERNALDATE'] ?? $message['FIELD_DATE'])->format('Y-m-d H:i:s')
				: (string)($message['INTERNALDATE'] ?? $message['FIELD_DATE'] ?? ''),
			'body' => $this->sanitizeBody($message),
		];
	}

	/**
	 * Returns full message data by id — list-shape (same as search) plus cc and body.
	 *
	 * Intended for single-message fetch (e.g. REST get by id) where the consumer
	 * expects the same shape as a list item, enriched with content.
	 *
	 * @throws SystemException when message is not found or user has no access
	 */
	public function getMessageById(int $messageId, int $userId): array
	{
		$content = $this->getMessageContent($messageId, $userId);

		$rows = QueryBuilder::buildDefaultMessagesDetailsQuery([$messageId], [])->fetchAll();
		$formatted = $this->formatMessages($rows);
		if (empty($formatted))
		{
			throw new SystemException('Message details not available.');
		}

		return $formatted[0] + [
			'cc' => $content['cc'] ?? '',
			'body' => $content['body'] ?? '',
		];
	}

	/**
	 * Returns message thread (conversation chain) sorted chronologically.
	 *
	 * @throws SystemException
	 */
	public function getMessageThread(int $messageId, int $userId, int $limit = 20): array
	{
		if (Message::getWithAccessCheck($messageId, $userId) === null)
		{
			throw new SystemException('Message not found or access denied.');
		}

		$threadLoader = new MessageThreadLoader($messageId);
		$threadLoader->loadFullThreadMessageIds($limit);
		$threadMessageIds = $threadLoader->getThreadMessageIds();

		if (empty($threadMessageIds))
		{
			return ['messages' => []];
		}

		$rows = MailMessageTable::getList([
			'runtime' => [
				new \Bitrix\Main\Entity\ReferenceField(
					'MESSAGE_UID',
					MailMessageUidTable::class,
					[
						'=this.MAILBOX_ID' => 'ref.MAILBOX_ID',
						'=this.ID' => 'ref.MESSAGE_ID',
					],
					['join_type' => 'INNER'],
				),
			],
			'select' => [
				'ID', 'SUBJECT', 'FIELD_FROM', 'FIELD_TO', 'FIELD_CC',
				'FIELD_DATE', 'INTERNALDATE' => 'MESSAGE_UID.INTERNALDATE',
				'MAILBOX_ID', 'BODY_HTML', 'BODY',
			],
			'filter' => [
				'@ID' => $threadMessageIds,
				'==MESSAGE_UID.DELETE_TIME' => 0,
				'!@MESSAGE_UID.IS_OLD' => MailMessageUidTable::HIDDEN_STATUSES,
			],
			'order' => ['MESSAGE_UID.INTERNALDATE' => 'ASC'],
		])->fetchAll();

		$messages = [];
		foreach ($rows as $row)
		{
			$messageId = (int)$row['ID'];
			if (isset($messages[$messageId]))
			{
				continue;
			}
			$messages[$messageId] = [
				'id' => $messageId,
				'subject' => $row['SUBJECT'] ?? '',
				'from' => $row['FIELD_FROM'] ?? '',
				'to' => $row['FIELD_TO'] ?? '',
				'cc' => $row['FIELD_CC'] ?? '',
				'date' => ($row['INTERNALDATE'] ?? $row['FIELD_DATE']) instanceof DateTime
					? ($row['INTERNALDATE'] ?? $row['FIELD_DATE'])->format('Y-m-d H:i:s')
					: (string)($row['INTERNALDATE'] ?? $row['FIELD_DATE'] ?? ''),
				'body' => $this->sanitizeBody($row),
			];
		}

		return ['messages' => array_values($messages)];
	}

	private function sanitizeBody(array $row): string
	{
		$body = $row['BODY_HTML'] ?? $row['BODY'] ?? '';
		if ($body === '')
		{
			return '';
		}

		$body = Message::sanitizeHtml($body, true);
		$body = strip_tags($body);

		return trim($body);
	}

	private function formatBindings(array $row): array
	{
		$bindings = [];

		$crmOwnerId = (int)($row['CRM_ACTIVITY_OWNER_ID'] ?? 0);
		$crmOwnerTypeId = (int)($row['CRM_ACTIVITY_OWNER_TYPE_ID'] ?? 0);
		if ($crmOwnerId > 0 && $crmOwnerTypeId > 0)
		{
			$bindings[] = ['type' => 'crm', 'entityTypeId' => $crmOwnerTypeId, 'entityId' => $crmOwnerId];
		}

		$entityType = $row['BIND_ENTITY_TYPE'] ?? '';
		$entityId = (int)($row['BIND_ENTITY_ID'] ?? 0);
		if ($entityId > 0 && $entityType !== '')
		{
			$typeMap = [
				MessageAccessTable::ENTITY_TYPE_TASKS_TASK => 'task',
				MessageAccessTable::ENTITY_TYPE_IM_CHAT => 'chat',
				MessageAccessTable::ENTITY_TYPE_CALENDAR_EVENT => 'calendarEvent',
				MessageAccessTable::ENTITY_TYPE_BLOG_POST => 'blogPost',
			];

			$mappedType = $typeMap[$entityType] ?? null;
			if ($mappedType !== null)
			{
				$bindings[] = ['type' => $mappedType, 'entityId' => $entityId];
			}
		}

		return $bindings;
	}

	private function formatMessages(array $rows): array
	{
		$messages = [];

		foreach ($rows as $row)
		{
			$messageId = $row['MESSAGE_ID'] ?? $row['ID'];

			if (isset($messages[$messageId]))
			{
				continue;
			}

			$messages[$messageId] = [
				'id' => (int)$messageId,
				'mailboxId' => (int)($row['MAILBOX_ID'] ?? 0),
				'mailboxEmail' => $row['MAILBOX_EMAIL'] ?? '',
				'subject' => $row['SUBJECT'] ?? '',
				'from' => $row['FIELD_FROM'] ?? '',
				'to' => $row['FIELD_TO'] ?? '',
				'date' => ($row['INTERNALDATE'] ?? $row['FIELD_DATE']) instanceof DateTime
					? ($row['INTERNALDATE'] ?? $row['FIELD_DATE'])->format('Y-m-d H:i:s')
					: (string)($row['INTERNALDATE'] ?? $row['FIELD_DATE'] ?? ''),
				'isSeen' => in_array($row['IS_SEEN'] ?? '', ['Y', 'S'], true),
				'url' => Message::getMessageUrl((int)$messageId),
				'hasAttachments' => !empty($row['ATTACHMENTS']),
				'bindings' => $this->formatBindings($row),
			];
		}

		return array_values($messages);
	}
}
