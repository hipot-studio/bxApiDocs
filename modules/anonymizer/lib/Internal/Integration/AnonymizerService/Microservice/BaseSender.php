<?php

declare(strict_types=1);

namespace Bitrix\Anonymizer\Internal\Integration\AnonymizerService\Microservice;

use Bitrix\Anonymizer\Internal\Integration\AnonymizerService\ProxyConfig;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\JWT;
use Bitrix\Main\Service\MicroService;

abstract class BaseSender extends MicroService\BaseSender
{
	private const JWT_TTL = 300;

	/** @var string */
	private string $serviceUrl;

	protected ProxyConfig $proxyConfig;

	public function __construct(string $serviceUrl, ?ProxyConfig $proxyConfig = null)
	{
		$this->serviceUrl = $this->refineServiceUrl($serviceUrl);
		$this->proxyConfig = $proxyConfig ?? new ProxyConfig();

		parent::__construct();
	}

	protected function refineServiceUrl(string $serviceUrl): string
	{
		$serviceUrl = rtrim($serviceUrl, '/');
		if (!str_starts_with($serviceUrl, 'http://') && !str_starts_with($serviceUrl, 'https://'))
		{
			return "https://$serviceUrl";
		}

		return $serviceUrl;
	}

	protected function getServiceUrl(): string
	{
		return $this->serviceUrl;
	}

	protected function buildHttpClient(): HttpClient
	{
		$httpClient = parent::buildHttpClient();
		$this->applyClientSignatureHeaders($httpClient);

		return $httpClient;
	}

	protected function applyClientSignatureHeaders(HttpClient $httpClient): void
	{
		$clientId = $this->proxyConfig->getProxyClientId();
		$secretKey = $this->proxyConfig->getProxySecretKey();
		if ($clientId === null || $secretKey === null || $clientId === '' || $secretKey === '')
		{
			return;
		}

		$httpClient->setHeader('X-Client-Id', $clientId);
		$httpClient->setHeader('Authorization', 'Bearer ' . $this->createClientRequestJwt($clientId, $secretKey));
	}

	protected function createClientRequestJwt(string $clientId, string $secretKey): string
	{
		$time = time();
		$payload = [
			'clientId' => $clientId,
			'iat' => $time,
			'exp' => $time + self::JWT_TTL,
		];

		return JWT::encode($payload, $secretKey);
	}
}
