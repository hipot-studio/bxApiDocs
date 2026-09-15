<?php

declare(strict_types=1);

namespace Bitrix\Anonymizer\Internal\Integration\AnonymizerService\Microservice;

use Bitrix\Anonymizer\Internal\Integration\AnonymizerService\ProxyConfig;
use Bitrix\Main\Engine\UrlManager;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Portal registration against anonymizerproxy via {@see \Bitrix\Main\Service\MicroService\BaseSender::performRequest}
 */
final class Registration extends BaseSender
{
	private const ACTION_UNREGISTER_CLIENT = 'anonymizerproxy.api.Registration.unregisterClient';
	private const ACTION_REGISTER_CLIENT = 'anonymizerproxy.api.Registration.registerClient';
	private const ACTION_CONFIRM_CLIENT = 'anonymizerproxy.api.Registration.confirmClient';

	private string $domain;

	public function __construct(string $serviceUrl, ?ProxyConfig $proxyConfig = null)
	{
		$this->domain = UrlManager::getInstance()->getHostUrl();

		parent::__construct($serviceUrl, $proxyConfig);
	}

	/**
	 * @see \Bitrix\AnonymizerProxy\Infrastructure\Controllers\Registration::unregisterClientAction
	 */
	public function unregisterPortal(): Result
	{
		$clientId = $this->proxyConfig->getProxyClientId();
		if ($clientId === null || $clientId === '')
		{
			$result = new Result();
			$result->addError(new Error('There is empty proxy registration data.'));

			return $result;
		}

		$data = [
			'clientId' => $clientId,
		];

		return $this->callRegistrationAction(self::ACTION_UNREGISTER_CLIENT, $data);
	}

	/**
	 * @see \Bitrix\AnonymizerProxy\Infrastructure\Controllers\Registration::registerClientAction
	 */
	public function registerPortalInit(): Result
	{
		$data = [
			'domain' => $this->domain,
		];

		return $this->callRegistrationAction(self::ACTION_REGISTER_CLIENT, $data);
	}

	/**
	 * @see \Bitrix\AnonymizerProxy\Infrastructure\Controllers\Registration::confirmClientAction
	 */
	public function registerPortalConfirm(string $challengeId): Result
	{
		$data = [
			'challengeId' => $challengeId,
			'domain' => $this->domain,
		];

		return $this->callRegistrationAction(self::ACTION_CONFIRM_CLIENT, $data);
	}

	private function callRegistrationAction(string $action, array $data): Result
	{
		return $this->performRequest($action, $data);
	}
}
