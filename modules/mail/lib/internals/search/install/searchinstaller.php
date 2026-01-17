<?php

namespace Bitrix\Mail\Internals\Search\Install;

use Bitrix\Mail\Internals\Search\IndexBuilder;
use Bitrix\Mail\Internals\Search\MailboxListSearchIndexTable;
use Bitrix\Mail\MailboxTable;
use Bitrix\Main\Application;

class SearchInstaller
{
	private const LOCK_NAME = 'mailbox_grid_search_index_install';
	private const INDEX_BATCH_COUNT = 100;
	private const AGENT_FUNCTION_TEMPLATE = 'Bitrix\\Mail\\Internals\\Search\\Install\\SearchInstaller::install(%d);';

	public static function install(int $lastMailboxId = 0): string
	{
		$connection = Application::getConnection();
		if (!$connection->lock(self::LOCK_NAME))
		{
			return self::getAgentCall($lastMailboxId);
		}

		try
		{
			$mailboxesDataToBatch =
				MailboxTable::query()
					->setSelect(['ID', 'USER_ID', 'EMAIL', 'NAME'])
					->where('ID', '>', $lastMailboxId)
					->addOrder('ID')
					->setLimit(self::INDEX_BATCH_COUNT)
					->fetchAll()
			;

			if (!$mailboxesDataToBatch)
			{
				return '';
			}

			$indexBuilder = new IndexBuilder();
			$indexesToInsert = $indexBuilder->buildBatch($mailboxesDataToBatch);
			$addResult = MailboxListSearchIndexTable::addMulti($indexesToInsert, ignoreEvents: true);

			if (!$addResult->isSuccess())
			{
				return self::getAgentCall($lastMailboxId + 1);
			}

			$lastMailbox = end($mailboxesDataToBatch);
			$lastMailboxId = (int)$lastMailbox['ID'];

			return self::getAgentCall($lastMailboxId);
		}
		catch (\Exception $e)
		{
			return self::getAgentCall($lastMailboxId + 1);
		}
		finally
		{
			$connection->unlock(self::LOCK_NAME);
		}
	}

	private static function getAgentCall(int $lastMailboxId): string
	{
		return sprintf(self::AGENT_FUNCTION_TEMPLATE, $lastMailboxId);
	}
}
