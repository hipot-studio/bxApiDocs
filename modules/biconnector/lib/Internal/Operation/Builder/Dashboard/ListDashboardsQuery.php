<?php

namespace Bitrix\BIConnector\Internal\Operation\Builder\Dashboard;

/**
 * Arguments of the dashboard list as list_dashboards takes them: an optional substring of the title or
 * the description and the offset of the page.
 */
final class ListDashboardsQuery
{
	public readonly string $query;
	public readonly int $offset;

	public function __construct(string $query = '', int $offset = 0)
	{
		$this->query = trim($query);
		$this->offset = max(0, $offset);
	}
}
