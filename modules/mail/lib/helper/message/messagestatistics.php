<?php

declare(strict_types=1);

namespace Bitrix\Mail\Helper\Message;

use Bitrix\Mail\Helper\Dto\Message\MailStatisticsDto;
use Bitrix\Mail\Helper\Dto\Message\SearchMessagesDto;
use Bitrix\Mail\Helper\MailboxAccess;
use Bitrix\Mail\Helper\Message\Loader\MessageFilter;
use Bitrix\Mail\Helper\Message\Loader\QueryBuilder;
use Bitrix\Mail\Internals\MailboxDirectoryTable;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;

class MessageStatistics
{
	/**
	 * Returns the number of incoming messages.
	 *
	 * When the DTO has no period — lifetime count across the mailbox(es).
	 * When dateFrom/dateTo is set — count restricted to that window.
	 * When employeeId is set — only that employee's own mailboxes that are
	 * accessible to the current user are counted.
	 *
	 * @return array{incomingCount:int}
	 * @throws SystemException
	 */
	public function getStatistics(MailStatisticsDto $dto, int $userId): array
	{
		$mailboxIds = $this->resolveMailboxIds($dto->mailboxId, $dto->employeeId, $userId);
		$dirMd5s = $mailboxIds === [] ? [] : $this->getIncomingDirHashes($mailboxIds);

		if ($mailboxIds === [] || $dirMd5s === [])
		{
			return ['incomingCount' => 0];
		}

		return [
			'incomingCount' => $this->count($mailboxIds, $dirMd5s, $dto->dateFrom, $dto->dateTo),
		];
	}

	/**
	 * @return int[]
	 * @throws SystemException
	 */
	private function resolveMailboxIds(?int $mailboxId, ?int $employeeId, int $userId): array
	{
		if ($mailboxId !== null)
		{
			return MailboxAccess::resolveUserMailboxIds($mailboxId, $userId);
		}

		if ($employeeId === null)
		{
			return MailboxAccess::resolveUserMailboxIds(null, $userId);
		}

		$targetUserId = $employeeId;
		if ($targetUserId <= 0)
		{
			throw new SystemException('employeeId must be a positive integer.');
		}

		return MailboxAccess::getAccessibleOwnerMailboxIds($targetUserId, $userId);
	}

	private function count(array $mailboxIds, array $dirMd5s, ?DateTime $from = null, ?DateTime $to = null): int
	{
		$searchDto = new SearchMessagesDto(
			dateFrom: $from,
			dateTo: $to,
		);

		$messageFilter = (new MessageFilter($mailboxIds, []))->applyFromDto($searchDto);
		$filter = $messageFilter->getArray();
		$filter['@MESSAGE_UID.DIR_MD5'] = $dirMd5s;

		return QueryBuilder::countMailMessages($filter);
	}

	/**
	 * @return string[]
	 */
	private function getIncomingDirHashes(array $mailboxIds): array
	{
		$rows = MailboxDirectoryTable::getList([
			'select' => ['DIR_MD5'],
			'filter' => [
				'@MAILBOX_ID' => $mailboxIds,
				'=IS_INCOME' => MailboxDirectoryTable::ACTIVE,
				'=IS_SYNC' => MailboxDirectoryTable::ACTIVE,
				'=IS_DISABLED' => MailboxDirectoryTable::INACTIVE,
			],
		])->fetchAll();

		return array_values(array_unique(array_filter(array_column($rows, 'DIR_MD5'))));
	}
}
