<?php

declare(strict_types=1);

namespace Bitrix\Baas\Integration\Controller;

use Bitrix\Baas\Baas;
use Bitrix\Baas\Contract\DateTimeFormat;
use Bitrix\Baas\Model\Dto\PurchasedPackage;
use Bitrix\Baas\Public\Provider\PackageProvider;
use Bitrix\Main\Application;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Routing\Router;
use Bitrix\Main\Security\Sign\TimeSigner;

final class BalanceReport
{
	private Baas $baas;
	private Router $router;
	private TimeSigner $signer;

	public function __construct(?Baas $baas = null, ?Router $router = null, ?TimeSigner $signer = null)
	{
		$this->baas = $baas ?? Baas::getInstance();
		$this->router = $router ?? Application::getInstance()->getRouter();
		$this->signer = $signer ?? new TimeSigner();
	}

	public function get(): string
	{
		try
		{
			$this->baas->sync();
		}
		catch (\Exception $exception)
		{
			return implode(PHP_EOL, ['Baas sync failed. ', '<pre>' , print_r($exception, true), '</pre>']);
		}

		if ($this->baas->isAvailable() !== true)
		{
			return $this->getUnavailableInfo();
		}

		return $this->getSummaryInfo();
	}

	private function getUnavailableInfo(): string
	{
		return 'Baas is not available for this portal.';
	}

	private function getSummaryInfo(): string
	{
		$noticeCollection = [];

		$noticeCollection['isAvailable'] = 'Baas is available.';
		$noticeCollection['isActive'] = $this->baas->isActive() ?
			'Baas is active.' :
			'Baas is not active: boosts are not enabled.'
		;

		$noticeCollection['packages'] = [[
			'Service', 'Current val', 'Maximal val',
			'Package', 'Purchase', 'Purchased Pack',
			'Start date', 'Expiration date', 'Download logs'
		]];

		foreach (PackageProvider::create()->getAll() as $package)
		{
			$formattedPurchases = [];
			foreach ($package->getPurchases(true) as $purchase)
			{
				/** @var PurchasedPackage $purchasedPack */
				foreach ($purchase->getPurchasedPackages() as $purchasedPack)
				{
					$packBalance = [];
					foreach ($purchasedPack->getPurchasedServices() as $service)
					{
						$packBalance[$service->getServiceCode()] ??= ['current' => 'not actual', 'max' => 0];
						$packBalance[$service->getServiceCode()]['max'] += $service->getInitialValue();
						if ($purchasedPack->isActual())
						{
							if (!is_integer($packBalance[$service->getServiceCode()]['current']))
							{
								$packBalance[$service->getServiceCode()]['current'] = 0;
							}
							$packBalance[$service->getServiceCode()]['current'] += $service->getCurrentValue();
						}
					}

					foreach ($packBalance as $serviceCode => $balance)
					{
						$hasLogsLink = false;
						if ($balance['current'] !== 'not actual' && $balance['current'] !== $balance['max'])
						{
							$hasLogsLink = true;
						}
						$formattedPurchase = [
							'service_code' => $serviceCode,
							'balance_current' => $balance['current'],
							'balance_max' => $balance['max'],
							'package_code' => $package->getCode(),
							'purchase_code' => $purchase->getCode(),
							'purchase_package_code' => $purchasedPack->getCode(),
							'start_date' => $purchasedPack->getStartDate()->format(
								DateTimeFormat::LOCAL_DATETIME->value,
							),
							'expiration_date' => $purchasedPack->getExpirationDate()->format(
								DateTimeFormat::LOCAL_DATETIME->value,
							),
						];

						if ($hasLogsLink)
						{
							$formattedPurchase['logs_link'] = $this->getLink(
								$purchasedPack->getCode(),
								$serviceCode,
							);
						}

						$formattedPurchases[] = $formattedPurchase;
					}
				}
			}

			if (empty($formattedPurchases))
			{
				$noticeCollection['packages'][] = [
					'', '', '',
					$package->getCode(), 'Not bought', '',
					'', ''
				];
			}
			else
			{
				$noticeCollection['packages'] = array_merge(
					$noticeCollection['packages'],
					$formattedPurchases
				);
			}
		}
		$cb = fn ($key, $value) => $key === 'logs_link' ? $value : str_pad(substr((string)$value, 0, 20), 20, '_', STR_PAD_RIGHT);
		array_walk(
			$noticeCollection['packages'],
			function (&$row) use ($cb) {
				$row = implode(' | ', array_map($cb, array_keys($row), $row));
			},
		);
		$noticeCollection['packages'] = implode(PHP_EOL, $noticeCollection['packages']);

		return implode(PHP_EOL, $noticeCollection);
	}

	private function getLink(
		string $purchasedPackageCode,
		string $serviceCode,
	): string
	{
		$signTime = '+1 hour';
		$signedPurchasedPackageCode = $this->signer->sign($purchasedPackageCode, $signTime);
		$signedServiceCode = $this->signer->sign($serviceCode, $signTime);

		$route = $this->router->url(
			'/bitrix/services/main/ajax.php',
			[
				'action' => 'baas.Host.getSignedPurchaseReport',
				'purchasedPackageCode' => $signedPurchasedPackageCode,
				'serviceCode' => $signedServiceCode,
			],
		);

		return sprintf(
			'<a href="%s" target="_blank" >%s</a>',
			$route,
			Loc::getMessage('BAAS_PACKAGE_LOGS_ROW_DOWNLOAD') ?? 'Logs',
		);
	}
}
