<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Entity\CustomServers;

use Bitrix\Disk\Internal\Enum\VersionTypes;
use Bitrix\Disk\Internal\Service\VersionMatcher\Matcher;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\DI\Exception\CircularDependencyException;
use Bitrix\Main\DI\Exception\ServiceNotFoundException;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\Result;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Json;
use Bitrix\Main\Web\JWT;
use CFile;

class R7CustomServer extends AbstractCustomServer
{
	public const DATA_URL_KEY = 'url';
	public const DATA_SECRET_KEY_KEY = 'secretKey';
	public const DATA_MAX_FILE_SIZE_KEY = 'maxFileSize';

	protected ?string $version = null;

	/**
	 * @return string|null
	 */
	public function getUrl(): ?string
	{
		return $this->data[static::DATA_URL_KEY] ?? null;
	}

	/**
	 * @return string|null
	 */
	public function getSecretKey(): ?string
	{
		return $this->data[static::DATA_SECRET_KEY_KEY] ?? null;
	}

	/**
	 * @return float|null
	 */
	public function getMaxFileSizeForEdit(): ?float
	{
		$maxFileSizeFromData = $this->getMaxFileSizeFromData();

		if (!is_int($maxFileSizeFromData))
		{
			return null;
		}

		return round($maxFileSizeFromData / 1048576, 1);
	}

	/**
	 * @return int|null
	 */
	public function getMaxFileSizeForUse(): ?int
	{
		return $this->getMaxFileSizeFromData() ?? $this->getMaxFileSizeFromConfig();
	}

	/**
	 * @return string|null
	 */
	public function getDefaultMaxFileSizeForView(): ?string
	{
		$maxFileSizeFromConfig = $this->getMaxFileSizeFromConfig();

		if (!is_int($maxFileSizeFromConfig))
		{
			return null;
		}

		return CFile::FormatSize($maxFileSizeFromConfig);
	}

	/**
	 * @return Result
	 * @throws ArgumentException
	 */
	public function getVersion(): Result
	{
		$result = new Result();

		if (!is_string($this->version))
		{
			$fetchResult = $this->fetchVersion();

			if ($fetchResult->isSuccess())
			{
				$this->version = $fetchResult->getData()['version'] ?? null;

				if (!is_string($this->version))
				{
					return $result->addError(new Error(
						message: Loc::getMessage('DISK_CUSTOM_SERVER_R7_NO_SERVER_VERSION'),
					));
				}
			}
			else
			{
				return $result->addErrors($fetchResult->getErrors());
			}
		}

		return $result->setData([
			'version' => $this->version,
		]);
	}

	/**
	 * @param string $version
	 * @return bool
	 * @throws CircularDependencyException
	 * @throws ServiceNotFoundException
	 * @throws ObjectNotFoundException
	 */
	public function isVersionSupported(string $version): bool
	{
		$supportedVersionsConfig = $this->config?->getSupportedVersions();
		$type = $supportedVersionsConfig['type'] ?? null;

		if (!is_string($type))
		{
			return false;
		}

		$type = VersionTypes::tryFrom($type);
		$templates = $supportedVersionsConfig['values'] ?? null;

		if (!$type instanceof VersionTypes || !is_array($templates))
		{
			return false;
		}

		/** @var Matcher $matcher */
		$matcher = ServiceLocator::getInstance()->get(Matcher::class);

		return is_string($matcher->matchMultiple($type, $version, $templates));
	}

	/**
	 * {@inheritDoc}
	 * @throws ArgumentException
	 * @see \Bitrix\Disk\Document\OnlyOffice\OnlyOfficeHandler::isValidToken
	 */
	public function prepareDataForConnect(): ?Error
	{
		$url = $this->getUrl();

		if (!is_string($url))
		{
			return new Error(
				message: Loc::getMessage('DISK_CUSTOM_SERVER_R7_INVALID_URL'),
			);
		}

		$this->data[static::DATA_URL_KEY] = rtrim($url, '/');
		$fetchVersionWithoutSecretKeyResult = $this->fetchVersion(false);

		if ($fetchVersionWithoutSecretKeyResult->isSuccess())
		{
			return new Error(
				message: Loc::getMessage(
					code: 'DISK_CUSTOM_SERVER_R7_DISABLED_JWT',
					replace: [
						'#LINK#' => 'https://support.r7-office.ru/document_server/api-document_server/more_api/signature-api/signature-2/',
					],
				),
			);
		}
		else
		{
			$error = $fetchVersionWithoutSecretKeyResult->getError();
			$isInvalidSecretKey = $error?->getCustomData()['isInvalidSecretKey'] ?? false;

			if (!$isInvalidSecretKey)
			{
				return $error;
			}
		}

		$fetchVersionWithSecretKeyResult = $this->fetchVersion();

		if (!$fetchVersionWithSecretKeyResult->isSuccess())
		{
			return $fetchVersionWithSecretKeyResult->getError();
		}

		$maxFileSize = $this->data[static::DATA_MAX_FILE_SIZE_KEY] ?? null;

		if (is_string($maxFileSize) && $maxFileSize !== '')
		{
			$this->data[static::DATA_MAX_FILE_SIZE_KEY] = (string)(int)(((float)$maxFileSize) * 1048576);
		}

		return null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function isReadyForUse(): bool
	{
		return
			is_string($this->getUrl()) &&
			is_string($this->getSecretKey());
	}

	/**
	 * @param bool $useSecretKey
	 * @return Result
	 * @throws ArgumentException
	 */
	protected function fetchVersion(bool $useSecretKey = true): Result
	{
		$result = new Result();

		if (!$this->isReadyForUse())
		{
			return $result->addError(new Error(
				message: Loc::getMessage('DISK_CUSTOM_SERVER_R7_NOT_CONFIGURED'),
				customData: [
					'isNotReadyForUse' => true,
				],
			));
		}

		$url = "{$this->getUrl()}/coauthoring/CommandService.ashx";
		$secretKey = $this->getSecretKey();

		$rawBody = ['c' => 'version'];
		$body = Json::encode($rawBody);

		$httpClient = new HttpClient([
			'socketTimeout' => 5,
			'streamTimeout' => 5,
			'version' => HttpClient::HTTP_1_1,
		]);

		$httpClient->setHeader('Content-Type', 'application/json');

		if ($useSecretKey)
		{
			$httpClient->setHeader('Authorization', 'Bearer ' . JWT::encode($rawBody, $secretKey));
		}

		if ($httpClient->post($url, $body) === false)
		{
			return $result->addError(new Error(
				message: Loc::getMessage('DISK_CUSTOM_SERVER_R7_UNAVAILABLE'),
			));
		}

		if ($httpClient->getStatus() !== 200)
		{
			return $result->addError(new Error(
				message: Loc::getMessage(
					code: 'DISK_CUSTOM_SERVER_R7_UNAVAILABLE_WITH_STATUS',
					replace: [
						'#STATUS#' => $httpClient->getStatus(),
					],
				),
			));
		}

		$response = Json::decode($httpClient->getResult());

		if (isset($response['error']) && $response['error'] !== 0)
		{
			return $result->addError(new Error(
				message: Loc::getMessage(
					code: 'DISK_CUSTOM_SERVER_R7_INVALID_JWT',
					replace: [
						'#LINK#' => 'https://support.r7-office.ru/document_server/api-document_server/more_api/signature-api/signature-2/',
					],
				),
				customData: [
					'isInvalidSecretKey' => true,
				],
			));
		}

		return $result->setData([
			'version' => $response['version'] ?? null,
		]);
	}

	/**
	 * @return int|null
	 */
	protected function getMaxFileSizeFromData(): ?int
	{
		$maxFileSizeString = $this->data[static::DATA_MAX_FILE_SIZE_KEY] ?? null;

		if (!is_string($maxFileSizeString) || $maxFileSizeString === '')
		{
			return null;
		}

		return (int)$maxFileSizeString;
	}

	/**
	 * @return int|null
	 */
	protected function getMaxFileSizeFromConfig(): ?int
	{
		return $this->config?->getMaxFileSize();
	}
}
