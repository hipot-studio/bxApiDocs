<?php

namespace Bitrix\Superset\Internal\Services;

use Bitrix\Main;
use Bitrix\Superset\Internal\Api;
use Bitrix\Superset\Internal\Dto\JwtStatus;
use Bitrix\Superset\Internal\HttpStatus;
use Bitrix\Superset\Internal\Support\AbstractSupersetContext;

final class JwtKeySynchronizationService extends AbstractSupersetContext
{
	private const LOCK_TIMEOUT = 5;
	private const ALLOWED_STATES = ['ok', 'missing', 'invalid'];
	private const ALLOWED_SOURCES = ['bx_props', 'env', 'none'];

	public function __construct(
		\Bitrix\Superset\Internal\Entities\Server $server,
		?\Bitrix\Superset\Internal\Connector\SupersetInstance $connector = null,
		private ?JwtKeyFingerprint $jwtKeyFingerprint = null,
		private ?JwtSynchronizationLock $jwtSynchronizationLock = null,
	)
	{
		parent::__construct($server, $connector);

		$this->jwtKeyFingerprint ??= new JwtKeyFingerprint();
		$this->jwtSynchronizationLock ??= new JwtSynchronizationLock();
	}

	public function ensure(): Main\Result
	{
		$validationResult = $this->validateServer();
		if (!$validationResult->isSuccess())
		{
			return $validationResult;
		}

		$expectedFingerprint = $this->resolveExpectedFingerprint();
		if ($expectedFingerprint !== null)
		{
			$statusResult = $this->fetchStatus();
			if (!$statusResult->isSuccess())
			{
				return $statusResult;
			}

			$status = $statusResult->getData()['status'] ?? null;
			if ($status instanceof JwtStatus && $this->isSynchronized($status, $expectedFingerprint))
			{
				return new Main\Result();
			}
		}

		return $this->repairSynchronization();
	}

	private function validateServer(): Main\Result
	{
		$result = new Main\Result();

		if ((string)$this->server->getHost() === '')
		{
			$result->addError(new Main\Error('Superset host is required for JWT key sync'));
		}

		if ((string)$this->server->getInstanceUsername() === '')
		{
			$result->addError(new Main\Error('Superset instance username is required for JWT key sync'));
		}

		return $result;
	}

	private function repairSynchronization(): Main\Result
	{
		if (!$this->jwtSynchronizationLock->acquire($this->server, self::LOCK_TIMEOUT))
		{
			$result = new Main\Result();
			$result->addError(new Main\Error('JWT key sync is already in progress'));

			return $result;
		}

		try
		{
			$expectedFingerprint = $this->resolveExpectedFingerprint();
			if ($expectedFingerprint !== null)
			{
				$statusResult = $this->fetchStatus();
				if (!$statusResult->isSuccess())
				{
					return $statusResult;
				}

				$status = $statusResult->getData()['status'] ?? null;
				if ($status instanceof JwtStatus && $this->isSynchronized($status, $expectedFingerprint))
				{
					return new Main\Result();
				}
			}

			$generateResult = (new ServerRuntimeService())->generateJwtKeys($this->server);
			if (!$generateResult->isSuccess())
			{
				return $generateResult;
			}

			$publicKey = (string)(
				$generateResult->getData()['jwt_public_key']
				?? $generateResult->getData()['publicKey']
				?? ''
			);
			if ($publicKey === '')
			{
				$result = new Main\Result();
				$result->addError(new Main\Error('Generated JWT public key is empty'));

				return $result;
			}

			$pushResult = (new JwtKeyPushService($this->server, $this->connector))->pushPublicKey($publicKey);
			if (!$pushResult->isSuccess())
			{
				return $pushResult;
			}

			$expectedFingerprint = $this->resolveExpectedFingerprint();
			if ($expectedFingerprint === null)
			{
				$result = new Main\Result();
				$result->addError(new Main\Error('Failed to resolve fingerprint for regenerated JWT key'));

				return $result;
			}

			$statusResult = $this->fetchStatus();
			if (!$statusResult->isSuccess())
			{
				return $statusResult;
			}

			$status = $statusResult->getData()['status'] ?? null;
			if (!$status instanceof JwtStatus || !$this->isSynchronized($status, $expectedFingerprint))
			{
				$result = new Main\Result();
				$result->addError(new Main\Error('JWT key fingerprint mismatch after repair'));

				return $result;
			}

			return new Main\Result();
		}
		catch (\Throwable $exception)
		{
			$result = new Main\Result();
			$result->addError(new Main\Error('JWT key sync failed: ' . $exception->getMessage()));

			return $result;
		}
		finally
		{
			$this->jwtSynchronizationLock->release($this->server);
		}
	}

	private function resolveExpectedFingerprint(): ?string
	{
		$jwtSecret = (string)$this->server->getJwtSecret();
		if ($jwtSecret === '')
		{
			return null;
		}

		try
		{
			return $this->jwtKeyFingerprint->getFingerprintFromPrivateKey($jwtSecret);
		}
		catch (\Throwable)
		{
			return null;
		}
	}

	private function fetchStatus(): Main\Result
	{
		$requestResult = (new Api\JwtStatus($this->connector))->getStatus();
		if ($requestResult->getHttpStatus() !== HttpStatus::OK)
		{
			return $this->createRequestErrorResult($requestResult, 'Superset JWT status request failed');
		}

		$decodedResponse = $this->decode($requestResult->getAnswer());
		$payload = is_array($decodedResponse) ? ($decodedResponse['result'] ?? null) : null;
		if (!is_array($payload))
		{
			return $this->createErrorResult('Superset JWT status response does not contain result payload');
		}

		$state = $payload['state'] ?? null;
		if (!is_string($state) || !in_array($state, self::ALLOWED_STATES, true))
		{
			return $this->createErrorResult('Superset JWT status response contains invalid state');
		}

		$source = $payload['source'] ?? null;
		if (!is_string($source) || !in_array($source, self::ALLOWED_SOURCES, true))
		{
			return $this->createErrorResult('Superset JWT status response contains invalid source');
		}

		$fingerprintSha256 = $payload['fingerprint_sha256'] ?? null;
		if (
			$fingerprintSha256 !== null
			&& (!is_string($fingerprintSha256) || trim($fingerprintSha256) === '')
		)
		{
			return $this->createErrorResult('Superset JWT status response contains invalid fingerprint');
		}

		$result = new Main\Result();
		$result->setData([
			'status' => new JwtStatus($state, $source, $fingerprintSha256),
		]);

		return $result;
	}

	private function isSynchronized(JwtStatus $status, string $expectedFingerprint): bool
	{
		return $status->state === 'ok' && $status->fingerprintSha256 === $expectedFingerprint;
	}
}
