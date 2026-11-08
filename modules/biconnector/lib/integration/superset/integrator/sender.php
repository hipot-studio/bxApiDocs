<?php

namespace Bitrix\BIConnector\Integration\Superset\Integrator;

use Bitrix\BIConnector\Superset\Config\ConfigContainer;
use Bitrix\Main\Error;
use Bitrix\Main\IO\File;
use Bitrix\Main\IO\FileOpenException;
use Bitrix\Main\Result;
use Bitrix\Main\Service\MicroService;
use Bitrix\Main\Service\MicroService\Client;
use Bitrix\Main\Web\Http\MultipartStream;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Json;

class Sender extends MicroService\BaseSender
{
	public const ERROR_MALFORMED_RESPONSE = 'MALFORMED_RESPONSE';
	public const ERROR_HTTPS_REQUIRED = 'HTTPS_REQUIRED';

	private const API_VERSION = 2;
	private array $singleUseHttpClientParams = [];
	private bool $secureRequest = false;
	private bool $requestDispatched = false;

	protected function getServiceUrl(): string
	{
		return ServiceLocation::getCurrentServiceUrl();
	}

	protected function getProxyPath(): string
	{
		return "/api/v1";
	}

	/**
	 * @throws RequestNotSentException when the request failed before it was handed over to the transport.
	 */
	public function performSecureRequest($action, #[\SensitiveParameter] array $parameters = []): Result
	{
		$this->secureRequest = true;
		$this->requestDispatched = false;
		$this->singleUseHttpClientParams = array_merge($this->singleUseHttpClientParams, [
			'disableSslVerification' => false,
			'redirect' => false,
		]);
		try
		{
			return $this->performRequest($action, $parameters);
		}
		catch (\Throwable $exception)
		{
			if ($this->requestDispatched)
			{
				throw $exception;
			}

			throw new RequestNotSentException($exception);
		}
		finally
		{
			$this->secureRequest = false;
			$this->requestDispatched = false;
			$this->singleUseHttpClientParams = [];
		}
	}

	/**
	 * @inheritDoc
	 */
	public function performRequest($action, array $parameters = [], ?Dto\User $user = null): Result
	{
		$serviceUrl = $this->getServiceUrl();
		if ($this->secureRequest)
		{
			$scheme = parse_url($serviceUrl, PHP_URL_SCHEME);
			$host = parse_url($serviceUrl, PHP_URL_HOST);
			if (!is_string($scheme) || strcasecmp($scheme, 'https') !== 0 || !is_string($host) || $host === '')
			{
				$result = new Result();
				$result->setData(['status' => 426, 'data' => null]);
				$result->addError(new Error('Proxy URL must use HTTPS', self::ERROR_HTTPS_REQUIRED));

				return $result;
			}
		}
		$url = $serviceUrl . $this->getProxyPath() . $action;

		/*
		 * `trim($val, '=')` is used to bypass the proactive filter of the proxy server.
		 * 	The '=' sign is used for padding Base64 strings. Proactive filter might detect a string
		 * 	in the format `/[^a-z]on.+=/` and adding spaces to it to protect against potential XSS attacks,
		 * 	which causes signature mismatches.
		 */
		$serializedParameters = trim(base64_encode(gzencode(Json::encode($parameters))), '=');
		$request = [
			'action' => $action,
			'serializedParameters' => $serializedParameters,
		];

		$request['BX_TYPE'] = Client::getPortalType();
		$request['BX_LICENCE'] = Client::getLicenseCode();
		$request['SERVER_NAME'] = $this->getClientServerName();
		if ($user && $user->clientId)
		{
			$request['BX_CLIENT_ID'] = $user->clientId;
		}
		$request['BX_VERSION'] = self::API_VERSION;

		$portalId = ConfigContainer::getConfigContainer()->getPortalId();
		if (!empty($portalId))
		{
			$request['BX_PORTAL_ID'] = $portalId;
		}
		$request['BX_HASH'] = Client::signRequest($request);

		$httpClient = $this->buildHttpClient();
		$this->requestDispatched = true;
		$result = $httpClient->query(HttpClient::HTTP_POST, $url, $request);

		return $this->buildResult($httpClient, $result);
	}

	public function performMultipartRequest($action, array $parameters = [], ?Dto\User $user = null): Result
	{
		$url = $this->getServiceUrl() . $this->getProxyPath() . $action;
		$filePath = $parameters['filePath'] ?? '';
		if (!$filePath)
		{
			$result = new Result();
			$result->addError(new Error('File path is empty.'));

			return $result;
		}
		$file = new File($filePath);
		try
		{
			$fileData = $file->open('r');
		}
		catch (FileOpenException $e)
		{
			$result = new Result();
			$result->addError(new Error('Error performing multipart request: ' . $e->getMessage() . '. File path: ' . $filePath));

			return $result;
		}

		unset($parameters['filePath']);

		$data = $parameters + [
				'BX_TYPE' => Client::getPortalType(),
				'BX_LICENCE' => Client::getLicenseCode(),
				'SERVER_NAME' => $this->getClientServerName(),
			];

		if ($user && $user->clientId)
		{
			$data['BX_CLIENT_ID'] = $user->clientId;
		}

		$portalId = ConfigContainer::getConfigContainer()->getPortalId();
		if (!empty($portalId))
		{
			$data['BX_PORTAL_ID'] = $portalId;
		}

		$data['BX_VERSION'] = self::API_VERSION;
		$data['BX_HASH'] = Client::signRequest($data);
		$data[] = [
			'resource' => $fileData,
			'filename' => $file->getName(),
		];
		$body = new MultipartStream($data);

		$httpClient = $this->buildHttpClient();
		$httpClient->setHeader('Content-Type', 'multipart/form-data; boundary=' . $body->getBoundary());

		$result = $httpClient->query(HttpClient::HTTP_POST, $url, $body);
		$file->close();

		return $this->buildResult($httpClient, $result);
	}

	protected function createAnswerForJsonResponse($queryResult, $response, $errors, $status): Result
	{
		$result = new Result();

		if(!$queryResult)
		{
			foreach ($errors as $code => $message)
			{
				$result->addError(new Error($message, $code));
			}

			return $result;
		}


		$resultBody = [
			'status' => $status,
			'data' => null,
		];

		if ($response !==  "")
		{
			$parseResult = self::tryBuildResponseResult($response, $this->secureRequest) ?? self::createMalformedResponseResult();

			$result->addErrors($parseResult->getErrors());

			$data = $parseResult->getData();
			$resultBody['data'] = $data;
		}

		$result->setData($resultBody);
		return $result;
	}

	private static function tryBuildResponseResult(string $response, bool $keepErrorData): ?Result
	{
		try
		{
			$parsedResponse = Json::decode($response);
		}
		catch (\Exception)
		{
			return null;
		}

		if (($parsedResponse["status"] ?? null) === "error")
		{
			return self::buildServiceErrorResult(
				$parsedResponse["errors"] ?? null,
				$keepErrorData ? ($parsedResponse["data"] ?? null) : null,
			);
		}

		$result = new Result();

		if (is_array($parsedResponse["data"] ?? null))
		{
			$result->setData($parsedResponse["data"]);
		}

		return $result;
	}

	private static function buildServiceErrorResult(mixed $serviceErrors, mixed $serviceData): ?Result
	{
		$result = new Result();
		if (is_array($serviceData))
		{
			$result->setData($serviceData);
		}

		foreach (is_array($serviceErrors) ? $serviceErrors : [] as $serviceError)
		{
			if (!is_array($serviceError) || !isset($serviceError["message"]))
			{
				continue;
			}

			$result->addError(new Error(
				$serviceError["message"],
				$serviceError["code"] ?? 0,
				$serviceError["customData"] ?? null,
			));
		}

		return $result->isSuccess() ? null : $result;
	}

	private static function createMalformedResponseResult(): Result
	{
		$result = new Result();
		$result->addError(new Error('Could not parse server response', self::ERROR_MALFORMED_RESPONSE));

		return $result;
	}

	public static function getCurrentPortalUrl(): string
	{
		if (defined('BX24_HOST_NAME'))
		{
			return "https://" . BX24_HOST_NAME;
		}

		if (\Bitrix\Main\Context::getCurrent()->getRequest()->isHttps())
		{
			$scheme = "https://";
		}
		else
		{
			$scheme = "http://";
		}

		$rootServerName = \Bitrix\Main\Config\Option::get('main', 'server_name');
		if (!empty($rootServerName))
		{
			return $scheme . $rootServerName;
		}

		if (defined("SITE_SERVER_NAME") && SITE_SERVER_NAME !== '')
		{
			return $scheme . SITE_SERVER_NAME;
		}

		$serverName = $_SERVER['SERVER_NAME'] ?? '';


		return $scheme . $serverName;
	}

	protected function getClientServerName(): string
	{
		return self::getCurrentPortalUrl();
	}

	public function setSingleUseHttpClientParams(array $params): void
	{
		$this->singleUseHttpClientParams = $params;
	}

	public function getHttpClientParameters(): array
	{
		$params = parent::getHttpClientParameters();

		if (!empty($this->singleUseHttpClientParams))
		{
			$params = array_merge($params, $this->singleUseHttpClientParams);
			$this->singleUseHttpClientParams = [];
		}

		return $params;
	}
}
