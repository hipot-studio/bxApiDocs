<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Recent;

use Bitrix\Im\Model\RecentTable;
use Bitrix\Im\Model\UserTable;
use Bitrix\Im\V2\Chat;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Type\DateTime;

/**
 * Page of the portal-wide acquaintance partition: rows with USER_ID = 0, one per employee.
 * Read with the same cursor and limit as the reader's own tail, so both streams can be merged
 * by date. Companions the reader already has a private row for are subtracted inside the query -
 * doing it in memory would shrink the page below the requested limit.
 */
class PortalRecentProvider
{
	private const PARTITION_USER_ID = 0;
	private const COMPANION = 'COMPANION';

	public function getList(int $readerId, ?DateTime $cursor, int $limit): Recent
	{
		if ($readerId <= 0 || $limit <= 0)
		{
			return new Recent();
		}

		return $this->markAsAcquaintance(Recent::initByArray($this->fetchPage($readerId, $cursor, $limit)));
	}

	/**
	 * DTO-03, channel M1. Every row of this partition is a person the reader has no conversation with,
	 * so the marker follows from the source of the row and needs nothing read from its content.
	 */
	private function markAsAcquaintance(Recent $rows): Recent
	{
		foreach ($rows as $row)
		{
			$row->setOptions($row->getOptions() + [Recent::OPTION_DEFAULT_USER_RECORD => true]);
		}

		return $rows;
	}

	private function fetchPage(int $readerId, ?DateTime $cursor, int $limit): array
	{
		$query = RecentTable::query()
			->setSelect(Recent::SELECT_FIELDS)
			->registerRuntimeField($this->getCompanionReference())
			->where('USER_ID', self::PARTITION_USER_ID)
			->where('ITEM_TYPE', Chat::IM_TYPE_PRIVATE)
			->where('ITEM_ID', '!=', $readerId)
			->where(self::COMPANION . '.ACTIVE', true)
			->where(self::COMPANION . '.IS_INTRANET_USER', true)
			->whereNotIn('ITEM_ID', $this->getOwnCompanionsQuery($readerId))
			->setOrder(['DATE_LAST_ACTIVITY' => 'DESC'])
			->setLimit($limit)
		;

		// The cursor is inclusive, exactly as in the tail: see RecentFilter::prepareFilter().
		if ($cursor !== null)
		{
			$query->where('DATE_LAST_ACTIVITY', '<=', $cursor);
		}

		return $query->fetchAll();
	}

	private function getCompanionReference(): Reference
	{
		return (new Reference(
			self::COMPANION,
			UserTable::class,
			Join::on('this.ITEM_ID', 'ref.ID'),
		))->configureJoinType(Join::TYPE_INNER);
	}

	private function getOwnCompanionsQuery(int $readerId): Query
	{
		return RecentTable::query()
			->setSelect(['ITEM_ID'])
			->where('USER_ID', $readerId)
			->where('ITEM_TYPE', Chat::IM_TYPE_PRIVATE)
		;
	}
}
