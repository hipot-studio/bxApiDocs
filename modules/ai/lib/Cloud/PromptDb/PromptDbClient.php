<?php

declare(strict_types=1);

namespace Bitrix\AI\Cloud\PromptDb;

use Bitrix\AI\Cloud\BaseSender;
use Bitrix\AI\Cloud\ServerList;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Json;
use RuntimeException;
use Throwable;

class PromptDbClient extends BaseSender
{
	public const DATA_BODY = 'body';
	public const DATA_STATUS = 'status';

	public const ERROR_PROXY_SERVER_NOT_CONFIGURED = 'PROMPT_DB_PROXY_SERVER_NOT_CONFIGURED';
	public const ERROR_REQUEST = 'PROMPT_DB_REQUEST_ERROR';
	public const ERROR_NETWORK = 'NETWORK';
	public const ERROR_WRONG_SERVER_RESPONSE = 'WRONG_SERVER_RESPONSE';
	public const ERROR_EMPTY_RESPONSE = 'EMPTY_SERVER_RESPONSE';
	public const ERROR_MALFORMED_RESPONSE = 'MALFORMED_RESPONSE';
	public const ERROR_UNEXPECTED_WRAPPED_RESPONSE = 'PROMPT_DB_UNEXPECTED_WRAPPED_RESPONSE';
	public const ERROR_MICROSERVICE_RESPONSE = 'PROMPT_DB_MICROSERVICE_RESPONSE';
	public const ERROR_AUTH_DENIED = 'AIPROXY_PROMPT_DB_AUTH_DENIED';

	private const SOCKET_TIMEOUT = 20;
	private const STREAM_TIMEOUT = 120;

	private ?string $resolvedServiceUrl = null;
	private bool $expectsRawDownload = false;

	public function __construct()
	{
		// BaseSender needs an initial URL, while this client resolves the real endpoint lazily.
		parent::__construct('http://localhost');
	}

	public function getUpdateInfo(int $currentVersion, bool $needFull): Result
	{
		return $this->request(
			'aiproxy.PromptDb.getUpdateInfo',
			[
				'currentVersion' => $currentVersion,
				'needFull' => $needFull,
			],
			false,
		);
	}

	public function download(int $currentVersion, bool $needFull, string $mode): Result
	{
		return $this->request(
			'aiproxy.PromptDb.download',
			[
				'currentVersion' => $currentVersion,
				'needFull' => $needFull,
				'mode' => $mode,
			],
			true,
		);
	}

	public function getHttpClientParameters(): array
	{
		$parameters = parent::getHttpClientParameters();
		$parameters['disableSslVerification'] = false;
		$parameters['socketTimeout'] = self::SOCKET_TIMEOUT;
		$parameters['streamTimeout'] = self::STREAM_TIMEOUT;

		return $parameters;
	}

	protected function getServiceUrl(): string
	{
		if ($this->resolvedServiceUrl !== null)
		{
			return $this->resolvedServiceUrl;
		}

		$server = $this->getMostSuitableServer();
		if (!is_string($server) || trim($server) === '')
		{
			throw new RuntimeException('Prompt DB proxy server is not configured.');
		}

		$this->resolvedServiceUrl = $this->refineServiceUrl($server);

		return $this->resolvedServiceUrl;
	}

	protected function buildResult(HttpClient $httpClient, bool $requestResult): Result
	{
		if ($this->expectsRawDownload)
		{
			return $this->buildRawDownloadResult($httpClient, $requestResult);
		}

		return $this->buildJsonResponseResult($httpClient, $requestResult);
	}

	private function buildRawDownloadResult(HttpClient $httpClient, bool $requestResult): Result
	{
		return $this->buildPromptDbResult($httpClient, $requestResult, true);
	}

	private function buildJsonResponseResult(HttpClient $httpClient, bool $requestResult): Result
	{
		return $this->buildPromptDbResult($httpClient, $requestResult, false);
	}

	private function buildPromptDbResult(HttpClient $httpClient, bool $requestResult, bool $expectRawDownload): Result
	{
		$result = new Result();
		$response = (string)$httpClient->getResult();
		$status = (int)$httpClient->getStatus();

		if ($this->addTransportErrors($result, $httpClient, $requestResult))
		{
			return $result;
		}

		if ($this->addErrorsFromMicroserviceResponse($result, $response, $status, $expectRawDownload))
		{
			return $result;
		}

		if ($status !== 200)
		{
			$result->addError(new Error(
				"Server returned {$status} code",
				self::ERROR_WRONG_SERVER_RESPONSE,
				['httpStatus' => $status],
			));

			return $result;
		}

		if ($response === '')
		{
			$result->addError(new Error('Empty server response', self::ERROR_EMPTY_RESPONSE));

			return $result;
		}

		if ($expectRawDownload)
		{
			$result->setData([
				self::DATA_BODY => $response,
				self::DATA_STATUS => $status,
			]);

			return $result;
		}

		try
		{
			$parsedResponse = Json::decode($response);
		}
		catch (Throwable)
		{
			$result->addError(new Error('Could not parse server response.', self::ERROR_MALFORMED_RESPONSE));

			return $result;
		}

		if (is_array($parsedResponse['data'] ?? null))
		{
			$result->setData($parsedResponse['data']);
		}

		return $result;
	}

	private function addTransportErrors(Result $result, HttpClient $httpClient, bool $requestResult): bool
	{
		if ($requestResult)
		{
			return false;
		}

		$errors = $httpClient->getError();
		if (empty($errors))
		{
			$result->addError(new Error('Prompt DB proxy is not available.', self::ERROR_NETWORK));

			return true;
		}

		foreach ($errors as $code => $message)
		{
			$result->addError(new Error((string)$message, $code ?: self::ERROR_NETWORK));
		}

		return true;
	}

	private function request(string $action, array $parameters, bool $expectRawDownload): Result
	{
		try
		{
			return $this->performPromptDbRequest($action, $parameters, $expectRawDownload);
		}
		catch (Throwable $exception)
		{
			$result = new Result();
			$result->addError(new Error(
				$exception->getMessage(),
				$exception instanceof RuntimeException
					? self::ERROR_PROXY_SERVER_NOT_CONFIGURED
					: self::ERROR_REQUEST,
			));

			return $result;
		}
	}

	private function performPromptDbRequest(string $action, array $parameters, bool $expectRawDownload): Result
	{
		$this->expectsRawDownload = $expectRawDownload;

		try
		{
			return $this->performRequest($action, $parameters);
		}
		finally
		{
			$this->expectsRawDownload = false;
		}
	}

	/**
	 * `ServerList` keeps compatibility with `proxyServers` override for dev stands.
	 *
	 * @throws ArgumentException
	 */
	private function getMostSuitableServer(): ?string
	{
		return (new ServerList())->getMostSuitableServer();
	}

	private function addErrorsFromMicroserviceResponse(
		Result $result,
		string $response,
		int $httpStatus,
		bool $rejectWrappedSuccess = false,
	): bool
	{
		if ($response === '')
		{
			return false;
		}

		if (!$this->looksLikeMicroserviceEnvelope($response))
		{
			return false;
		}

		try
		{
			$parsedResponse = Json::decode($response);
		}
		catch (Throwable)
		{
			return false;
		}

		if (!is_array($parsedResponse))
		{
			return false;
		}

		$status = $parsedResponse['status'] ?? null;
		if ($status === 'error')
		{
			$errors = is_array($parsedResponse['errors'] ?? null) ? $parsedResponse['errors'] : [];
			if (empty($errors))
			{
				$result->addError(new Error(
					'Prompt DB proxy returned an error.',
					self::ERROR_MICROSERVICE_RESPONSE,
					['httpStatus' => $httpStatus],
				));

				return true;
			}

			foreach ($errors as $error)
			{
				if (!is_array($error))
				{
					$result->addError(new Error(
						'Prompt DB proxy returned an error.',
						self::ERROR_MICROSERVICE_RESPONSE,
						['httpStatus' => $httpStatus],
					));

					continue;
				}

				$customData = is_array($error['customData'] ?? null) ? $error['customData'] : [];
				$customData['httpStatus'] = $httpStatus;

				$result->addError(new Error(
					(string)($error['message'] ?? 'Prompt DB proxy returned an error.'),
					$error['code'] ?? self::ERROR_MICROSERVICE_RESPONSE,
					$customData,
				));
			}

			return true;
		}

		if ($rejectWrappedSuccess && $status === 'success' && array_key_exists('data', $parsedResponse))
		{
			$result->addError(new Error(
				'Prompt DB download returned a wrapped response instead of a file body.',
				self::ERROR_UNEXPECTED_WRAPPED_RESPONSE,
			));

			return true;
		}

		return false;
	}

	private function looksLikeMicroserviceEnvelope(string $response): bool
	{
		$prefix = ltrim(substr($response, 0, 128));

		return preg_match('/^\{\s*"(status|errors)"/', $prefix) === 1;
	}
}
