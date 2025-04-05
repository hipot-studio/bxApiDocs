<?php

declare(strict_types=1);

namespace Bitrix\Baas\Repository;

use Bitrix\Baas;
use Bitrix\Baas\Model;
use Bitrix\Main;

class PurchaseRepository implements PurchaseRepositoryInterface
{
	use Baas\Internal\Trait\SingletonConstructor;

	protected function __construct()
	{
	}

	public function purge(): void
	{
		Model\PurchaseTable::deleteBatch(['!CODE' => null]);
		Model\PurchasedPackageTable::deleteBatch(['!CODE' => null]);
		Model\ServiceInPurchasedPackageTable::deleteBatch(['!PURCHASED_PACKAGE_CODE' => null]);
	}

	public function save(
		Baas\Model\EO_Purchase_Collection $purchases,
		Baas\Model\EO_PurchasedPackage_Collection $purchasedPackages,
		Baas\Model\EO_ServiceInPurchasedPackage_Collection $servicesInPurchasedPackages,
	): void
	{
		foreach ([
			$purchases,
			$purchasedPackages,
			$servicesInPurchasedPackages,
		] as $collection)
		{
			$result = $collection->save();
			if (!$result->isSuccess())
			{
				throw new Main\SystemException(
					'Error saving purchases: ' . implode(' ', $result->getErrorMessages()),
				);
			}
		}
	}

	public function update(
		Baas\Model\EO_Purchase_Collection $purchases,
		Baas\Model\EO_PurchasedPackage_Collection $purchasedPackages,
		Baas\Model\EO_ServiceInPurchasedPackage_Collection $servicesInPurchasedPackages,
	): void
	{
		$existedPurchases = Baas\Model\PurchaseTable::query()
			->setSelect(['CODE'])
			->whereIn('CODE', $purchases->getCodeList())
			->fetchCollection()
		;
		foreach ($purchases as $purchase)
		{
			if (!$existedPurchases || !$existedPurchases->getByPrimary(['CODE' => $purchase->getCode()]))
			{
				Baas\Model\PurchaseTable::insertIgnore([
					'CODE' => $purchase->getCode(),
					'PURCHASE_URL' => $purchase->getPurchaseUrl() ?? '',
				]);
			}
		}

		$existedPurchasedPackages = Baas\Model\PurchasedPackageTable::query()
			->setSelect(['CODE'])
			->whereIn('CODE', $purchasedPackages->getCodeList())
			->fetchCollection()
		;
		$cleanCache = false;
		foreach ($purchasedPackages as $purchasedPackage)
		{
			if (
				!$existedPurchasedPackages
				|| !$existedPurchasedPackages->getByPrimary(['CODE' => $purchasedPackage->getCode()])
			)
			{
				Baas\Model\PurchasedPackageTable::insertIgnore([
					'CODE' => $purchasedPackage->getCode(),
					'PACKAGE_CODE' => $purchasedPackage->getPackageCode(),
					'PURCHASE_CODE' => $purchasedPackage->getPurchaseCode(),
					'START_DATE' => $purchasedPackage->getStartDate(),
					'EXPIRATION_DATE' => $purchasedPackage->getExpirationDate(),
				]);
				$cleanCache = true;
			}
		}

		if ($cleanCache)
		{
			Baas\Model\PurchasedPackageTable::cleanCache();
		}

		foreach ($servicesInPurchasedPackages as $serviceInPurchasedPackage)
		{
			Baas\Model\ServiceInPurchasedPackageTable::insertUpdate(
				[
					'PURCHASED_PACKAGE_CODE' => $serviceInPurchasedPackage->getPurchasedPackageCode(),
					'SERVICE_CODE' => $serviceInPurchasedPackage->getServiceCode(),
					'CURRENT_VALUE' => $serviceInPurchasedPackage->getCurrentValue(),
					'STATE_NUMBER' => 0,
				],
				[
					'CURRENT_VALUE' => $serviceInPurchasedPackage->getCurrentValue(),
					'STATE_NUMBER' => 0,
				],
			);
		}
	}

	public function recalculateBalance(): void
	{
		// $service = null; //Baas\Model\EO_Service $service;
		$serviceQuery = Baas\Model\ServiceInPurchasedPackageTable::query()
			->setSelect([
				'SERVICE_CODE',
				new Main\ORM\Fields\ExpressionField('MAXIMALDATE', 'MAX(%s)', 'PURCHASED_PACKAGE.EXPIRATION_DATE'),
				new Main\ORM\Fields\ExpressionField('MAXIMALV', 'SUM(%s)', 'SERVICES_IN_PACKAGE.VALUE'),
			])
			->addGroup('SERVICE_CODE')
		//	->where('CURRENT_VALUE', '>', 0)
		;
		// if ($service !== null)
		// {
		// 	$serviceQuery->where('SERVICE_CODE', $service->getCode());
		// }
		$activeServices = $serviceQuery->exec()->fetchAll();
		$activeServices = array_combine(
			array_column($activeServices, 'SERVICE_CODE'),
			$activeServices,
		);

		$actualValues = Baas\Model\ServiceInPurchasedPackageTable::query()
			->setSelect([
				'SERVICE_CODE',
				new Main\ORM\Fields\ExpressionField('CURRENTV', 'SUM(%s)', 'CURRENT_VALUE'),
				// Maximal value for today
				// new Main\ORM\Fields\ExpressionField('MAXIMALV', 'SUM(%s)', 'SERVICES_IN_PACKAGE.VALUE'),
				new Main\ORM\Fields\ExpressionField('MAXIMAL_STATE_NUMBER', 'MAX(%s)', 'STATE_NUMBER'),
			])
			->where('CURRENT_VALUE', '>=', 0)
			->whereIn('SERVICE_CODE', array_keys($activeServices))
			->where('PURCHASED_PACKAGE.ACTUAL', 'Y')
			->addGroup('SERVICE_CODE')
			->exec()
			->fetchAll()
		;
		$actualValues = array_combine(
			array_column($actualValues, 'SERVICE_CODE'),
			array_column($actualValues, 'CURRENTV'),
		);
		$stateNumbers = array_combine(
			array_column($actualValues, 'SERVICE_CODE'),
			array_column($actualValues, 'MAXIMAL_STATE_NUMBER'),
		);

		foreach ($activeServices as $activeService)
		{
			Baas\Model\ServiceTable::update($activeService['SERVICE_CODE'], [
				'CURRENT_VALUE' => $actualValues[$activeService['SERVICE_CODE']] ?? 0,
				'MAXIMAL_VALUE' => $activeService['MAXIMALV'],
				'EXPIRATION_DATE' => $activeService['MAXIMALDATE'],
				'STATE_NUMBER' => (int) ($stateNumbers[$activeService['SERVICE_CODE']] ?? 0),
			]);
		}

		$data = Baas\Model\ServiceInPurchasedPackageTable::query()
			->setSelect([
				'CURRENTV' => new Main\ORM\Fields\ExpressionField('CURRENTV', 'SUM(%s)', 'CURRENT_VALUE'),
				'PURCHASED_PACKAGE_CODE',
			])
			->where('PURCHASED_PACKAGE.ACTIVE', 'Y')
			->where('SERVICE.RENEWABLE', 'N')
			->setGroup(['PURCHASED_PACKAGE_CODE'])
			->exec()
			->fetchAll()
		;
		$needToDeactivate = array_filter($data, static function($item) { return $item['CURRENTV'] <= 0; });
		if ($needToDeactivate)
		{
			$needToDeactivate = array_column($needToDeactivate, 'PURCHASED_PACKAGE_CODE');
			Baas\Model\PurchasedPackageTable::updateBatch(
				['ACTIVE' => 'N'],
				[
					'@CODE' => $needToDeactivate,
				],
			);
		}
	}

	public function updateByStateNumber(
		Baas\Model\EO_ServiceInPurchasedPackage_Collection $servicesInPurchasedPackages,
		int $stateNumber,
	): void
	{
		$recalculateBalance = false;
		foreach ($servicesInPurchasedPackages as $serviceInPurchasedPackage)
		{
			$updateResult = Baas\Model\ServiceInPurchasedPackageTable::updateBatch([
				'CURRENT_VALUE' => $serviceInPurchasedPackage->getCurrentValue(),
				'STATE_NUMBER' => $stateNumber,
			], [
				'=SERVICE_CODE' => $serviceInPurchasedPackage->getServiceCode(),
				'=PURCHASED_PACKAGE_CODE' => $serviceInPurchasedPackage->getPurchasedPackageCode(),
				[
					'LOGIC' => 'OR',
					'<STATE_NUMBER' => $stateNumber,
					'STATE_NUMBER' => null,
				],
			]);

			if ($updateResult->getAffectedRowsCount() > 0)
			{
				$recalculateBalance = true;
			}
		}

		if ($recalculateBalance)
		{
			$this->recalculateBalance();
		}
	}

	public function getAvailable(): Baas\Model\EO_ServiceInPurchasedPackage_Collection
	{
		return $this->getNotExpiredServicesQuery()
			->where(Main\ORM\Query\Query::filter()
				->logic('OR')
				->where('CURRENT_VALUE', '>', 0)
				->where('SERVICE.RENEWABLE', 'Y')
			)
			->exec()
			->fetchCollection()
		;
	}

	public function getAvailableByPackageCode(
		string $packageCode,
	): Baas\Model\EO_ServiceInPurchasedPackage_Collection
	{
		return $this->getNotExpiredServicesQuery()
			->where(Main\ORM\Query\Query::filter()
				->logic('OR')
				->where('CURRENT_VALUE', '>', 0)
				->where('SERVICE.RENEWABLE', 'Y')
			)
			->where('PURCHASED_PACKAGE.PACKAGE_CODE', $packageCode)
			->exec()
			->fetchCollection()
		;
	}

	public function getNotExpiredPurchases(): Baas\Model\EO_ServiceInPurchasedPackage_Collection
	{
		return $this->getNotExpiredServicesQuery()
			->exec()
			->fetchCollection()
		;
	}

	public function getServicesInPurchase(string $packageCode, string $purchaseCode): array
	{
		return Baas\Model\ServiceInPurchasedPackageTable::query()
			->setSelect([
				'SERVICE_CODE',
				new Main\ORM\Fields\ExpressionField('CURRENTV', 'SUM(%s)', 'CURRENT_VALUE'),
				new Main\ORM\Fields\ExpressionField('WILL_START', 'MIN(%s)', 'PURCHASED_PACKAGE.START_DATE'),
				new Main\ORM\Fields\ExpressionField('WILL_EXPIRED', 'MAX(%s)', 'PURCHASED_PACKAGE.EXPIRATION_DATE'),
			])
			->where('PURCHASED_PACKAGE.PACKAGE_CODE', $packageCode)
			->where('PURCHASED_PACKAGE.PURCHASE_CODE', $purchaseCode)
			->where('CURRENT_VALUE', '>', 0)
			->where('PURCHASED_PACKAGE.EXPIRED', 'N')
			->addGroup('SERVICE_CODE')
			->exec()
			->fetchAll()
		;
	}

	public function findPurchaseByPurchasedPackageCode(string $purchasedPackageCode): ?Baas\Model\EO_Purchase
	{
		$purchase = Baas\Model\PurchaseTable::query()
			->setSelect(['CODE'])
			->where('PURCHASED_PACKAGE.CODE', $purchasedPackageCode)
			->fetchObject()
		;

		return ($purchase instanceof Baas\Model\EO_Purchase) ? $purchase : null;
	}

	public function findPurchaseByCode(string $purchaseCode): ?Baas\Model\EO_Purchase
	{
		$purchase = Baas\Model\PurchaseTable::query()
			->setSelect(['CODE'])
			->where('CODE', $purchaseCode)
			->fetchObject()
		;

		return ($purchase instanceof Baas\Model\EO_Purchase) ? $purchase : null;
	}

	private function getNotExpiredServicesQuery(): Main\ORM\Query\Query
	{
		return Baas\Model\ServiceInPurchasedPackageTable::query()
			->setSelect([
				'PURCHASED_PACKAGE_CODE',
				'SERVICE_CODE',
				'CURRENT_VALUE',
				'PURCHASED_PACKAGE.START_DATE',
				'PURCHASED_PACKAGE.EXPIRATION_DATE',
				'PURCHASED_PACKAGE.ACTUAL',
				'PURCHASED_PACKAGE.PURCHASE_CODE',
				'SERVICES_IN_PACKAGE.VALUE',
			])
			->where('PURCHASED_PACKAGE.EXPIRED', 'N')
			->setOrder([
				'PURCHASED_PACKAGE.START_DATE' => 'ASC',
			])
			->setCacheTtl(86400)
			->cacheJoins(true)
		;
	}
}
