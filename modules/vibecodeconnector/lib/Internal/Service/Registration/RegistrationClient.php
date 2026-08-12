<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Registration;

use Bitrix\Main\Service\MicroService\BaseSender;
use Bitrix\Vibecodeconnector\Internal\Exception\RegistrationFailedException;
use Bitrix\Vibecodeconnector\Internal\Service\Endpoint\EndpointResolver;

final class RegistrationClient extends BaseSender
{
	private const ACTION_REGISTER = 'register';
	private const ACTION_UNREGISTER = 'unregister';

	public function __construct(private readonly EndpointResolver $endpoints)
	{
		parent::__construct();
	}

	public function register(): RegistrationResponse
	{
		$parameters = [];

		$result = $this->performRequest(self::ACTION_REGISTER, $parameters);
		if (!$result->isSuccess())
		{
			$errors = $result->getErrors();
			$first = $errors[0] ?? null;

			throw new RegistrationFailedException(
				'Registration failed: ' . implode('; ', $result->getErrorMessages()),
				$first?->getCode() !== null ? (string)$first->getCode() : null,
			);
		}

		$data = $result->getData();
		$iss = (string)($data['iss'] ?? '');
		$publicKey = (string)($data['public_key'] ?? $data['vibecode_public_key'] ?? '');
		$portalId = (string)($data['portal_id'] ?? '');
		$ttlSeconds = (int)($data['ttl_seconds'] ?? 0);

		if ($iss === '')
		{
			throw new RegistrationFailedException(
				'Registration response missing iss',
				'ISS_MISSING',
			);
		}
		if ($publicKey === '')
		{
			throw new RegistrationFailedException(
				'Registration response missing public_key',
				'PUBLIC_KEY_MISSING',
			);
		}
		if ($portalId === '')
		{
			throw new RegistrationFailedException(
				'Registration response missing portal_id',
				'PORTAL_ID_MISSING',
			);
		}
		if ($ttlSeconds <= 0)
		{
			throw new RegistrationFailedException(
				'Registration response has invalid ttl_seconds',
				'TTL_SECONDS_INVALID',
			);
		}

		return new RegistrationResponse(
			iss: $iss,
			publicKey: $publicKey,
			portalId: $portalId,
			ttlSeconds: $ttlSeconds,
		);
	}

	public function unregister(string $iss): void
	{
		if ($iss === '')
		{
			throw new RegistrationFailedException('iss must not be empty', 'ISS_REQUIRED');
		}

		$parameters = [
			'iss' => $iss,
		];

		$result = $this->performRequest(self::ACTION_UNREGISTER, $parameters);
		if (!$result->isSuccess())
		{
			$errors = $result->getErrors();
			$first = $errors[0] ?? null;

			throw new RegistrationFailedException(
				'Unregister failed: ' . implode('; ', $result->getErrorMessages()),
				$first?->getCode() !== null ? (string)$first->getCode() : null,
			);
		}
	}

	protected function getServiceUrl(): string
	{
		return $this->endpoints->microservice();
	}
}
