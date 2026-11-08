<?php

namespace Bitrix\Sign\Service;

use Bitrix\Main;
use Bitrix\Sign\Result\Service\License\LoadTariffResult;
use Bitrix\Sign\Contract;

class LicenseService
{
	private const LOAD_TIMEOUT = 15;
	private const CACHE_TTL = 3600;
	private const LOAD_FAILURE_CACHE_TTL = 60;

	private const CACHE_TARIFF_KEY = 'sign_license_tariff';
	private const CACHE_LOAD_FAILURE_KEY = 'sign_license_tariff_load_failure';

	public function __construct(
		private readonly Contract\Util\Cache $cache,
	)
	{
		$this->cache->setTtl(self::CACHE_TTL);
	}

	public function loadTariff(): Main\Result | LoadTariffResult
	{
		$cachedTariff = $this->cache->get(self::CACHE_TARIFF_KEY);
		if ($cachedTariff)
		{
			return new LoadTariffResult($cachedTariff);
		}

		$cachedFailure = $this->getCachedLoadFailure();
		if ($cachedFailure !== null)
		{
			return (new Main\Result())->addError(new Main\Error($cachedFailure));
		}

		$endpoint = Main\Application::getInstance()->getLicense()->getDomainStoreLicense() . '/verify.php';

		$httpClient = (new Main\Web\HttpClient())->setTimeout(self::LOAD_TIMEOUT);
		$httpClient->post($endpoint, $this->makeLicenseData());

		try
		{
			$data = Main\Web\Json::decode($httpClient->getResult());
		}
		catch (Main\ArgumentException $e)
		{
			return $this->failLoad('Incorrect response data');
		}

		$tariffCode = ($data['result'] ?? [])['TARIF'] ?? null;
		if (empty($tariffCode))
		{
			return $this->failLoad('Empty tariff data');
		}

		try
		{
			$this->cache->set(self::CACHE_TARIFF_KEY, $tariffCode);
		}
		catch (Main\ArgumentException $exception) {}

		return new LoadTariffResult($tariffCode);
	}

	/**
	 * A failed load is kept for a short while only: an unreachable license service costs
	 * LOAD_TIMEOUT seconds of the worker on every call, and a recovered one must not stay ignored
	 * as long as a successful tariff is kept. The moment of the failure is stored with it because
	 * a read honors the ttl of the shared cache instance, not the ttl the key was written with.
	 */
	private function failLoad(string $message): Main\Result
	{
		try
		{
			$this->cache->set(
				self::CACHE_LOAD_FAILURE_KEY,
				['message' => $message, 'time' => time()],
				self::LOAD_FAILURE_CACHE_TTL,
			);
		}
		catch (Main\ArgumentException $exception) {}

		return (new Main\Result())->addError(new Main\Error($message));
	}

	private function getCachedLoadFailure(): ?string
	{
		$failure = $this->cache->get(self::CACHE_LOAD_FAILURE_KEY);
		if (!is_array($failure))
		{
			return null;
		}

		$failedAt = (int)($failure['time'] ?? 0);
		if ($failedAt <= 0 || (time() - $failedAt) >= self::LOAD_FAILURE_CACHE_TTL)
		{
			return null;
		}

		return (string)($failure['message'] ?? '');
	}

	private function makeLicenseData(): array
	{
		$licenseData = [
			'BX_TYPE' => Main\Service\MicroService\Client::getPortalType(),
			'BX_LICENCE' => Main\Service\MicroService\Client::getLicenseCode(),
			'SERVER_NAME' => Main\Service\MicroService\Client::getServerName(),
		];
		$licenseData['BX_HASH'] = Main\Service\MicroService\Client::signRequest($licenseData);

		return $licenseData;
	}
}