<?php

declare(strict_types=1);

namespace Bitrix\Anonymizer\Internal\Integration\AnonymizerService;

use Bitrix\Anonymizer\Internal\Integration\AnonymizerService\Microservice\Registration;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Portal registration / unregistration on anonymizerproxy via {@see Registration} + Option persistence.
 */
class ProxyRegistration
{
	private ProxyConfig $proxyConfig;

	public function __construct(?ProxyConfig $proxyConfig = null)
	{
		$this->proxyConfig = $proxyConfig ?? new ProxyConfig();
	}

	public function getProxyConfig(): ProxyConfig
	{
		return $this->proxyConfig;
	}

	public function isRegistered(): bool
	{
		return $this->proxyConfig->isProxyRegistered();
	}

	/**
	 * Calls anonymizerproxy 2-step registration (init + confirm) and persists clientId/secretKey/serverHost.
	 * Override {@see executeRegisterPortalInit} / {@see executeRegisterPortalConfirm} in tests to stub the HTTP layer.
	 *
	 * @return Result with ['clientId' => ..., 'secretKey' => ..., 'serverHost' => ...] on success
	 */
	public function register(): Result
	{
		$proxyBaseUrl = $this->proxyConfig->getProxyServer();
		if ($proxyBaseUrl === null || $proxyBaseUrl === '')
		{
			$result = new Result();
			$result->addError(new Error('Proxy server URL is not configured for the current region.'));

			return $result;
		}

		$this->proxyConfig->clearProxyRegistrationData();

		try
		{
			$initResult = $this->executeRegisterPortalInit($proxyBaseUrl);
			if (!$initResult->isSuccess())
			{
				return $initResult;
			}

			$challenge = self::extractChallengePayload($initResult->getData());
			if ($challenge === null)
			{
				$result = new Result();
				$result->addError(new Error('Registration init response missing challenge payload'));

				return $result;
			}

			$challengeId = $challenge['challengeId'] ?? null;
			$tempSecret = $challenge['secret'] ?? null;
			if ($challengeId === null || $tempSecret === null)
			{
				$result = new Result();
				$result->addError(new Error('Registration init response missing challengeId or secret'));

				return $result;
			}

			$this->proxyConfig->storeTempSecretForDomainVerification((string)$tempSecret);

			$confirmResult = $this->executeRegisterPortalConfirm($proxyBaseUrl, (string)$challengeId);
			if (!$confirmResult->isSuccess())
			{
				return $confirmResult;
			}

			$client = self::extractClientPayload($confirmResult->getData());
			if ($client === null)
			{
				$result = new Result();
				$result->addError(new Error('Registration response missing client payload'));

				return $result;
			}

			$clientId = $client['clientId'] ?? null;
			$secretKey = $client['secretKey'] ?? null;
			$serverHost = $client['serverHost'] ?? null;

			if ($clientId === null || $secretKey === null || $serverHost === null)
			{
				$result = new Result();
				$result->addError(new Error('Registration response missing clientId, secretKey or serverHost'));

				return $result;
			}

			$this->proxyConfig->setProxyRegistrationData((string)$clientId, (string)$secretKey, (string)$serverHost);
			$this->proxyConfig->setUseProxy(true);
			$confirmResult->setData([
				'clientId' => $clientId,
				'secretKey' => $secretKey,
				'serverHost' => $serverHost,
			]);

			return $confirmResult;
		}
		finally
		{
		}
	}

	/**
	 * Calls {@see Registration::unregisterPortal()} (microservice unregisterClient), then clears local registration Option.
	 * Override {@see executeUnregisterPortal} in tests to stub the HTTP layer.
	 */
	public function unregister(): Result
	{
		$clientId = $this->proxyConfig->getProxyClientId();
		if ($clientId === null || $clientId === '')
		{
			$result = new Result();
			$result->addError(new Error('There is empty proxy registration data.'));

			return $result;
		}

		$proxyBaseUrl = $this->proxyConfig->getProxyServerHost() ?? $this->proxyConfig->getProxyServer();
		if ($proxyBaseUrl === null || $proxyBaseUrl === '')
		{
			$result = new Result();
			$result->addError(new Error('Proxy server URL is not configured for unregister request.'));

			return $result;
		}

		$result = $this->executeUnregisterPortal($proxyBaseUrl);
		if (!$result->isSuccess())
		{
			return $result;
		}

		$this->proxyConfig->clearProxyRegistrationData();

		return $result;
	}

	/**
	 * @see Registration::registerPortalInit()
	 */
	protected function executeRegisterPortalInit(string $proxyBaseUrl): Result
	{
		$registration = new Registration($proxyBaseUrl, $this->proxyConfig);

		return $registration->registerPortalInit();
	}

	/**
	 * @see Registration::registerPortalConfirm()
	 */
	protected function executeRegisterPortalConfirm(string $proxyBaseUrl, string $challengeId): Result
	{
		$registration = new Registration($proxyBaseUrl, $this->proxyConfig);

		return $registration->registerPortalConfirm($challengeId);
	}

	/**
	 * @see Registration::unregisterPortal()
	 */
	protected function executeUnregisterPortal(string $proxyBaseUrl): Result
	{
		$registration = new Registration($proxyBaseUrl, $this->proxyConfig);

		return $registration->unregisterPortal();
	}

	/**
	 * @param array<string, mixed>|null $data Result data from {@see Registration::registerPortalConfirm()}
	 * @return array<string, mixed>|null
	 */
	private static function extractClientPayload(?array $data): ?array
	{
		if ($data === null)
		{
			return null;
		}

		// todo: check which fields return from proxy
		if (
			array_key_exists('clientId', $data)
			&& array_key_exists('secretKey', $data)
			&& array_key_exists('serverHost', $data)
		)
		{
			return $data;
		}

		if (isset($data['client']) && is_array($data['client']))
		{
			return $data['client'];
		}

		if (isset($data['data']['client']) && is_array($data['data']['client']))
		{
			return $data['data']['client'];
		}

		return null;
	}

	/**
	 * @param array<string, mixed>|null $data Result data from {@see Registration::registerPortalInit()}
	 * @return array<string, mixed>|null
	 */
	private static function extractChallengePayload(?array $data): ?array
	{
		if ($data === null)
		{
			return null;
		}

		if (
			array_key_exists('challengeId', $data)
			&& array_key_exists('secret', $data)
		)
		{
			return $data;
		}

		return null;
	}
}
