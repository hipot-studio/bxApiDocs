<?php

declare(strict_types=1);

namespace Bitrix\Anonymizer\Internal\Integration\AnonymizerService;

use Bitrix\Anonymizer\Internal\Integration\AnonymizerService\Command\CommandInterface;
use Bitrix\Main\Web\Json;

/**
 * Sends a request to anonymizerproxy by action name with body, callbackUri and JWT signature.
 */
class ProxySender
{
	private ProxyConfig $proxyConfig;

	public function __construct(?ProxyConfig $proxyConfig = null)
	{
		$this->proxyConfig = $proxyConfig ?? new ProxyConfig();
	}

	/**
	 * Calls a proxy action. Returns ['success' => true, 'hash' => string] or ['success' => false, 'error' => string].
	 *
	 * @return array{success: bool, hash?: string, error?: string}
	 */
	public function callProxy(CommandInterface $command, string $callbackUri): array
	{
		if (!$this->canUseProxy())
		{
			return ['success' => false, 'error' => 'Proxy registration data missing'];
		}

		$clientId = $this->proxyConfig->getProxyClientId();
		$secretKey = $this->proxyConfig->getProxySecretKey();
		$serverHost = $this->proxyConfig->getProxyServerHost();
		if ($clientId === null || $secretKey === null || $serverHost === null)
		{
			return ['success' => false, 'error' => 'Proxy registration data missing'];
		}

		$bodyJson = Json::encode($command->getData());
		$sender = new Microservice\Anonymize($serverHost, $this->proxyConfig);
		$result = $sender->callAction(
			$command->getProxyAction(),
			[
				'body' => $bodyJson,
				'callbackUri' => $callbackUri,
			],
		);
		if ($result->isSuccess())
		{
			$hash = (string)($result->getData()['hash'] ?? '');
			if ($hash !== '')
			{
				return ['success' => true, 'hash' => $hash];
			}

			return ['success' => false, 'error' => 'Invalid proxy response JSON'];
		}

		return [
			'success' => false,
			'error' => implode('; ', $result->getErrorMessages()) ?: 'Proxy request failed',
		];
	}

	private function canUseProxy(): bool
	{
		if (!$this->proxyConfig->isUseProxy())
		{
			return false;
		}

		// todo: add max try limit
		if (!$this->proxyConfig->isProxyRegistered())
		{
			$registration = new ProxyRegistration($this->proxyConfig);
			$result = $registration->register();
			if (!$result->isSuccess())
			{
				return false;
			}
		}

		return true;
	}
}
