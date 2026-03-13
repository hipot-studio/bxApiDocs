<?php

declare(strict_types=1);

namespace Bitrix\Baas\Public\Provider;

use Bitrix\Baas;
use Bitrix\Main\Provider\Params\PagerInterface;

class ServiceProvider
{
	use DailyBillingSyncTrait;

	/** @var Baas\Entity\Service[]  */
	private array $repo = [];
	protected Baas\Model\EO_Service_Collection $services;

	public function __construct(
		protected Baas\Repository\ServiceRepositoryInterface $serviceRepository,
		protected Baas\Repository\PurchaseRepositoryInterface $purchaseRepository,
	)
	{
		$this->checkAndSyncOncePerDay();
	}

	public function getByCode(string $code): Baas\Contract\Service
	{
		return $this->createService($code, null);
	}
	/**
	 * @return iterable<Baas\Contract\Service>
	 */
	public function getAll(): iterable
	{
		foreach ($this->getServices() as $service)
		{
			yield $this->createService($service->getCode(), $service);
		}
	}

	/**
	 * @param PagerInterface|null $pager
	 * @return Baas\Contract\Service[]
	 *
	 */
	public function getList(?PagerInterface $pager = null): array
	{
		$result = [];
		foreach ($this->serviceRepository->getList(
			offset: $pager?->getOffset(),
			limit: $pager?->getLimit(),
		) as $service)
		{
			$result[] = $this->createService($service->getCode(), $service);
		}

		return $result;
	}

	private function createService(string $code, ?Baas\Model\EO_Service $data): Baas\Entity\Service
	{
		if (!array_key_exists($code, $this->repo))
		{
			$data ??= $this->getServices()->getByPrimary($code);
			$this->repo[$code] = new Baas\Entity\Service($code, $data);
		}

		return $this->repo[$code];
	}

	private function getServices(): Baas\Model\EO_Service_Collection
	{
		if (!isset($this->services))
		{
			$this->services = $this->serviceRepository->getAll();
		}

		return $this->services;
	}

	public static function create(): static
	{
		return new static(
			Baas\Repository\ServiceRepository::getInstance(),
			Baas\Repository\PurchaseRepository::getInstance(),
		);
	}
}
