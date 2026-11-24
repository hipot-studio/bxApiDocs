<?php

namespace Bitrix\BIConnector\Public\Command\TableCell;

/**
 * Cell of a role and a table: an override of the rights the role has on the table through its groups.
 */
final readonly class TableCellChange
{
	/**
	 * @param int $roleId
	 * @param string $tableCode
	 * @param TableRights|null $rights null - the role inherits the rights of the table groups.
	 */
	public function __construct(
		public int $roleId,
		public string $tableCode,
		public ?TableRights $rights,
	)
	{
	}
}
