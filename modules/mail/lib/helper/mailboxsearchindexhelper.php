<?php

namespace Bitrix\Mail\Helper;

use Bitrix\Mail\Internals\Search\IndexBuilder;
use Bitrix\Mail\Internals\Search\MailboxListSearchIndexTable;
use Bitrix\Main\Application;
use Bitrix\Main\ORM\Query\Filter\Helper;
use Bitrix\Main\Search\Content;

class MailboxSearchIndexHelper
{
	public static function prepareStringToSearch(string $index): ?string
	{
		$index = trim($index);
		$index = mb_strtoupper($index);
		$preparedString = Content::prepareStringToken($index);

		return Helper::matchAgainstWildcard($preparedString);
	}

	public static function saveSearchIndexForMailbox(int $mailboxId): void
	{
		$indexBuilder = new IndexBuilder();
		$index = $indexBuilder->build($mailboxId);

		if ($index)
		{
			self::set($mailboxId, $index);
		}
	}

	public static function set(int $mailboxId, string $searchIndex): bool
	{
		$searchIndex = trim($searchIndex);

		if ($mailboxId <= 0 || empty($searchIndex))
		{
			return false;
		}

		$connection = Application::getConnection();
		$sqlHelper = $connection->getSqlHelper();
		$searchIndex = $sqlHelper->forSql($searchIndex);

		$row = MailboxListSearchIndexTable::getList([
			'select' => ['ID', 'SEARCH_INDEX'],
			'filter' => [
				'MAILBOX_ID' => $mailboxId,
			],
		])->fetch();

		if (!$row)
		{
			MailboxListSearchIndexTable::addInsertIgnore([
				'MAILBOX_ID' => $mailboxId,
				'SEARCH_INDEX' => $searchIndex,
			]);

			return true;
		}

		if ($searchIndex === $row['SEARCH_INDEX'])
		{
			return true;
		}

		MailboxListSearchIndexTable::update(
			['ID' => $row['ID']],
			['SEARCH_INDEX' => $searchIndex],
		);

		return true;
	}
}
