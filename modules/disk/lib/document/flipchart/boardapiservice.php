<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Flipchart;

use Bitrix\Disk\Document\FileDownloader;
use Bitrix\Disk\Document\Flipchart\DualMode\ServiceProfile;
use Bitrix\Disk\Document\Flipchart\Enum\BoardIndexErrorCategory;
use Bitrix\Disk\Document\Flipchart\Enum\BoardReadyStatus;
use Bitrix\Disk\Document\Flipchart\Http\BoardIndexHttpClient;
use Bitrix\Disk\Document\Flipchart\Http\RequestDeadline;
use Bitrix\Main\Result;
use Bitrix\Main\Web\Http\Method;
use Bitrix\Main\Web\Http\Response;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Json;
use Psr\Log\NullLogger;


class BoardApiService
{
	private const ELEMENTS_BODY_LENGTH_MAX = 32 * 1024 * 1024;

	private string $baseUrl;

	public function __construct(
		?string $baseUrl = null,
		private readonly ?JwtService $serverReadJwtService = null,
		private readonly ?ServiceProfile $serverReadProfile = null,
	)
	{
		if (is_null($baseUrl))
		{
			if (Configuration::isUsingDocumentProxy())
			{
				$jwt = new JwtService();
				$tokenFromProxy = $jwt->generateToken();
				if (!$tokenFromProxy)
				{
					$tokenFromProxy = $jwt->generateToken();
				}
				$baseUrl = $jwt->getApiUrlFromProxy();
				if (!$baseUrl)
				{
					throw new \Exception('Proxy inavailable');
				}
			}
			else
			{
				$baseUrl = Configuration::getApiHost();
			}
		}
		$this->baseUrl = $baseUrl;
	}

	public function getBoardElements(string $documentId): BoardElementsResult
	{
		if ($this->serverReadJwtService === null || $this->serverReadProfile === null)
		{
			return BoardElementsResult::createError(BoardIndexErrorCategory::ProfileUnresolved);
		}

		$deadline = new RequestDeadline();
		$signing = $this->serverReadJwtService->generateTokenForIndexing(
			$documentId,
			$this->serverReadProfile,
			$deadline,
		);
		if (!$signing->isSuccess())
		{
			return BoardElementsResult::createError($signing->errorCategory, $signing->httpStatus);
		}

		$httpClient = $this->createServerReadHttpClient($deadline);
		$httpClient->setRedirect(false);
		$httpClient->waitResponse(true);
		$httpClient->setTimeout(5);
		$httpClient->setStreamTimeout(20);
		$httpClient->setBodyLengthMax(self::ELEMENTS_BODY_LENGTH_MAX);
		$httpClient->setLogger(new NullLogger());
		$httpClient->setDebugLevel(0);
		$httpClient->setHeader('Accept', 'application/json');
		$httpClient->setHeader(Configuration::getClientTokenHeaderLookup(), $signing->token);

		$response = null;
		$httpClient->shouldFetchBody(static function (Response $receivedResponse) use (&$response): bool {
			$response = $receivedResponse;

			return $response->getStatusCode() >= 200
				&& $response->getStatusCode() < 300
				&& (float)$response->getHeaderLine('Content-Length') <= self::ELEMENTS_BODY_LENGTH_MAX;
		});

		$queryResult = $httpClient->query(Method::GET, rtrim($signing->apiUrl, '/') . '/api/v1/flip/elements');
		$httpStatus = $response?->getStatusCode() ?? $httpClient->getStatus();
		$httpStatus = $httpStatus > 0 ? $httpStatus : null;
		if ($httpClient instanceof BoardIndexHttpClient && $httpClient->hasExceededDeadline())
		{
			return BoardElementsResult::createError(BoardIndexErrorCategory::Timeout, $httpStatus);
		}
		if ($httpStatus !== null && ($httpStatus < 200 || $httpStatus >= 300))
		{
			return BoardElementsResult::createError(BoardIndexErrorCategory::Http, $httpStatus);
		}
		if (
			$response !== null
			&& (
				(float)$response->getHeaderLine('Content-Length') > self::ELEMENTS_BODY_LENGTH_MAX
				|| $response->getBody()->getSize() > self::ELEMENTS_BODY_LENGTH_MAX
			)
		)
		{
			return BoardElementsResult::createError(BoardIndexErrorCategory::ResponseTooLarge, $httpStatus);
		}
		if (!$queryResult || $httpStatus === null)
		{
			return BoardElementsResult::createError(BoardIndexErrorCategory::Transport, $httpStatus);
		}

		$body = $httpClient->getResult();
		if (strlen($body) > self::ELEMENTS_BODY_LENGTH_MAX)
		{
			return BoardElementsResult::createError(BoardIndexErrorCategory::ResponseTooLarge, $httpStatus);
		}

		try
		{
			$envelope = json_decode($body, associative: false, flags: JSON_THROW_ON_ERROR);
		}
		catch (\JsonException)
		{
			return BoardElementsResult::createError(BoardIndexErrorCategory::InvalidJson, $httpStatus);
		}
		if (!$envelope instanceof \stdClass || !isset($envelope->items) || !is_array($envelope->items))
		{
			return BoardElementsResult::createError(BoardIndexErrorCategory::ElementsEnvelopeInvalid, $httpStatus);
		}

		return BoardElementsResult::createSuccess($envelope->items);
	}

	protected function createServerReadHttpClient(RequestDeadline $deadline): HttpClient
	{
		return new BoardIndexHttpClient(
			$deadline,
			[
				'curlLogFile' => '',
				'sendEvents' => false,
			],
		);
	}

	public function downloadBoard(
		string $url,
		string $method = Method::GET,
		$entityBody = null,
		$creatingNew = false,
		$isNewBoard = false,
		?string $documentId = null,
	): Result
	{
		$url = $this->baseUrl . $url;
		$additionalData = [
			'analytics' => [
				'action' => $creatingNew ? 'creating' : 'save_changes',
				'value1' =>
					$creatingNew
						? null
						: ($isNewBoard ? 'new_element' : 'old_element'),
			],
		];
		if ($documentId !== null)
		{
			$additionalData['document_id'] = $documentId;
		}

		$token = (new JwtService())->generateToken(
			false,
			$additionalData,
		);

		$httpClient = new HttpClient();
		$httpClient->disableSslVerification();
		$httpClient->setHeader(
			Configuration::getClientTokenHeaderLookup(),
			$token,
		);

		$downloader = new FileDownloader(
			$url,
			$httpClient,
		);

		return $downloader->download($method, $entityBody);
	}

	public function downloadBlank(): Result
	{
		return $this->downloadBoard('/api/v1/file/new', Method::POST, null, true);
	}

	public function kickUsers(string $documentId, array $userIds): bool|string
	{
		$url = $this->baseUrl . '/api/v1/flip/kick';
		$token = (new JwtService())->generateToken(
			false,
			[
				'document_id' => $documentId,
			]
		);

		$httpClient = new HttpClient();
		$httpClient->disableSslVerification();
		$httpClient->setHeader('Content-Type', 'application/json');
		$httpClient->setHeader(
			Configuration::getClientTokenHeaderLookup(),
			$token,
		);

		$userIds = array_map(static fn ($userId) => (string)$userId, $userIds);

		return $httpClient->post($url, Json::encode([
			'user_id' => $userIds,
		]));
	}

	public function getActiveUsersByDocumentId(string $documentId): ?array
	{
		$url = $this->baseUrl . '/api/v1/flip/users';
		$token = (new JwtService())->generateToken(
			false,
			[
				'document_id' => $documentId
			]
		);

		$httpClient = new HttpClient();
		$httpClient->disableSslVerification();
		$httpClient->setHeader('Content-Type', 'application/json');
		$httpClient->setHeader(
			Configuration::getClientTokenHeaderLookup(),
			$token,
		);

		$data = $httpClient->get($url);

		if (!$data || !Json::validate($data))
		{
			return null;
		}

		return (array) Json::decode($data);
	}

	public function getBoardReadyStatus(string $documentId): BoardReadyStatus
	{
		$url = $this->baseUrl . '/api/v1/file/status';
		$token = (new JwtService())->generateToken(
			false,
			[
				'document_id' => $documentId
			]
		);

		$httpClient = new HttpClient();
		$httpClient->disableSslVerification();
		$httpClient->setHeader('Content-Type', 'application/json');
		$httpClient->setHeader(
			Configuration::getClientTokenHeaderLookup(),
			$token,
		);

		$data = $httpClient->get($url);

		if (!$data || !Json::validate($data))
		{
			return BoardReadyStatus::ERROR;
		}

		$data = (array)Json::decode($data);
		if (!array_key_exists('status', $data))
		{
			return BoardReadyStatus::ERROR;
		}

		$status = BoardReadyStatus::tryFrom($data['status']);
		if (!$status)
		{
			return BoardReadyStatus::ERROR;
		}

		return $status;
	}
}
