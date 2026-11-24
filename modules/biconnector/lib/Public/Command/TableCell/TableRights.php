<?php

namespace Bitrix\BIConnector\Public\Command\TableCell;

/**
 * Operations a role is allowed on one table when the role overrides the rights of its table groups.
 */
final readonly class TableRights
{
	public function __construct(
		public bool $view,
		public bool $edit,
		public bool $export,
	)
	{
	}
}
