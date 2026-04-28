<?php

namespace Bitrix\Intranet\Infrastructure\Agent;

use Bitrix\Intranet\Internal\Repository\AnnualSummaryRepository;
use Bitrix\Intranet\Internal\Entity\AnnualSummary\SummaryInterface;
use Bitrix\Intranet\Public\Event\OnInitSummaryProvidersEvent;
use Bitrix\Intranet\Public\Provider\AnnualSummary\SummaryProviderInterface;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Entity\EntityCollection;
use Bitrix\Main\EventResult;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Update\Stepper;
use Bitrix\Main\UserTable;

class CalcAnnualSummary extends Stepper
{
	protected static $moduleId = "intranet";
	private Date $from;
	private Date $to;
	private Date $closeDate;
	private array $providers;
	private AnnualSummaryRepository $annualSummaryRepository;

	public function __construct()
	{
		$this->closeDate = new Date('31.12.2026', 'd.m.Y');
		$this->from = new Date('01.01.2026', 'd.m.Y');
		$this->to = new Date('01.12.2026', 'd.m.Y');
		$this->providers = $this->getProviders();
		$this->annualSummaryRepository = new AnnualSummaryRepository();
	}

	/**
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function execute(array &$option): bool
	{
		if ((new Date()) >= $this->closeDate)
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

		if (empty($option['providerIdList']))
		{
			$option['providerIdList'] = array_keys($this->providers);
		}
		$provider = $this->findProviderToOption($option);
		if (!$provider)
		{
			return self::FINISH_EXECUTION;
		}
		$userIds = $this->getUserIds((int)$option['lastId'], $provider->getUserIdLimit());

		$collection = $provider->provide($userIds);
		$this->annualSummaryRepository->saveCollection($collection);

		$option['lastId'] = $provider->getLastUserId();

		if (count($userIds) < $provider->getUserIdLimit())
		{
			$option['providerIndex']++;
		}

		return self::CONTINUE_EXECUTION;
	}

	/**
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @throws ArgumentException
	 */
	private function getUserIds($lastId = 0, int $step = 5): array
	{
		return UserTable::query()
			->setSelect(['ID'])
			->where('REAL_USER', 'expr', true)
			->addFilter('ACTIVE', 'Y')
			->addFilter('>ID', $lastId)
			->addFilter('<DATE_REGISTER', new DateTime('2025-10-01', 'Y-m-d'))
			->setLimit($step)
			->addOrder('ID')
			->fetchCollection()
			->getIdList()
		;
	}

	/**
	 * @return SummaryInterface[]
	 */
	private function getProviders(): array
	{
		/** @var SummaryProviderInterface[] $providers */
		$providers = [];
		$event = new OnInitSummaryProvidersEvent($this->from, $this->to);
		$event->send();
		
		foreach ($event->getResults() as $result)
		{
			if ($result->getType() === EventResult::SUCCESS)
			{
				$provider = $result->getParameters()['provider'] ?? [];
				if ($provider instanceof SummaryProviderInterface)
				{
					$providers[$provider->getId()] = $provider;
				}
			}
		}

		return $providers;
	}

	private function getProviderById(string $id): ?SummaryProviderInterface
	{
		return $this->providers[$id];
	}

	private function findProviderToOption(array $option): ?SummaryProviderInterface
	{
		$providerIndex = $option['providerIndex'];
		$providerId = $option['providerIdList'][$providerIndex] ?? null;

		return $providerId ? $this->getProviderById($providerId) : null;
	}
}
