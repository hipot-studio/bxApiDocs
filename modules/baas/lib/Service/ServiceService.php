<?php

declare(strict_types=1);

namespace Bitrix\Baas\Service;

use Bitrix\Main;
use Bitrix\Baas;
use Bitrix\Baas\UseCase\External;
use Bitrix\Baas\UseCase\Internal;

class ServiceService extends LocalService implements Baas\Contract\ServiceService
{
	/** @var Baas\Entity\Service[]  */
	private array $repo = [];
	protected Baas\Model\EO_Service_Collection $services;

	public function __construct(
		protected Baas\Service\BillingService $billingService,
		protected Baas\Service\ProxyService $proxyService,
		protected Baas\Repository\ServiceRepositoryInterface $serviceRepository,
		protected Baas\Repository\PurchaseRepositoryInterface $purchaseRepository,
	)
	{
		parent::__construct();
	}

	public function getByCode(string $code): Baas\Contract\Service
	{
		if (!array_key_exists($code, $this->repo))
		{
			$this->repo[$code] = new Baas\Entity\Service($code, $this->getServiceDataByCode($code));
		}

		return $this->repo[$code];
	}

	public function getAll(): iterable
	{
		$services = Baas\Model\ServiceTable::getList(['select' => ['CODE'], 'order' => ['CODE' => 'ASC']]);

		foreach ($services as $service)
		{
			yield $this->getByCode($service['CODE']);
		}
	}

	public function consumeService(
		Baas\Contract\Service $service,
		int $units,
		bool $force = false,
		?array $attributes = null,
	): External\Response\ConsumeServiceResult
		|Internal\Response\ConsumeServiceResult
		|Main\Result
	{
		Baas\Internal\Diag\Logger::getInstance()->info(
			'Consumption begins',
			['service' => $service->getCode(), 'units' => $units],
		);

		$result = $this->billingService->consumeService($service, $units, $force, $attributes);

		Baas\Internal\Diag\Logger::getInstance()->info('Consumption has finished', [
			'succeeded' => $result->isSuccess(),
			'errors' => $result->getErrorMessages(),
			'consumptionId' => $result->consumptionId,
			'units' => $units],
		);

		if ($result instanceof External\Response\ConsumeServiceResult)
		{
			$this->updateBalance(
				$service,
				$result->stateNumber,
				$result->serviceInPurchasedPackages,
			);
		}

		return $result;
	}

	public function refundService(
		Baas\Contract\Service $service,
		string $consumptionId,
		?array $attributes = null,
	):  External\Response\RefundServiceResult
		|Internal\Response\RefundServiceResult
		|Main\Result
	{
		$result = $this->billingService->refundService($service, $consumptionId, $attributes);

		if (
			$result->isSuccess()
			&& $result instanceof External\Response\RefundServiceResult
		)
		{
			$this->updateBalance(
				$service,
				$result->stateNumber,
				$result->serviceInPurchasedPackages,
			);
		}

		return $result;
	}

	public function applyProxyState(
		Baas\Entity\Service $service,
		array $proxyStateRawResponse,
	): Main\Result
	{
		$result = $this->proxyService->convertServiceBalance($service, $proxyStateRawResponse);
		if ($result->isSuccess())
		{
			$this->updateBalance(
				$service,
				$result->stateNumber,
				$result->serviceInPurchasedPackages,
			);
		}

		return $result;
	}

	public function applyBillingServiceBalance(
		Baas\Entity\Service $service,
		array $billingServiceBalance,
	): void
	{
		$result = $this->billingService->convertServiceBalance($service, $billingServiceBalance);

		if ($result->isSuccess())
		{
			$this->updateBalance(
				$service,
				$result->stateNumber,
				$result->serviceInPurchasedPackages,
			);
		}
	}

	public function getAdsInfo(Baas\Entity\Service $service, string $languageId): ?Baas\Model\EO_ServiceAds
	{
		$result = (new Internal\GetAdsInfo(serviceRepository: $this->serviceRepository))(
			new Internal\Request\GetAdsInfoRequest(
				service: $service,
				languageId: $languageId,
			)
		);

		return $result->serviceAds ?? null;
	}

	protected function updateBalance(
		Baas\Entity\Service $service,
		int $stateNumber,
		Baas\Model\EO_ServiceInPurchasedPackage_Collection $serviceInPurchasedPackages,
	): void
	{
		(new Internal\SetServiceBalance(
			serviceRepository: $this->serviceRepository,
			purchaseRepository: $this->purchaseRepository,
		))(
			new Internal\Request\SetServiceBalanceRequest(
				service: $service,
				stateNumber: $stateNumber,
				serviceInPurchasedPackages: $serviceInPurchasedPackages,
			)
		);
	}

	private function getServiceDataByCode(string $code): ?Baas\Model\EO_Service
	{
		if (!isset($this->services))
		{
			$this->services = Baas\Model\ServiceTable::getList([
				'select' => ['*'],
			])->fetchCollection();
		}

		return $this->services->getByPrimary($code) ?? null;
	}
}
