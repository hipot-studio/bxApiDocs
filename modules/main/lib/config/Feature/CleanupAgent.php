<?php

namespace Bitrix\Main\Config\Feature;

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;

/**
 * Once per day clears records from FeatureFlagTable and FeatureFlagRuleTable,
 * where related feature class not exist in codebase.
 */
final class CleanupAgent
{
	public function __construct(
		private readonly Factory $flagFactory,
	)
	{
	}

	public static function run(): ?string
	{
		$instance = new static(
			ServiceLocator::getInstance()->get(Factory::class),
		);

		$result = $instance->runInternal();

		if ($result === false)
		{
			return null;
		}

		return __METHOD__ . '();';
	}

	private function runInternal(): bool
	{
		$this->cleanupRules();
		$this->cleanupFlagValues();

		return true;
	}

	private function cleanupRules(): void
	{
		$rows = FeatureFlagRuleTable::query()
			->setSelect(['ID', 'RULE_CODE', 'FEATURE_CODE'])
			->setOrder(['ID' => 'ASC'])
			->exec();

		$removeIds = [];

		while ($row = $rows->fetch())
		{
			$flagExists = $this->flagFactory->isFlagExists($row['FEATURE_CODE']);
			$ruleExists = $this->flagFactory->isRuleExists($row['RULE_CODE']);

			if (!$flagExists || !$ruleExists)
			{
				$removeIds[] = (int)$row['ID'];
			}
		}

		if (empty($removeIds))
		{
			return;
		}

		$filter = (new ConditionTree())->whereIn('ID', $removeIds);
		FeatureFlagRuleTable::deleteByFilter($filter);
	}

	private function cleanupFlagValues(): void
	{
		$rows = FeatureFlagTable::query()
			->setSelect(['CODE'])
			->setOrder(['CODE' => 'ASC'])
			->exec();

		$removeCodes = [];

		while ($row = $rows->fetch())
		{
			if (!$this->flagFactory->isFlagExists($row['CODE']))
			{
				$removeCodes[] = $row['CODE'];
			}
		}

		if (empty($removeCodes))
		{
			return;
		}

		$filter = (new ConditionTree())->whereIn('CODE', $removeCodes);
		FeatureFlagTable::deleteByFilter($filter);
	}
}
