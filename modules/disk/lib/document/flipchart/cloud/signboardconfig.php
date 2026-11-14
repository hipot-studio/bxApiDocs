<?php

namespace Bitrix\Disk\Document\Flipchart\Cloud;

use Bitrix\Disk\Document\OnlyOffice\Cloud\BaseSender;
use Bitrix\Disk\Document\Flipchart\BoardSigningResult;
use Bitrix\Disk\Document\Flipchart\Configuration;
use Bitrix\Disk\Document\Flipchart\DualMode\ServiceAddress;
use Bitrix\Disk\Document\Flipchart\Enum\BoardIndexErrorCategory;
use Bitrix\Disk\Document\Flipchart\Http\BoardIndexHttpClient;
use Bitrix\Disk\Document\Flipchart\Http\RequestDeadline;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Result;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Json;
use Bitrix\Main\Web\Uri;

final class SignBoardConfig extends BaseSender
{
	private bool $indexing = false;
	private ?RequestDeadline $indexingDeadline = null;

	public function __construct(string $serviceUrl, private ?HttpClient $httpClient = null)
	{
		parent::__construct($serviceUrl);
	}

	public function signForIndexing(array $config, ?RequestDeadline $deadline = null): BoardSigningResult
	{
		if (strtolower((new Uri($this->getServiceUrl()))->getScheme()) !== 'https')
		{
			return BoardSigningResult::createError(BoardIndexErrorCategory::ProfileUnavailable);
		}

		$this->indexing = true;
		$this->indexingDeadline = $deadline ?? new RequestDeadline();
		try
		{
			return $this->sign($config);
		}
		finally
		{
			$this->indexing = false;
			$this->indexingDeadline = null;
		}
	}

	public function sign(array $config): Result
	{
		$clientId = Configuration::getCloudRegistrationData()['clientId'] ?? null;

		/** @see \Bitrix\DocumentProxy\Controller\SignBoardConfiguration::signAction */
		$result = $this->performRequest('documentproxy.SignBoardConfiguration.sign', [
			'clientId' => $clientId,
			'config' => $config,
		]);

		return $result;
	}

	public function decode(string $config): Result
	{
		$clientId = Configuration::getCloudRegistrationData()['clientId'] ?? null;

		/** @see \Bitrix\DocumentProxy\Controller\SignBoardConfiguration::decodeJwtAction */
		$result = $this->performRequest('documentproxy.SignBoardConfiguration.decodeJwt', [
			'clientId' => $clientId,
			'data' => $config,
		]);

		return $result;
	}

	protected function buildHttpClient(): HttpClient
	{
		if ($this->httpClient !== null)
		{
			return $this->httpClient;
		}
		if ($this->indexing && $this->indexingDeadline !== null)
		{
			return new BoardIndexHttpClient($this->indexingDeadline, $this->getHttpClientParameters());
		}

		return parent::buildHttpClient();
	}

	public function getHttpClientParameters(): array
	{
		$parameters = parent::getHttpClientParameters();
		if ($this->indexing)
		{
			$parameters['redirect'] = false;
			$parameters['disableSslVerification'] = false;
		}

		return $parameters;
	}

	protected function buildResult(HttpClient $httpClient, bool $requestResult): Result
	{
		if (!$this->indexing)
		{
			return parent::buildResult($httpClient, $requestResult);
		}

		$httpStatus = $httpClient->getStatus() ?: null;
		if (!$requestResult)
		{
			$transportErrorCodes = array_values(array_intersect(array_keys($httpClient->getError()), [
				'NETWORK', 'MULTIPART', 'REDIRECT', 'URI_SCHEME', 'URI_HOST', 'URI_PUNICODE', 'PRIVATE_IP',
			]));

			$category = $httpClient instanceof BoardIndexHttpClient && $httpClient->hasExceededDeadline()
				? BoardIndexErrorCategory::Timeout
				: BoardIndexErrorCategory::Transport;

			return BoardSigningResult::createError(
				$category,
				$httpStatus,
				$transportErrorCodes,
			);
		}

		if ($httpStatus !== null && $httpStatus !== 200)
		{
			return BoardSigningResult::createError(BoardIndexErrorCategory::Http, $httpStatus);
		}

		try
		{
			$response = Json::decode($httpClient->getResult());
		}
		catch (ArgumentException)
		{
			return BoardSigningResult::createError(BoardIndexErrorCategory::SigningEnvelopeInvalid, $httpStatus);
		}

		if (!is_array($response) || $httpStatus !== 200)
		{
			return BoardSigningResult::createError(BoardIndexErrorCategory::SigningEnvelopeInvalid, $httpStatus);
		}

		if (
			($response['status'] ?? null) === 'error'
			&& is_array($response['errors'] ?? null)
			&& $response['errors'] !== []
		)
		{
			foreach ($response['errors'] as $error)
			{
				if (!is_array($error) || !isset($error['code'], $error['message']))
				{
					return BoardSigningResult::createError(BoardIndexErrorCategory::SigningEnvelopeInvalid, $httpStatus);
				}
			}

			return BoardSigningResult::createError(BoardIndexErrorCategory::ClientRejected, $httpStatus);
		}

		$data = $response['data'] ?? null;
		if (
			($response['status'] ?? null) !== 'success'
			|| !empty($response['errors'])
			|| !is_array($data)
			|| !is_string($data['token'] ?? null)
			|| trim($data['token']) === ''
			|| !is_string($data['api_url'] ?? null)
			|| !ServiceAddress::isValid(rtrim($data['api_url'], '/'))
		)
		{
			return BoardSigningResult::createError(BoardIndexErrorCategory::SigningEnvelopeInvalid, $httpStatus);
		}

		return BoardSigningResult::createSuccess($data['token'], $data['api_url']);
	}
}
