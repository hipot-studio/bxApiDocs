<?php

namespace Bitrix\Superset\Internal\Connector;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Error;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\HttpHeaders;
use Bitrix\Superset\Internal\HttpStatus;
use Bitrix\Superset\Internal\RequestResult;
use Bitrix\Main\Web\Http;

abstract class BaseConnector
{
	protected ?HttpClient $httpClient;
	protected array $httpClientOptions;

	/**
	 * @throws ArgumentException
	 */
	public function __construct(array $options = [])
	{
		$this->httpClientOptions = $options;

		$this->initHttpClient();
	}

	/**
	 * @return void
	 * @throws ArgumentException
	 */
	abstract protected function initHttpClient(): void;

	/**
	 * @param string $path
	 * @return string
	 */
	abstract public function buildRequestUrl(string $path): string;

	protected function processResult(bool $isSuccess, float $time): RequestResult
	{
		$resultTime = microtime(true) - $time;
		if (!$isSuccess)
		{
			$result = new RequestResult(HttpStatus::SERVER_DOWN, new HttpHeaders(), '', $resultTime);
			$result->addError(new Error('Server is not available'));

			return $result;
		}

		$result = new RequestResult(
			$this->httpClient->getStatus(),
			$this->httpClient->getHeaders(),
			$this->httpClient->getResult(),
			$resultTime
		);

		$errors = $this->httpClient->getError();

		if(!empty($errors))
		{
			foreach($errors as $code => $message)
			{
				$result->addError(new Error($message, $code));
			}
		}

		return $result;
	}

	public function get(string $url): RequestResult
	{
		$time = microtime(true);
		$submitResult = $this->httpClient->get($url);

		return $this->processResult($submitResult !== false, $time);
	}

	public function post(string $url, array $payload = []): RequestResult
	{
		$time = microtime(true);

		$postBody = static::buildRequestBody($payload);
		$submitResult = $this->httpClient->post($url, $postBody);

		return $this->processResult($submitResult !== false, $time);
	}

	protected static function buildRequestBody(array $payload = []): mixed
	{
		return $payload;
	}

	public function postMultipart(string $url, array $payload = []): RequestResult
	{
		$time = microtime(true);
		$submitResult = $this->httpClient->post($url, $payload, true);

		return $this->processResult($submitResult !== false, $time);
	}

	public function put(string $url, array $payload = []): RequestResult
	{
		$time = microtime(true);
		$postBody = static::buildRequestBody($payload);
		$submitResult = $this->httpClient->query(Http\Method::PUT, $url, $postBody);

		return $this->processResult($submitResult !== false, $time);
	}

	public function delete(string $url, array $payload = []): RequestResult
	{
		$time = microtime(true);
		$postBody = static::buildRequestBody($payload);
		$submitResult = $this->httpClient->query(Http\Method::DELETE, $url, $postBody);

		return $this->processResult($submitResult !== false, $time);
	}
}
