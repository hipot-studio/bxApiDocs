<?php

namespace Bitrix\BIConnector\Public\Command\RowRule;

use Bitrix\BIConnector\Internal\Services\RowRule\RowRuleService;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;

/**
 * Replaces a row rule whole. The author stays, the user becomes the last editor.
 */
class UpdateRowRuleCommand extends AbstractCommand
{
	/**
	 * @param int $id
	 * @param array $fields The whole rule, as in `add`.
	 * @param int $userId Author of the change.
	 */
	public function __construct(
		public readonly int $id,
		public readonly array $fields,
		public readonly int $userId,
	)
	{
	}

	protected function execute(): Result
	{
		$service = new RowRuleService();
		$result = $service->update($this->id, $this->fields, $this->userId);
		if (!$result->isSuccess())
		{
			return $result;
		}

		[$model] = $service->present([$result->getData()['rule']]);

		return (new Result())->setData(['rule' => $model]);
	}
}
