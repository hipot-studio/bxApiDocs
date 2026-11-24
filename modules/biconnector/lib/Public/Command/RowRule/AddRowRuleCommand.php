<?php

namespace Bitrix\BIConnector\Public\Command\RowRule;

use Bitrix\BIConnector\Internal\Services\RowRule\RowRuleService;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;

/**
 * Creates a row rule. Access of users and roles stays as it is: a rule only describes rows.
 */
class AddRowRuleCommand extends AbstractCommand
{
	/**
	 * @param array $fields Fields of the API `add` request.
	 * @param int $userId Author of the rule.
	 */
	public function __construct(
		public readonly array $fields,
		public readonly int $userId,
	)
	{
	}

	protected function execute(): Result
	{
		$service = new RowRuleService();
		$result = $service->add($this->fields, $this->userId);
		if (!$result->isSuccess())
		{
			return $result;
		}

		[$model] = $service->present([$result->getData()['rule']]);

		return (new Result())->setData(['rule' => $model]);
	}
}
