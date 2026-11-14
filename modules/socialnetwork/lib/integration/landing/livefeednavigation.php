<?php

namespace Bitrix\Socialnetwork\Integration\Landing;

/** @internal Native navigation state without executing or buffering a metadata query. */
final class LivefeedNavigation extends \CDBResult
{
	public function normalize(int $total, array|int|false $params): ?array
	{
		$descending = $params['bDescPageNumbering'] ?? false;
		$this->InitNavStartVars($params);
		$this->NavRecordCount = $total;
		if ($this->NavShowAll)
		{
			$this->NavPageSize = $this->NavRecordCount;
		}

		$this->NavPageCount = $this->NavPageSize > 0 ? floor($total / $this->NavPageSize) : 0;
		if ($descending)
		{
			$makeweight = $this->NavPageSize > 0 ? $total % $this->NavPageSize : 0;
			if ($this->NavPageCount == 0 && $makeweight > 0)
			{
				$this->NavPageCount = 1;
			}
			$this->calculatePageNumber($this->NavPageCount);
			$first = $this->NavPageNomer != $this->NavPageCount ? $makeweight : 0;
			$first += ($this->NavPageCount - $this->NavPageNomer) * $this->NavPageSize;
			$last = $makeweight + ($this->NavPageCount - $this->NavPageNomer + 1) * $this->NavPageSize;
		}
		else
		{
			if ($this->NavPageSize > 0 && $total % $this->NavPageSize > 0)
			{
				$this->NavPageCount++;
			}
			$this->calculatePageNumber(1, true, (bool)($params['checkOutOfRange'] ?? false));
			if ($this->NavPageNomer === null)
			{
				return null;
			}
			$first = $this->NavPageSize * ($this->NavPageNomer - 1);
			$last = $this->NavPageSize * $this->NavPageNomer;
		}

		if ($total === 0)
		{
			return null;
		}

		return ['offset' => (int)$first, 'limit' => (int)($last - $first), 'showAll' => (bool)$this->NavShowAll];
	}
}
