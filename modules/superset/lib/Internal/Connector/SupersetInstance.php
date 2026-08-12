<?php

namespace Bitrix\Superset\Internal\Connector;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Json;
use Bitrix\Superset\Internal\Entities\Server;
use Bitrix\Superset\Internal\HttpStatus;
use Bitrix\Superset\Internal\Repositories\LocalServerRepository;
use Bitrix\Superset\Internal\RequestResult;
use Bitrix\Superset\Internal\Support\SupersetResultFactory;

class SupersetInstance extends BaseConnector
{
	private const CSRF_TOKEN_URL = '/api/v1/security/csrf_token/';
	private const AUTH_TOKEN_URL = '/api/v1/security/login';

	/**
	 * @param Server $server
	 * @param array $options
	 * @throws ArgumentException
	 */
	public function __construct(protected Server $server, array $options = [])
	{
		parent::__construct($options);
	}

	/**
	 * @return void
	 * @throws ArgumentException
	 */
	protected function initHttpClient(): void
	{
		$this->httpClient = new HttpClient($this->httpClientOptions);
		$this->httpClient->setHeader('Content-Type', 'application/json');
		$this->httpClient->disableSslVerification();

		try
		{
			$this->httpClient->setHeader('Authorization', "Bearer {$this->server->getToken()}");
		}
		catch (\InvalidArgumentException)
		{
			$refreshAccessTokenResult = $this->refreshAccessToken();
			if (!$refreshAccessTokenResult->isSuccess())
			{
				$errorMessages = implode(', ',$refreshAccessTokenResult->getErrorMessages());

				throw new ArgumentException("Failed to refresh access token: {$errorMessages}");
			}

			$accessToken = $refreshAccessTokenResult->getData()['access_token'] ?? null;
			if ($accessToken)
			{
				$this->httpClient->setHeader('Authorization', "Bearer {$accessToken}");
			}
			else
			{
				throw new ArgumentException("Invalid user credentials");
			}
		}
	}

	private function addCsrfToken(): void
	{
		$csrfToken = $this->getCsrfToken();
		$this->httpClient->setHeader('Referer', $this->buildRequestUrl(self::CSRF_TOKEN_URL));
		$this->httpClient->setHeader('X-CSRFToken', $csrfToken);
		$this->httpClient->setCookies($this->httpClient->getCookies()->toArray());
	}

	/**
	 * @return Result with new access token in data
	 */
	public function refreshAccessToken(): Result
	{
		$result = new Result();

		$payload = [
			'username' => 'admin',
			'password' => $this->server->getAccessPassword(),
			'provider' => 'db',
		];

		$response = parent::post($this->getAccessTokenUrl(), $payload);
		if (!$response->isSuccess())
		{
			$result->addErrors($response->getErrors());

			return $result;
		}

		if ($response->getHttpStatus() !== HttpStatus::OK)
		{
			return (new SupersetResultFactory())->createRequestErrorResult(
				$response,
				'Failed to refresh superset access token',
			);
		}

		$responseDecode = $this->decodeAnswer($response->getAnswer());
		$accessToken = $responseDecode['access_token'] ?? null;
		$refreshToken = $responseDecode['refresh_token'] ?? null;

		if (empty($accessToken))
		{
			$errorMessages = self::buildErrorMessageFromBody($response->getAnswer());
			if ($errorMessages === '')
			{
				$errorMessages = implode(', ', $response->getErrorMessages());
			}
			if ($errorMessages === '')
			{
				$errorMessages = 'Failed to refresh superset access token';
			}

			$result->addError(new Error($errorMessages));

			return $result;
		}

		$this->server->setToken($accessToken);
		if (!empty($refreshToken))
		{
			$this->server->setRefreshToken($refreshToken);
		}
		(new LocalServerRepository())->save($this->server);

		$result->setData(['access_token' => $accessToken]);

		return $result;
	}

	private function getAccessTokenUrl(): string
	{
		return $this->buildRequestUrl(self::AUTH_TOKEN_URL);
	}

	private function getCsrfToken(): ?string
	{
		$responseDecode = $this->decodeAnswer(
			$this->get(self::CSRF_TOKEN_URL)->getAnswer()
		);

		return $responseDecode['result'] ?? null;
	}

	private function decodeAnswer(string $answer): ?array
	{
		try
		{
			return Json::decode($answer);
		}
		catch(ArgumentException $e)
		{
			return null;
		}
	}

	/**
	 * @param string $path
	 * @return string
	 */
	public function buildRequestUrl(string $path): string
	{
		return $this->server->getHost() . $path;
	}

	/**
	 * @param string $url path to superset endpoint without host. Ex. "/api/v1/dashboard"
	 * @return RequestResult
	 */
	public function get(string $url): RequestResult
	{
		$this->initHttpClient();
		$requestResult = parent::get($this->buildRequestUrl($url));

		if (
			$requestResult->getHttpStatus() === HttpStatus::UNAUTHORIZED
			|| $requestResult->getHttpStatus() === HttpStatus::UNPROCESSABLE_ENTITY
		)
		{
			$refreshAccessTokenResult = $this->refreshAccessToken();

			if ($refreshAccessTokenResult->isSuccess())
			{
				$accessToken = $refreshAccessTokenResult->getData()['access_token'] ?? null;
				if ($accessToken)
				{
					$this->httpClient->setHeader('Authorization', "Bearer {$accessToken}");
				}
			}

			return parent::get($this->buildRequestUrl($url));
		}

		return $requestResult;
	}

	/**
	 * @param string $url path to superset endpoint without host. Ex. "/api/v1/dashboard"
	 * @param array $payload
	 * @return RequestResult
	 */
	public function post(string $url, array $payload = []): RequestResult
	{
		$this->initHttpClient();
		$this->addCsrfToken();

		return parent::post($this->buildRequestUrl($url), $payload);
	}

	/**
	 * @param string $url path to superset endpoint without host. Ex. "/api/v1/dashboard"
	 * @return RequestResult
	 * @param array $payload
	 */
	public function postMultipart(string $url, array $payload = []): RequestResult
	{
		$this->initHttpClient();
		$this->addCsrfToken();

		return parent::postMultipart($this->buildRequestUrl($url), $payload);
	}

	/**
	 * @param string $url path to superset endpoint without host. Ex. "/api/v1/dashboard"
	 * @param array $payload
	 * @return RequestResult
	 */
	public function put(string $url, array $payload = []): RequestResult
	{
		$this->initHttpClient();
		$this->addCsrfToken();

		return parent::put($this->buildRequestUrl($url), $payload);
	}

	/**
	 * @param string $url path to superset endpoint without host. Ex. "/api/v1/dashboard"
	 * @param array $payload
	 * @return RequestResult
	 */
	public function delete(string $url, array $payload = []): RequestResult
	{
		$this->initHttpClient();
		$this->addCsrfToken();

		return parent::delete($this->buildRequestUrl($url), $payload);
	}

	protected static function buildRequestBody(array $payload = []): mixed
	{
		return Json::encode($payload);
	}

	protected function processResult(bool $isSuccess, float $time): RequestResult
	{
		$requestResult = parent::processResult($isSuccess, $time);

		$status = $requestResult->getHttpStatus();
		if (
			$status === HttpStatus::BAD_GATEWAY
			|| $status === HttpStatus::SERVICE_UNAVAILABLE
		)
		{
			return new RequestResult(
				HttpStatus::DEACTIVATED_INSTANCE,
				$requestResult->getHeaders(),
				'Superset instance unavailable',
				$requestResult->getTime()
			);
		}

		return $requestResult;
	}

	private static function parseSupersetErrorResponseBody(string $body): ?Error
	{
		if (empty($body))
		{
			return null;
		}

		try
		{
			$decoded = Json::decode($body);
		}
		catch (\Exception)
		{
			return null;
		}

		if (!is_array($decoded))
		{
			return null;
		}

		if (isset($decoded['msg']) && is_string($decoded['msg']))
		{
			return new Error($decoded['msg']);
		}

		if (isset($decoded['message']) && is_string($decoded['message']))
		{
			return new Error($decoded['message']);
		}

		if (isset($decoded['error']) && is_string($decoded['error']))
		{
			return new Error($decoded['error']);
		}

		if (isset($decoded['errors']) && is_array($decoded['errors']))
		{
			$errorMessages = [];
			foreach ($decoded['errors'] as $error)
			{
				if (is_array($error) && isset($error['message']) && is_string($error['message']))
				{
					$errorMessages[] = $error['message'];
				}
			}

			if (!empty($errorMessages))
			{
				return new Error(implode('; ', $errorMessages));
			}
		}

		return null;
	}

	public static function buildErrorMessageFromBody(string $body): string
	{
		$error = self::parseSupersetErrorResponseBody($body);
		if ($error !== null)
		{
			return $error->getMessage();
		}
		else
		{
			$newErrorMessage = mb_substr($body, 0, 200);
			if ($newErrorMessage !== $body)
			{
				return $newErrorMessage . '...';
			}
		}

		return $body;
	}

}
