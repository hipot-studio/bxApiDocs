<?php

namespace Bitrix\BIConnector\Public\Command\RowRule;

use Bitrix\BIConnector\Internal\Services\RowRule\RowRuleService;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;

/**
 * Deletes a row rule.
 */
class DeleteRowRuleCommand extends AbstractCommand
{
	public function __construct(
		public readonly int $id,
	)
	{
	}

	/**
	 * @return Result Data: `id` of the deleted rule.
	 */
	protected function execute(): Result
	{
		return (new RowRuleService())->delete($this->id);
	}
}
