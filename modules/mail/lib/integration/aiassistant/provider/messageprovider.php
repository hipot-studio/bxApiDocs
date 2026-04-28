<?php

declare(strict_types=1);

namespace Bitrix\Mail\Integration\AiAssistant\Provider;

use Bitrix\Mail\Helper\MailboxAccess;
use Bitrix\Mail\Helper\Message\Loader\MessageFilter;
use Bitrix\Mail\Helper\Message\Loader\QueryBuilder;
use Bitrix\Mail\Helper\Dto\Message\SearchMessagesDto;
use Bitrix\Mail\MailboxTable;
use Bitrix\Main\LoaderException;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;

class MessageProvider
{
	/**
	 * @throws SystemException|LoaderException
	 */
	public function search(SearchMessagesDto $dto, int $userId): array
	{
		$mailboxIds = $this->resolveMailboxIds($dto->mailboxId, $userId);

		if (empty($mailboxIds))
		{
			return [];
		}

		$messageFilter = (new MessageFilter($mailboxIds, []));
		$messageFilter->applyFromDto($dto);
		$filter = $messageFilter->getArray();

		$listQuery = QueryBuilder::buildMailMessageListQuery(
			$filter,
			$dto->limit > 0 ? $dto->limit : SearchMessagesDto::DEFAULT_LIMIT,
			0,
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

		if (!MailboxAccess::hasUserAccessToMailbox($mailboxId, $userId, true))
		{
			throw new SystemException('User does not have access to this mailbox');
		}

		return [$mailboxId];
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
				'date' => $row['FIELD_DATE'] instanceof DateTime
					? $row['FIELD_DATE']->format('Y-m-d H:i:s')
					: (string)($row['FIELD_DATE'] ?? ''),
				'isSeen' => in_array($row['IS_SEEN'] ?? '', ['Y', 'S'], true),
				'hasAttachments' => !empty($row['ATTACHMENTS']),
			];
		}

		return array_values($messages);
	}
}
