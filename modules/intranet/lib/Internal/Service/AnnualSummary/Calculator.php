<?php

namespace Bitrix\Intranet\Internal\Service\AnnualSummary;

use Bitrix\Intranet\Internal\Entity\AnnualSummary\Collection;
use Bitrix\Intranet\Internal\Repository\AnnualSummaryRepository;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Type\DateTime;

class Calculator
{
	private Collection $yearResults;
	private AnnualSummaryRepository $storage;

	public function __construct(
		private int $userId,
	) {
		$this->storage = new AnnualSummaryRepository($this->userId);
		$this->yearResults = $this->storage->load();
	}

	/**
	 * @throws ArgumentException
	 */
	public function calc(array $providers, DateTime $from, DateTime $to): void
	{
		foreach ($providers as $provider)
		{
			if ($provider->isAvailable())
			{
				$this->yearResults->addUnique($provider->getFeatureSummary($this->userId, $from, $to));
			}
		}
		$this->store();
	}

	public function calcOne(AbstractFeatureProvider $provider, DateTime $from, DateTime $to): void
	{
		$this->yearResults->addUnique($provider->getFeatureSummary($this->userId, $from, $to));
		$this->store();
	}

	public function calcPart(AbstractFeatureProvider $provider, DateTime $from, DateTime $to, $lastId): array
	{
		return $provider->partCalc($this->userId, $from, $to, $lastId);
	}

	/**
	 * @throws ArgumentException
	 */
	public function saveValue(AbstractFeatureProvider $provider, int $value): void
	{
		$feature = $provider->createFeature($value);
		$this->yearResults->addUnique($feature);
		$this->store();
	}

	/**
	 * @throws ArgumentException
	 */
	private function store(): void
	{
		try
		{
			$this->yearResults->sortByRate();
			$this->storage->store($this->yearResults);
		}
		catch (\Exception)
		{
		}
	}
}
