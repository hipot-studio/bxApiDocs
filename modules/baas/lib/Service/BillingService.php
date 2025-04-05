<?php

declare(strict_types=1);

namespace Bitrix\Baas\Service;

use Bitrix\Baas;
use Bitrix\Baas\UseCase\External\Exception;
use Bitrix\Main;

class BillingService
{
	private const LOCK_NAME = 'baas_sync';
	private const LOCK_LIMIT = 15;

	protected static ?self $instance = null;
	private mixed $backgroundJob = null;

	protected function __construct(
		protected Baas\Config\Client $clientConfigs,
		protected Baas\UseCase\External\UseCaseFactory $useCaseFactory,
		protected Baas\Repository\ConsumptionRepositoryInterface $consumptionRepository,
	)
	{
	}

	public function getLastSyncTime(): int
	{
		return $this->clientConfigs->getLastSyncTime();
	}

	/**
	 * @return Main\Result
	 * @throws Exception\BaasControllerException\ClientHostKeyIsNotRecognizedException
	 * @throws Exception\BaasControllerException\ClientHostNameIsNotRecognizedException
	 * @throws Exception\BaasControllerException\ClientIsNotRecognizedException
	 * @throws Main\DB\SqlQueryException
	 * @throws \Throwable
	 */
	public function synchronizeWithBilling(): Main\Result
	{
		$result = $this->fulfill(function () {
			return $this->useCaseFactory->createBillingDataGet(languageId: LANGUAGE_ID)();
		});

		$connection = Main\Application::getConnection();
		$connection->lock(self::LOCK_NAME, self::LOCK_LIMIT);
		$connection->startTransaction();

		try
		{
			$this->useCaseFactory->createBillingDataPurge()();

			$this->useCaseFactory->createBillingDataSave(
				services: $result->services,
				servicesAds: $result->servicesAds,
				packages: $result->packages,
				servicesInPackages: $result->servicesInPackages,
				purchases: $result->purchases,
				purchasedPackages: $result->purchasedPackages,
				servicesInPurchasedPackages: $result->servicesInPurchasedPackages,
			)();
			$connection->commitTransaction();
			$this->clientConfigs->setLastSyncTime(time());
		}
		catch (\Throwable)
		{
			$connection->rollbackTransaction();
		}
		finally
		{
			$connection->unlock(self::LOCK_NAME);
		}


		return new Main\Result();
	}

	/**
	 * @param array $billingBalance
	 * @return Main\Result
	 * @throws Main\DB\SqlQueryException
	 */
	public function applyBalance(array $billingBalance): Main\Result
	{
		$connection = Main\Application::getConnection();
		$connection->startTransaction();

		$result = $this->useCaseFactory->createBillingBalanceParse($billingBalance)();

		$this->useCaseFactory->createBillingBalanceSave(
			purchases: $result->purchases,
			purchasedPackages: $result->purchasedPackages,
			servicesInPurchasedPackages: $result->servicesInPurchasedPackages,
		)();

		$connection->commitTransaction();
		$this->clientConfigs->setLastSyncTime(time());

		return new Main\Result();
	}

	public function register(bool $force = false): Main\Result
	{
		if ($force === true || $this->isDomainRegistered() !== true)
		{
			Baas\Internal\Diag\Logger::getInstance()->info(
				'Registration start',
			);
			$result = $this->useCaseFactory->createRegisterDomain()();
			Baas\Internal\Diag\Logger::getInstance()->info(
				'Registration finish',
			);

			return $result;
		}

		return new Main\Result();
	}

	public function verify(): Main\Result
	{
		return $this->useCaseFactory->createVerifyDomain()();
	}

	public function getBaasSalesStatus(): Baas\UseCase\External\Response\GetBaasSalesStatusResult
	{
		return $this->fulfill(function() {
			return $this->useCaseFactory->createGetSalesStatus()();
		});
	}

	public function verifyAckDomain(string $ack, string $syn): Baas\UseCase\External\Response\VerifyAckDomainResult|Main\Result
	{
		/**
		 * @var Baas\UseCase\External\Response\VerifyAckDomainResult | Main\Result $result
		 */
		return $this->useCaseFactory->createVerifyAckDomain($syn, $ack)();
	}

	public function consumeService(
		Baas\Entity\Service $service,
		int $units,
		bool $force = false,
		?array $attributes = null,
	):
		Baas\UseCase\External\Response\ConsumeServiceResult
		|Baas\UseCase\Internal\Response\ConsumeServiceResult
	{
		//TODO Delete after migration period
		if ($this->needToMigrate())
		{
			$consumptionResult = (new Baas\UseCase\Internal\ConsumeService(
				consumptionRepository: $this->consumptionRepository,
			))(new Baas\UseCase\Internal\Request\ConsumeServiceRequest(
				service: $service,
				units: $units,
				force: $force,
			));
		}
		else
		{
			$consumptionResult = $this->fulfill(
				$this->useCaseFactory->createConsumeService(
					$service,
					$units,
					$force,
					$attributes,
				),
			);
		}

		return $consumptionResult;
	}

	public function refundService(
		Baas\Entity\Service $service,
		string $consumptionId,
		?array $attributes = null,
	):
		Baas\UseCase\External\Response\RefundServiceResult
		|Baas\UseCase\Internal\Response\RefundServiceResult
	{
		//TODO Delete after migration period
		if ($this->needToMigrate())
		{
			$refundingResult = (new Baas\UseCase\Internal\RefundService(
				consumptionRepository: $this->consumptionRepository,
			))(new Baas\UseCase\Internal\Request\RefundServiceRequest(
				service: $service,
				consumptionId: $consumptionId,
			));
		}
		else
		{
			$refundingResult = $this->fulfill($this->useCaseFactory->createRefundService(
				$service,
				$consumptionId,
				$attributes,
			));
		}

		return $refundingResult;
	}

	public function convertServiceBalance(
		Baas\Entity\Service $service,
		array $serviceBalanceWithTheState,
	): Baas\UseCase\External\Response\BillingServiceBalanceParseResult | Main\Result
	{
		return $this->useCaseFactory->createBillingServiceBalanceParse(
			$service,
			$serviceBalanceWithTheState,
		)();
	}


	protected function isDomainRegistered(): bool
	{
		return $this->clientConfigs->isRegistered();
	}

	public function getHostSecret(): ?string
	{
		[, $hostSecret] = array_values($this->clientConfigs->getRegistrationData());

		return $hostSecret ?? null;
	}

	public function needToMigrate(): bool
	{
		return $this->consumptionRepository->isEnabled()
			&& $this->clientConfigs->isConsumptionsLogMigrated() === false;
	}

	public function planToMigrate():void
	{
		if ($this->backgroundJob === null)
		{
			$this->backgroundJob = function () {
				$this->fulfill(
					$this->useCaseFactory->createMigrateConsumptionLog(
						$this->consumptionRepository,
					),
				);
			};
			Main\Application::getInstance()->addBackgroundJob($this->backgroundJob);
		}
	}

	private function fulfill(callable $callback, int $depth = 0): mixed
	{
		try
		{
			$this->checkRegistration();
			$result = $callback();
		}
		catch(
			Exception\BaasControllerException\ClientIsNotRecognizedException
			|Exception\BaasControllerException\ClientHostKeyIsNotRecognizedException
			|Exception\BaasControllerException\ClientHostNameIsNotRecognizedException
			$e
		)
		{
			$this->register(true);

			if ($depth < 1)
			{
				return $this->fulfill($callback, $depth + 1);
			}

			Baas\Internal\Diag\Logger::getInstance()->error(
				'This portal is not recognized by baas controller.',
				[
					'message' => $e->getMessage(),
					'code' => $e->getCode(),
					'customData' => $e->getCustomData(),
				],
			);

			throw $e;
		}
		catch (\Throwable $e)
		{
			Baas\Internal\Diag\Logger::getInstance()->error(
				'An error was detected during "fulfill" execution.',
				[
					'message' => $e->getMessage(),
					'code' => $e->getCode(),
					'type' => get_class($e),
					'file' => $e->getFile(),
					'line' => $e->getLine(),
				],
			);

			throw $e;
		}

		return $result;
	}

	private function checkRegistration(): void
	{
		$this->isDomainRegistered() || $this->register(true);
	}

	public static function getInstance(): static
	{
		if (!isset(static::$instance))
		{
			$client = new Baas\UseCase\External\Entity\Client();
			static::$instance = new static(
				$client->getConfigs(),
				new Baas\UseCase\External\UseCaseFactory(
					$client,
					Main\Loader::includeModule('bitrix24')
						? new Baas\UseCase\External\Entity\Bitrix24Server() : new Baas\UseCase\External\Entity\BusServer(),
					Baas\Repository\ServiceRepository::getInstance(),
					Baas\Repository\PackageRepository::getInstance(),
					Baas\Repository\PurchaseRepository::getInstance(),
				),
				Baas\Repository\ConsumptionRepository::getInstance(),
			);
		}

		return static::$instance;
	}
}
