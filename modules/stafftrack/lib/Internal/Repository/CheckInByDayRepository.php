<?php

namespace Bitrix\StaffTrack\Internal\Repository;

use Bitrix\Main\Application;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Type\DateTime;
use Bitrix\StaffTrack\Internal\Model\CheckInByDayTable;

class CheckInByDayRepository
{
	/**
	 * Ids of the cleanup window with the length of GEO_DATA as stored - encrypted and base64 encoded, which
	 * is what lands in memory. The value itself stays out of the select: CryptoField decrypts on select.
	 *
	 * @param DateTime $windowStart
	 * @param DateTime $threshold
	 * @param int[] $cursor (DATE_END unix, ID) of the last row already walked; empty starts at the window
	 * @param int $limit
	 * @return array
	 */
	public function getStaleRowSizes(DateTime $windowStart, DateTime $threshold, array $cursor = [], int $limit = 500): array
	{
		$query = CheckInByDayTable::query()
			->registerRuntimeField(new ExpressionField('GEO_LEN', 'LENGTH(%s)', ['GEO_DATA']))
			->setSelect(['ID', 'DATE_END', 'GEO_LEN'])
			->setLimit($limit)
		;

		return $this->inStaleWindow($query, $windowStart, $threshold, $cursor)->exec()->fetchAll();
	}

	/**
	 * Rows of the given ids in the (DATE_END, ID) order the cleanup walks its window in. The order is
	 * part of the contract rather than a detail of the query: the caller saves a cursor by the last
	 * processed row, and any other order would carry that cursor past rows still holding geo data.
	 *
	 * @param int[] $ids
	 * @return array
	 */
	public function getRowsByIds(array $ids): array
	{
		$ids = array_values(array_filter(array_map('intval', $ids)));
		if (empty($ids))
		{
			return [];
		}

		return CheckInByDayTable::query()
			->setSelect(['ID', 'DATE_END', 'GEO_DATA'])
			->whereIn('ID', $ids)
			->setOrder(['DATE_END' => 'ASC', 'ID' => 'ASC'])
			->exec()
			->fetchAll()
		;
	}

	/**
	 * @param int $id
	 * @param string $geoDataJson
	 */
	public function updateGeoData(int $id, string $geoDataJson): void
	{
		CheckInByDayTable::update($id, ['GEO_DATA' => $geoDataJson]);
	}

	/**
	 * The window of the cleanup and the seek into it, shared by the two selects of one walk so that they
	 * cannot drift apart. The keyset goes over (DATE_END, ID) - the order of
	 * ix_st_check_in_by_day_date_end, so the range, the sort and the seek all lie on the same index.
	 *
	 * @param Query $query
	 * @param DateTime $windowStart
	 * @param DateTime $threshold
	 * @param int[] $cursor
	 * @return Query
	 */
	private function inStaleWindow(Query $query, DateTime $windowStart, DateTime $threshold, array $cursor): Query
	{
		$query
			->where('DATE_END', '>', $windowStart)
			->where('DATE_END', '<=', $threshold)
			->setOrder(['DATE_END' => 'ASC', 'ID' => 'ASC'])
		;

		if (count($cursor) === 2)
		{
			$after = DateTime::createFromTimestamp((int)$cursor[0]);
			$sqlHelper = Application::getConnection()->getSqlHelper();
			$afterDateEnd = $sqlHelper->convertToDbDateTime($after);
			$afterId = (int)$cursor[1];

			// The row-value comparison is the exact predicate but not a sargable one - alone it reads as
			// a full index scan. The bound on the leading column repeats part of the tuple in the form
			// the range optimizer seeks by.
			$query
				->where('DATE_END', '>=', $after)
				->whereExpr("(%s, %s) > ({$afterDateEnd}, {$afterId})", ['DATE_END', 'ID'])
			;
		}

		return $query;
	}
}
