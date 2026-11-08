<?php
namespace Bitrix\Search;

use Bitrix\Main\Messenger\Entity\MessageInterface;
use Bitrix\Main\Messenger\Receiver\AbstractReceiver;
use Bitrix\Main\Messenger\Internals\Exception\Receiver\RecoverableMessageException;

/**
 * Class SyncIndexItemReceiver
 *
 * Receiver for syncing a search content entry.
 *
 * @package Bitrix\Search
 */
class SyncIndexItemReceiver extends AbstractReceiver
{
	/**
	 * Processes incoming messages to update search index.
	 *
	 * @param SyncIndexItemMessage $message The message containing search content id.
	 * @return void
	 */
	protected function process(MessageInterface $message): void
	{
		$connection = \CDatabase::GetModuleConnection('search')->getConnection();
		$indexSql = '
			SELECT ID, MODULE_ID, ITEM_ID
			FROM b_search_content sc
			WHERE sc.ID = ' . $message->searchContentId
		;

		$indexEntry = $connection->query($indexSql)->fetch();

		try
		{
			if (!$indexEntry)
			{
				\CSearchFullText::getInstance()->deleteById($message->searchContentId);
			}
			else
			{
				\CSearchFullText::getInstance()->update($indexEntry['ID'], $indexEntry);
			}
		}
		catch (\Bitrix\Main\DB\ConnectionException $e)
		{
			throw new RecoverableMessageException('Error processing search queue.', 0, $e);
		}
	}
}
