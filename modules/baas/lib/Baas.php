<?php

namespace Bitrix\Baas;

use Bitrix\Baas\Service\PackageService;
use Bitrix\Baas\Service\PurchaseService;
use Bitrix\Main;

class Baas
{
	use Internal\Trait\SingletonConstructor;

	protected bool $registered;
	protected Contract\License $license;
	protected Config\Client $config;
	protected Service\ServiceService $serviceManager;
	protected Service\InstantMessageService $imService;

	protected function __construct(Contract\License $license, Config\Client $config)
	{
		$this->license = $license;
		$this->config = $config;
		$this->registered = $this->config->isRegistered();

		$eventManager = Main\EventManager::getInstance();
		$eventManager->addEventHandler('baas', 'onServiceBalanceChanged', function ($event) {$this->sendMessage($event);});
	}

	public function isAvailable(): bool
	{
		return $this->license->isBaasAvailable();
	}

	public function isActive(): bool
	{
		return $this->license->isActive();
	}

	public function isRegistered(): bool
	{
		return $this->registered;
	}

	public function isSellableToAll(): bool
	{
		return $this->license->isSellableToAll();
	}

	/** Fast snippet:
if (\Bitrix\Main\Loader::includeModule('baas'))
{
	\Bitrix\Baas\Baas::getInstance()->sync();
}
	 */
	public function sync(bool $force = true): Main\Result
	{
		if ($this->isAvailable())
		{
			if ($force === true)
			{
				return Service\BillingSynchronizationService::getInstance()->sync();
			}

			return Service\BillingSynchronizationService::getInstance()->syncIfNeeded();
		}

		return (new Main\Result())->addError(new Main\Error('Baas is not available'));
	}



	/**
	 * @return array<Contract\Purchase>
	 */
	public function getNotExpiredPurchases(): array
	{
		if ($this->isAvailable())
		{
			return PurchaseService::getInstance()->getNotExpired();
		}

		return [];
	}

	/**
	 * @return array<Contract\Package>
	 */
	public function getPackagesWithActivePurchases(): array
	{
		$allPackages = PackageService::getInstance()->getAll();
		$packagesWithActivePurchases = [];

		foreach ($allPackages as $package)
		{
			if (count($package->getPurchasedServices()) > 0)
			{
				$packagesWithActivePurchases[] = $package;
			}
		}

		return $packagesWithActivePurchases;
	}

	/**
	 * @return array<Contract\Package>
	 */
	public function getPackagesWithoutActivePurchases(): array
	{
		$allPackages = PackageService::getInstance()->getAll();
		$packagesWithoutActivePurchases = [];

		foreach ($allPackages as $package)
		{
			if ($package->getPurchaseInfo()->getCount() === 0)
			{
				$packagesWithoutActivePurchases[] = $package;
			}
		}

		return $packagesWithoutActivePurchases;
	}

	public function getServiceManager(): Contract\ServiceService
	{
		if (!isset($this->serviceManager))
		{
			$this->serviceManager = new Service\ServiceService(
				Service\BillingService::getInstance(),
				Service\ProxyService::getInstance(),
				Repository\ServiceRepository::getInstance(),
				Repository\PurchaseRepository::getInstance(),
			);
		}

		return $this->serviceManager;
	}

	public function getService(string $serviceCode): Contract\Service
	{
		return $this->getServiceManager()->getByCode($serviceCode);
	}

	protected function getIMService(): Service\InstantMessageService
	{
		if (!isset($this->imService))
		{
			$this->imService = new Service\InstantMessageService();
		}

		return$this->imService;
	}

	protected function sendMessage(Main\Event $event): void
	{
		/**@var Entity\Service $service */
		$service = $event->getParameter('service');
		$purchases = $this->getNotExpiredPurchases();
		$packages = $this->getPackagesWithActivePurchases();
		$this
			->getIMService()
			->sendBalanceMessage(
				$service,
				$packages,
				$purchases,
			)
		;
	}

	/**
	 * @return mixed
	 * @throws Main\LoaderException
	 */
	public static function getInstance(): static
	{
		if (!isset(static::$instance))
		{
			$configs = new Config\Client();
			$cloudLicense = new Integration\Bitrix24\License($configs);
			$license = $cloudLicense->isAvailable() ? $cloudLicense : new Integration\Main\License($configs);

			static::$instance = new self($license, $configs);
		}

		return static::$instance;
	}

	/**
	 * @deprecated
	 * @return Contract\Purchase[]
	 */
	public function getPurchases(): array
	{
		return $this->getNotExpiredPurchases();
	}
}
