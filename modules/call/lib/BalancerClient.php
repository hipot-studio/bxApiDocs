<?php

namespace Bitrix\Call;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Result;
use Bitrix\Main\Web\Http\ClientException;
use Bitrix\Main\Web\Http\Method;
use Bitrix\Main\Web\Http\Request;
use Bitrix\Main\Web\Http\Response;
use Bitrix\Main\Web\Http\Stream;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\JWT;
use Bitrix\Main\Web\Uri;

class BalancerClient
{
	private const BALANCER_MAP = [
		'ru' => 'https://slb.webrtc.bitrix.info',
		'eu' => 'https://slb-de.webrtc.bitrix.info',
		'us' => 'https://slb-us.webrtc.bitrix.info',
	];

	/**
	 * Returns call balancer service endpoint url.
	 *
	 * @param string $region Portal region.
	 * @return string
	 */
	public function getEndpoint(string $region): string
	{
		$endpoint = Option::get('call', 'call_balancer_url');

		if (empty($endpoint))
		{
			if (in_array($region, Library::REGION_CIS, true))
			{
				$endpoint = self::BALANCER_MAP['ru'];
			}
			elseif (in_array($region, Library::REGION_EU, true))
			{
				$endpoint = self::BALANCER_MAP['eu'];
			}
			else
			{
				$endpoint = self::BALANCER_MAP['us'];
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
	public function getServiceUrl(): string
	{
		$region = \Bitrix\Main\Application::getInstance()->getLicense()->getRegion() ?: 'ru';

		return $this->getEndpoint($region);
	}

	/**
	 * @param int $tokenVersion
	 * @param int $chatId
	 * @return Result
	 */
	public function updateTokenVersion(int $tokenVersion, int $chatId): Result
	{
		$result = new Result();

		$httpClient = new HttpClient([
			'compress' => true
		]);

		$uri = new Uri($this->getServiceUrl() . '/v2/update-token-version');

		$data = [
			'portalId' => Settings::getPortalId(),
			'minTokenVersion' => $tokenVersion,
			'chatId' => $chatId
		];

		$jwt = JWT::encode($data, JwtCall::getPrivateKey());
		$body = new Stream('php://temp', 'r+');
		$body->write(json_encode(['token' => $jwt]));
		$request = new Request(Method::POST, $uri, [], $body);
		$request->withHeader('bx_call_portal_jwt', $jwt);

		try
		{
			$response = $httpClient->sendRequest($request);

			$answer = $this->extractAnswer($response);
			if (!$answer->isSuccess())
			{
				$result->addErrors($answer->getErrors());
			}
		}
		catch (ClientException $exception)
		{
			$result->addError(new Error(
				Error::BALANCER_ERROR,
				'Exception: '. $exception->getMessage(),
				$data
			));
		}

		return $result;
	}

	protected function extractAnswer(Response $response): Result
	{
		$result = new Result();

		$httpStatus = $response->getStatusCode();
		$answerContent = (string)($response->getBody() ?? '');

		if ($httpStatus == 200)
		{
			if ($answerContent)
			{
				try
				{
					$answer = (array)\json_decode($answerContent, true, 512, \JSON_THROW_ON_ERROR);
					if (isset($answer['result']))
					{
						$result->setData($answer['result']);
					}
					elseif (isset($answer['error']))
					{
						$result->addError(new Error(
							Error::BALANCER_ERROR,
							'Balancer error ['.$answer['error']['code'].']: '.$answer['error']['message']
						));
					}
				}
				catch (\JsonException $exception)
				{
					$result->addError(new Error(
						Error::BALANCER_ERROR,
						'Balancer answer malformed: '. $exception->getMessage()
					));
				}
			}
			else
			{
				$result->addError(new Error(
					Error::BALANCER_ERROR,
					'Unrecognizable or empty balancer answer'
				));
			}
		}
		else
		{
			$result->addError(new Error(
				Error::BALANCER_ERROR,
				'Wrong balancer response '.$response->getStatusCode().' '.$response->getReasonPhrase()
			));
		}

		return $result;
	}
}