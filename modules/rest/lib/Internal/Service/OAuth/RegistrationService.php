<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Service\OAuth;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\SystemException;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Json;
use Bitrix\Rest\OAuth\Engine;

final class RegistrationService
{
	private const CLIENT_TYPE = 'B';
	private const REQUEST_FAILED_MESSAGE = 'OAuth registration request failed';
	private const INVALID_RESPONSE_MESSAGE = 'Wrong answer from service';

	public function __construct(
		private readonly Engine $engine,
		private readonly string $registrationUrl,
		private ?HttpClient $httpClient = null,
	)
	{
	}

	public function register(string $redirectUri, ?string $memberId): void
	{
		$query = [
			'redirect_uri' => $redirectUri,
			'type' => self::CLIENT_TYPE,
		];

		if ($memberId !== null)
		{
			$query['member_id'] = $memberId;
		}

		$query = \CRestUtil::signLicenseRequest($query, $this->engine->getLicense());
		$httpClient = $this->httpClient ??= new HttpClient();

		try
		{
			$response = $httpClient->post($this->registrationUrl, $query);
		}
		catch (\Throwable)
		{
			throw new SystemException(self::REQUEST_FAILED_MESSAGE);
		}

		if ($response === false)
		{
			throw new SystemException(self::REQUEST_FAILED_MESSAGE);
		}

		$status = $httpClient->getStatus();
		try
		{
			$result = Json::decode($response);
		}
		catch (ArgumentException)
		{
			throw new SystemException(
				$status >= 200 && $status < 300
					? self::INVALID_RESPONSE_MESSAGE
					: self::REQUEST_FAILED_MESSAGE,
			);
		}

		if (!is_array($result))
		{
			throw new SystemException(self::INVALID_RESPONSE_MESSAGE);
		}

		$error = $result['error'] ?? null;
		if (is_string($error) && $error !== '')
		{
			throw new SystemException($error);
		}

		if ($error !== null && $error !== '')
		{
			throw new SystemException(self::INVALID_RESPONSE_MESSAGE);
		}

		if ($status < 200 || $status >= 300)
		{
			throw new SystemException(self::REQUEST_FAILED_MESSAGE);
		}

		if (
			!is_string($result['client_id'] ?? null)
			|| $result['client_id'] === ''
			|| !is_string($result['client_secret'] ?? null)
			|| $result['client_secret'] === ''
		)
		{
			throw new SystemException(self::INVALID_RESPONSE_MESSAGE);
		}

		$this->engine->setAccess([
			'client_id' => $result['client_id'],
			'client_secret' => $result['client_secret'],
		]);
	}
}
