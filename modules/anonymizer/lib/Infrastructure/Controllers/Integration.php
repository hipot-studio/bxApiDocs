<?php

declare(strict_types=1);

namespace Bitrix\Anonymizer\Infrastructure\Controllers;

use Bitrix\Anonymizer\Internal\Integration\AnonymizerService\ProxyConfig;
use Bitrix\Anonymizer\Infrastructure\Controllers\Filter\Authorization;
use Bitrix\Anonymizer\Public\Commands\HandleProxyCallbackResult;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\JsonPayload;
use Bitrix\Main\Error;
use Bitrix\Main\Security\Cipher;
use Bitrix\Main\Security\SecurityException;
use Bitrix\Main\Web\Json;

/**
 * Integration endpoints for the cloud proxy (registration, domain verification).
 *
 * @see \Bitrix\AnonymizerProxy\Internal\Services\Scenario\DomainVerification::verify()
 */
final class Integration extends Controller
{
	private ?ProxyConfig $proxyConfig = null;

	private function getProxyConfig(): ProxyConfig
	{
		return $this->proxyConfig ??= new ProxyConfig();
	}

	public function configureActions(): array
	{
		$proxyConfig = $this->getProxyConfig();

		return [
			'proxyCallback' => [
				'prefilters' => [
					new Authorization($proxyConfig->getProxySecretKey()),
				],
			],
			'verifyDomain' => [
				'prefilters' => [],
				'+postfilters' => [
					function () use ($proxyConfig): void
					{
						$proxyConfig->resetTempSecretForDomainVerification();
					},
				],
			],
		];
	}

	/**
	 * The proxy issues a GET to the portal during registration to prove the domain is this portal.
	 * Returns an encrypted challenge; the proxy decrypts it with the temporary secret stored locally for the active
	 * registration flow.
	 */
	public function verifyDomainAction(): ?array
	{
		$tempSecret = $this->getProxyConfig()->getTempSecretForDomainVerification();
		if ($tempSecret === null || $tempSecret === '')
		{
			$this->addError(new Error('Empty secret.'));

			return null;
		}

		try
		{
			$cipher = new Cipher();
			$message = base64_encode($cipher->encrypt('42', $tempSecret));
		}
		catch (SecurityException)
		{
			$this->addError(new Error("Cipher doesn't happy."));

			return null;
		}

		return [
			'message' => $message,
		];
	}

	/**
	 * Callback from anonymizerproxy: POST body { hash, statusCode, body }.
	 * Request authorization is validated by prefilter Authorization.
	 */
	public function proxyCallbackAction(JsonPayload $payload): void
	{
		$data = $payload->getData();
		$data = is_array($data) ? $data : [];
		if ($data === [])
		{
			$raw = $payload->getRaw();
			if (is_string($raw) && $raw !== '')
			{
				try
				{
					$decoded = Json::decode($raw);
					$data = is_array($decoded) ? $decoded : [];
				}
				catch (\Exception)
				{
					$this->addError(new Error('Invalid JSON'));

					return;
				}
			}
		}

		$result = (new HandleProxyCallbackResult())->handle($data);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());
		}
	}
}
