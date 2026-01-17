<?php

namespace Bitrix\Intranet\Infrastructure\Agent;

use Bitrix\Intranet\Internal\Integration\Crm\AnnualSummary\DealProvider;
use Bitrix\Intranet\Internal\Integration\Im\AnnualSummary\ChatProvider;
use Bitrix\Intranet\Internal\Integration\Im\AnnualSummary\CopilotMessageProvider;
use Bitrix\Intranet\Internal\Integration\Im\AnnualSummary\MessageProvider;
use Bitrix\Intranet\Internal\Integration\Im\AnnualSummary\ReactionProvider;
use Bitrix\Intranet\Internal\Integration\Landing\AnnualSummary\SiteProvider;
use Bitrix\Intranet\Internal\Integration\Socialnetwork\AnnualSummary\WorkgroupProvider;
use Bitrix\Intranet\Internal\Integration\Stafftrack\AnnualSummary\ShiftProvider;
use Bitrix\Intranet\Internal\Integration\Tasks\AnnualSummary\TaskProvider;
use Bitrix\Intranet\Internal\Integration\Workflow\AnnualSummary\WorkflowProvider;
use Bitrix\Intranet\Internal\Service\AnnualSummary\AbstractFeatureProvider;
use Bitrix\Intranet\Internal\Service\AnnualSummary\Calculator;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Update\Stepper;
use Bitrix\Main\UserTable;

class CalcAnnualSummary extends Stepper
{
	protected static $moduleId = "intranet";
	private int $limit = 1;

	/**
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function execute(array &$option): bool
	{
		if ((new DateTime()) >= new DateTime('31.12.2025', 'd.m.Y'))
		{
			return self::FINISH_EXECUTION;
		}
		if (empty($option))
		{
			$option["steps"] = 0;
			$option["count"] = 1;
			$option['lastId'] = 0;
		}
		$option['providerIndex'] ??= 0;
		$option['partLastId'] ??= 0;
		$option['partValue'] ??= 0;

		$userIds = $this->getUserIds($option['lastId']);
		$from = new DateTime('01.01.2025', 'd.m.Y');
		$to = new DateTime('01.12.2025', 'd.m.Y');

		foreach ($userIds as $userId)
		{
			$calculator = new Calculator($userId);
			while (true)
			{
				if (!($provider = $this->getProviderByIndex($option['providerIndex'])))
				{
					$option['providerIndex'] = 0;
					break;
				}
				if (!$provider->isAvailable())
				{
					++$option['providerIndex'];
					continue;
				}
				if (!$provider->needPartCalc())
				{
					$calculator->calcOne($provider, $from, $to);
					++$option['providerIndex'];

					continue;
				}
				[$partLastId, $partValue] = $calculator->calcPart($provider, $from, $to, $option['partLastId'] ?? 0);
				if ($partLastId !== $option['partLastId'])
				{
					$option['partValue'] += $partValue;
					$option['partLastId'] = $partLastId;

					return self::CONTINUE_EXECUTION;
				}
				else
				{
					$calculator->saveValue($provider, $option['partValue'] ?? 0);
					$option['partValue'] = 0;
					$option['partLastId'] = 0;
					++$option['providerIndex'];
				}
			}

			$option['lastId'] = $userId;
		}

		return count($userIds) < $this->limit ? self::FINISH_EXECUTION : self::CONTINUE_EXECUTION;
	}

	/**
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @throws ArgumentException
	 */
	private function getUserIds($lastId = 0): array
	{
		return UserTable::query()
			->setSelect(['ID'])
			->addFilter('=IS_REAL_USER', 'Y')
			->addFilter('ACTIVE', 'Y')
			->addFilter('>ID', $lastId)
			->addFilter('<DATE_REGISTER', new DateTime('2025-10-01', 'Y-m-d'))
			->setLimit($this->limit)
			->addOrder('ID')
			->fetchCollection()
			->getIdList()
		;
	}

	private function getProviders(): array
	{
		return [
			new ChatProvider(),
			new MessageProvider(),
			new ReactionProvider(),
			new CopilotMessageProvider(),
			new DealProvider(),
			new SiteProvider(),
			new ShiftProvider(),
			new TaskProvider(),
//			new WorkflowProvider(),
			new WorkgroupProvider(),
		];
	}

	private function getProviderByIndex(int $index): ?AbstractFeatureProvider
	{
		return $this->getProviders()[$index] ?? null;
	}
}
