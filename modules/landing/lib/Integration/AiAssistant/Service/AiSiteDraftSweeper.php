<?php
declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Service;

use Bitrix\Landing\Integration\AiAssistant\Model\AiSiteBindingTable;
use Bitrix\Main\Application;
use Bitrix\Main\Type\DateTime;

/**
 * Removes free rows of /sites/ai/ - no site, no generation - that were neither created nor opened for the
 * given time. Opening the page touches DATE_MODIFY of the row of its cookie, so a row is kept while the page
 * is in use. A row at the moderation-block limit keeps DATE_MODIFY as the anchor of its cooldown, which is
 * not longer than a day, so a running cooldown is never lost. The chats of the removed rows stay in the
 * chat list of the user: the chat map of aiassistant is not touched.
 */
class AiSiteDraftSweeper
{
	public const DEFAULT_AGE_SECONDS = 86400;
	public const DEFAULT_LIMIT = 500;

	/**
	 * @return int Number of removed rows.
	 */
	public function sweep(int $olderThanSeconds = self::DEFAULT_AGE_SECONDS, int $limit = self::DEFAULT_LIMIT): int
	{
		if ($olderThanSeconds <= 0 || $limit <= 0)
		{
			return 0;
		}

		$threshold = DateTime::createFromTimestamp(time() - $olderThanSeconds);
		$ids = array_map(
			'intval',
			array_column(
				AiSiteBindingTable::query()
					->setSelect(['ID'])
					->whereNull('SITE_ID')
					->whereNull('GENERATION_ID')
					->where('DATE_CREATE', '<', $threshold)
					->where('DATE_MODIFY', '<', $threshold)
					->setOrder(['ID' => 'ASC'])
					->setLimit($limit)
					->fetchAll(),
				'ID',
			),
		);
		if ($ids === [])
		{
			return 0;
		}

		// the conditions are repeated in the DELETE itself: a row reserved or opened after the read stays;
		// the ORM deletes by primary key only and could not re-check the state in the same statement
		$connection = Application::getConnection();
		$sqlThreshold = $connection->getSqlHelper()->convertToDbDateTime($threshold);
		$connection->queryExecute(sprintf(
			'DELETE FROM %s WHERE ID IN (%s) AND SITE_ID IS NULL AND GENERATION_ID IS NULL'
			. ' AND DATE_CREATE < %s AND DATE_MODIFY < %s',
			AiSiteBindingTable::getTableName(),
			implode(', ', $ids),
			$sqlThreshold,
			$sqlThreshold,
		));

		return (int)$connection->getAffectedRowsCount();
	}
}
