<?php

namespace Bitrix\Im\V2\Call;

use Bitrix\Main\Result;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Service\MicroService\BaseSender;
use Bitrix\Im\Call\Call;

class ControllerClient extends BaseSender
{
	private const SERVICE_MAP = [
		'ru' => 'https://videocalls.bitrix.info',
		'eu' => 'https://videocalls-de.bitrix.info',
		'us' => 'https://videocalls-us.bitrix.info',
	];
	private const REGION_RU = ['ru', 'by', 'kz'];
	private const REGION_EU = ['de', 'eu', 'fr', 'it', 'pl', 'tr', 'uk'];

	private array $httpClientParameters = [];

	/**
	 * Returns controller service endpoint url.
	 *
	 * @return string
	 * @param string $region Portal region.
	 */
	public function getEndpoint(string $region): string
	{
		$endpoint = Option::get('im', 'call_server_url');

		if (empty($endpoint))
		{
			if (in_array($region, self::REGION_RU, true))
			{
				$endpoint = self::SERVICE_MAP['ru'];
			}
			elseif (in_array($region, self::REGION_EU, true))
			{
				$endpoint = self::SERVICE_MAP['eu'];
			}
			else
			{
				$endpoint = self::SERVICE_MAP['us'];
			}
		}
		elseif (!(mb_strpos($endpoint, 'https://') === 0 || mb_strpos($endpoint, 'http://') === 0))
		{
			$endpoint = 'https://' . $endpoint;
		}

		return $endpoint;
	}


	/**
	 * Returns API endpoint for the service.
	 *
	 * @return string
	 */
	protected function getServiceUrl(): string
	{
		$region = \Bitrix\Main\Application::getInstance()->getLicense()->getRegion() ?: 'ru';

		return $this->getEndpoint($region);
	}

	/**
	 * @see \Bitrix\CallController\Controller\InternalApi::createCallAction
	 * @param Call $call
	 * @return Result
	 */
	public function createCall(Call $call): Result
	{
		$data = [
			'uuid' => $call->getUuid(),
			'secretKey' => $call->getSecretKey(),
			'initiatorUserId' => $call->getInitiatorId(),
			'callId' => $call->getId(),
			'conference' => 'N',
			'usersCount' => count($call->getUsers()),
			'version' => \Bitrix\Main\ModuleManager::getVersion('call'),
			'maxParticipants' => \Bitrix\Im\Call\Call::getMaxCallServerParticipants(),
		];

		$this->httpClientParameters = [
			'waitResponse' => true,
		];

		return $this->performRequest('callcontroller.InternalApi.createCall', $data);
	}

	/**
	 * @see \Bitrix\CallController\Controller\InternalApi::finishCallAction
	 * @param Call $call
	 * @return Result
	 */
	public function finishCall(Call $call): Result
	{
		$data = [
			'uuid' => $call->getUuid()
		];

		$this->httpClientParameters = [
			'waitResponse' => false,
			'socketTimeout' => 5,
			'streamTimeout' => 10,
		];

		return $this->performRequest('callcontroller.InternalApi.finishCall', $data);
	}

	public function getHttpClientParameters(): array
	{
		return array_merge(parent::getHttpClientParameters(), $this->httpClientParameters);
	}
}