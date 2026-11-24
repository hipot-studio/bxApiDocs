<?php

namespace Bitrix\BIConnector\Public\Command\TableCell;

use Bitrix\BIConnector\Internal\Services\TableCell\TableCellService;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;

/**
 * Writes the cells of roles and tables: only the pairs of the changes, every other cell stays as it is.
 *
 * Opens no transaction and resets no cache: it runs inside the transaction of the caller, which notifies
 * about the changed rights after its commit.
 */
class SaveTableCellsCommand extends AbstractCommand
{
	/**
	 * @param TableCellChange[] $changes
	 */
	public function __construct(
		public readonly array $changes,
		private readonly ?TableCellService $service = null,
	)
	{
	}

	/**
	 * @return Result Data: `isChanged` - whether any row was written.
	 */
	protected function execute(): Result
	{
		return ($this->service ?? new TableCellService())->save($this->changes);
	}
}
