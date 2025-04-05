<?php

declare(strict_types=1);

namespace Bitrix\Baas\Service;

use Bitrix\Baas\Contract;
use Bitrix\Baas\Repository;
use Bitrix\Baas\UseCase;

class PurchaseService extends LocalService implements Contract\PurchaseService
{
	private static PurchaseService $instance;

	protected function __construct(protected Repository\PurchaseRepositoryInterface $purchaseRepository)
	{
		parent::__construct();
	}

	/**
	 * @inheritDoc
	 */
	public function getAll(): array
	{
		return (new UseCase\Internal\PackAvailablePurchaseInfo(
			$this->purchaseRepository
		))(
			new UseCase\Internal\Request\PackAvailablePurchaseInfoRequest(
				onlyEnabled: false,
			)
		)->getData();
	}

	/**
	 * @inheritDoc
	 */
	public function getByPackageCode(string $code): array
	{
		return (new UseCase\Internal\PackAvailablePurchaseInfo(
			$this->purchaseRepository
		))(
			new UseCase\Internal\Request\PackAvailablePurchaseInfoRequest(
				packageCode: $code,
			)
		)->getData();
	}

	/**
	 * @inheritDoc
	 */
	public function getNotExpired(): array
	{
		return (new UseCase\Internal\PackAvailablePurchaseInfo(
			$this->purchaseRepository
		))(
			new UseCase\Internal\Request\PackAvailablePurchaseInfoRequest(
				onlyEnabled: true,
			)
		)->getData();
	}

	public function notifyAboutPurchase(string $packageCode, string $purchaseCode): void
	{
		(new UseCase\Internal\NotifyAboutPurchase(
			$this->purchaseRepository
		))(
			new UseCase\Internal\Request\NotifyAboutPurchaseRequest(
				$packageCode,
				$purchaseCode,
			)
		);
	}

	public static function getInstance(): static
	{
		if (!isset(self::$instance))
		{
			self::$instance = new static(
				Repository\PurchaseRepository::getInstance(),
			);
		}

		return self::$instance;
	}
}
