<?php

namespace Bitrix\Superset\Internal\Connector;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Config\Configuration;
use Bitrix\Main\Error;
use Bitrix\Main\Diag\Logger;
use Bitrix\Main\Result;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\HttpHeaders;
use Bitrix\Main\Web\Json;
use Bitrix\Superset\Internal\Entities\Server;
use Bitrix\Superset\Internal\HttpStatus;
use Bitrix\Superset\Internal\Repositories\ServerPersister\ServerPersisterFactory;
use Bitrix\Superset\Internal\Repositories\ServerPersister\ServerPersisterInterface;
use Bitrix\Superset\Internal\RequestResult;
use Bitrix\Superset\Internal\Support\SupersetResultFactory;
use Closure;

class SupersetInstance extends BaseConnector
{
	private const CSRF_TOKEN_URL = '/api/v1/security/csrf_token/';
	private const AUTH_TOKEN_URL = '/api/v1/security/login';
	private const RETRY_PAUSE_MICROSECONDS = 1_000_000;
	private const RETRY_FIRST_ATTEMPT_TIME_LIMIT = 5.0;
	private const RETRY_SOCKET_TIMEOUT = 5;
	private const RETRY_STREAM_TIMEOUT = 10;
	private const MIN_REQUEST_TIME = 2;

	private readonly ServerPersisterInterface $serverPersister;
	private bool $isRetrySpent = false;
	private bool $isGetRetryEnabled = true;
	private ?float $deadline = null;
	private ?int $lastTokenRefreshHttpStatus = null;

	/**
	 * @param Server $server
	 * @param array $options
	 * @param ServerPersisterInterface|null $serverPersister
	 * @throws ArgumentException
	 */
	public function __construct(
		protected Server $server,
		array $options = [],
		?ServerPersisterInterface $serverPersister = null
	)
	{
		$this->serverPersister = $serverPersister ?? ServerPersisterFactory::forServer($server);

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
		if (!$this->server->isSslVerificationEnabled())
		{
			$this->httpClient->disableSslVerification();
		}

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

		$response = $this->send(fn(): RequestResult => parent::post($this->getAccessTokenUrl(), $payload));
		$this->lastTokenRefreshHttpStatus = $response->getHttpStatus();
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
		$this->serverPersister->persist($this->server);

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
		$this->lastTokenRefreshHttpStatus = null;
		$this->initHttpClient();
		$requestResult = $this->send(fn(): RequestResult => parent::get($this->buildRequestUrl($url)));

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

			$requestResult = $this->send(fn(): RequestResult => parent::get($this->buildRequestUrl($url)));
		}

		if ($this->shouldRetry($requestResult))
		{
			$this->isRetrySpent = true;
			$this->pauseBeforeRetry();
			$retryResult = $this->send(
				fn(): RequestResult => parent::get($this->buildRequestUrl($url)),
				self::RETRY_SOCKET_TIMEOUT,
				self::RETRY_STREAM_TIMEOUT,
			);
			$this->logRetry($url, $requestResult, $retryResult);

			// A retry that did not reach the instance in time tells nothing new, the first server error is kept.
			return $retryResult->getHttpStatus() === HttpStatus::SERVER_DOWN ? $requestResult : $retryResult;
		}

		return $requestResult;
	}

	/**
	 * Limits the total time of the following requests of this connector, token refresh included.
	 * Each request gets the time left as its timeouts; a request that does not fit is not sent
	 * and ends with `521` (SERVER_DOWN).
	 */
	public function limitTotalTime(float $seconds): static
	{
		$this->deadline = $this->getCurrentTime() + $seconds;

		return $this;
	}

	/**
	 * HTTP status of the token refresh made by the last GET, or null when that GET did not refresh the token.
	 */
	public function getLastTokenRefreshHttpStatus(): ?int
	{
		return $this->lastTokenRefreshHttpStatus;
	}

	/**
	 * @param Closure(): RequestResult $request
	 */
	private function send(
		Closure $request,
		?int $socketTimeoutLimit = null,
		?int $streamTimeoutLimit = null,
	): RequestResult
	{
		[$configuredSocketTimeout, $configuredStreamTimeout] = $this->getConfiguredTimeouts();
		$socketTimeout = self::limitTimeout($configuredSocketTimeout, $socketTimeoutLimit);
		$streamTimeout = self::limitTimeout($configuredStreamTimeout, $streamTimeoutLimit);

		if ($this->deadline !== null)
		{
			$timeLeft = (int)floor($this->deadline - $this->getCurrentTime());
			if ($timeLeft < self::MIN_REQUEST_TIME)
			{
				return $this->createTimeLimitExceededResult();
			}

			// Connection and reading are limited separately, so together they have to fit into the time left.
			$socketTimeout = self::limitTimeout($socketTimeout, intdiv($timeLeft, 2));
			$streamTimeout = self::limitTimeout($streamTimeout, $timeLeft - $socketTimeout);
		}

		if ($socketTimeout === $configuredSocketTimeout && $streamTimeout === $configuredStreamTimeout)
		{
			return $request();
		}

		$this->httpClient->setTimeout($socketTimeout);
		$this->httpClient->setStreamTimeout($streamTimeout);
		try
		{
			return $request();
		}
		finally
		{
			$this->httpClient->setTimeout($configuredSocketTimeout);
			$this->httpClient->setStreamTimeout($configuredStreamTimeout);
		}
	}

	/**
	 * Timeouts the HTTP client gets on creation: the connector options complemented by the global
	 * `http_client_options`, resolved the same way as the HttpClient constructor does.
	 *
	 * @return array{int, int} socket and stream timeouts
	 */
	private function getConfiguredTimeouts(): array
	{
		$options = $this->httpClientOptions + (Configuration::getValue('http_client_options') ?? []);

		$streamTimeout = HttpClient::DEFAULT_STREAM_TIMEOUT;
		if (isset($options['waitResponse']) && !$options['waitResponse'])
		{
			$streamTimeout = HttpClient::DEFAULT_STREAM_TIMEOUT_NO_WAIT;
		}

		return [
			(int)($options['socketTimeout'] ?? HttpClient::DEFAULT_SOCKET_TIMEOUT),
			(int)($options['streamTimeout'] ?? $streamTimeout),
		];
	}

	private static function limitTimeout(int $timeout, ?int $limit): int
	{
		if ($limit === null)
		{
			return $timeout;
		}

		// A zero timeout means no timeout at all.
		return $timeout > 0 ? min($timeout, $limit) : $limit;
	}

	private function createTimeLimitExceededResult(): RequestResult
	{
		$result = new RequestResult(HttpStatus::SERVER_DOWN, new HttpHeaders(), '', 0.0);
		$result->addError(new Error('Superset request time limit exceeded'));

		return $result;
	}

	protected function getCurrentTime(): float
	{
		return microtime(true);
	}

	/**
	 * Makes every GET of this connector a single attempt: a server error is returned without the retry.
	 */
	public function disableGetRetry(): static
	{
		$this->isGetRetryEnabled = false;

		return $this;
	}

	/**
	 * A single fast 5xx answer is treated as transient and retried once per connector.
	 * Slow failures, a failed connection and an unavailable instance are not retried to keep the overall wait bounded.
	 */
	private function shouldRetry(RequestResult $result): bool
	{
		if (!$this->isGetRetryEnabled || $this->isRetrySpent)
		{
			return false;
		}

		$status = $result->getHttpStatus();
		if (
			$status < HttpStatus::INTERNAL_SERVER_ERROR
			|| $status === HttpStatus::DEACTIVATED_INSTANCE
			|| $status === HttpStatus::SERVER_DOWN
		)
		{
			return false;
		}

		return $result->getTime() < $this->getRetryFirstAttemptTimeLimit();
	}

	protected function pauseBeforeRetry(): void
	{
		usleep(self::RETRY_PAUSE_MICROSECONDS);
	}

	protected function getRetryFirstAttemptTimeLimit(): float
	{
		return self::RETRY_FIRST_ATTEMPT_TIME_LIMIT;
	}

	private function logRetry(string $url, RequestResult $firstResult, RequestResult $retryResult): void
	{
		Logger::create('superset.SupersetInstance')?->warning(
			'Superset GET request was retried after a server error. Path: {path}, status: {status}, '
			. 'retry status: {retryStatus}, first attempt time: {time}',
			[
				'path' => explode('?', $url, 2)[0],
				'status' => $firstResult->getHttpStatus(),
				'retryStatus' => $retryResult->getHttpStatus(),
				'time' => $firstResult->getTime(),
			],
		);
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

		return $this->send(fn(): RequestResult => parent::post($this->buildRequestUrl($url), $payload));
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

		return $this->send(fn(): RequestResult => parent::postMultipart($this->buildRequestUrl($url), $payload));
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

		return $this->send(fn(): RequestResult => parent::put($this->buildRequestUrl($url), $payload));
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

		return $this->send(fn(): RequestResult => parent::delete($this->buildRequestUrl($url), $payload));
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
