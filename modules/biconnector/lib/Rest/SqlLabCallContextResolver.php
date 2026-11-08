<?php

namespace Bitrix\BIConnector\Rest;

use Bitrix\BIConnector\Integration\Superset\Integrator\Dto\User;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Rest\APAuth\Auth as APAuth;
use Bitrix\Rest\OAuth\Auth as OAuth;

class SqlLabCallContextResolver
{
	private const ACCESS_DENIED = 'SQLLAB_ACCESS_DENIED';

	private readonly SqlLabIdentityProvider $identityProvider;

	public function __construct(?SqlLabIdentityProvider $identityProvider = null)
	{
		$this->identityProvider = $identityProvider ?? new SqlLabIdentityProvider();
	}

	public function resolve(\CRestServer $server): Result
	{
		$result = new Result();
		$authType = $server->getAuthType();
		$authData = $server->getAuthData();
		if (
			!is_string($authType)
			|| !in_array($authType, [OAuth::AUTH_TYPE, APAuth::AUTH_TYPE], true)
			|| !is_array($authData)
			|| ($authData['auth_type'] ?? null) !== $authType
		)
		{
			return $this->deny($result);
		}

		$userId = $this->positiveInteger($authData['user_id'] ?? null);
		if ($userId === null)
		{
			return $this->deny($result);
		}

		$user = $this->identityProvider->getUserById($userId);
		if (
			!$user instanceof User
			|| !$user->active
			|| $this->identityProvider->getCurrentUserId() !== $userId
		)
		{
			return $this->deny($result);
		}

		$trustedId = $this->resolveTrustedId($server, $authType, $authData);
		if ($trustedId === null)
		{
			return $this->deny($result);
		}

		if (!$this->identityProvider->hasBiAccess($userId))
		{
			return $this->deny($result);
		}

		$callerKey = $this->hashCallerKey($authType . ':' . $trustedId);
		if ($callerKey === '')
		{
			return $this->deny($result);
		}

		$result->setData([
			'context' => new SqlLabCallContext($user, $callerKey, $authType),
		]);

		return $result;
	}

	protected function hashCallerKey(string $identity): string
	{
		return hash('sha256', $identity);
	}

	private function resolveTrustedId(\CRestServer $server, string $authType, array $authData): ?string
	{
		if ($authType === OAuth::AUTH_TYPE)
		{
			$trustedId = $this->identity($server->getClientId());
			$authDataId = $this->identity($authData['client_id'] ?? null);
		}
		else
		{
			// A webhook identity is the PasswordTable primary key: the auth data carries it as int,
			// the server getter as string.
			$trustedId = $this->identity($server->getPasswordId());
			$authDataId = $this->identity($authData['password_id'] ?? null);
			if ($trustedId === '' && $server instanceof \CRestServerBatchItem)
			{
				$trustedId = $authDataId;
			}
		}

		if ($trustedId === '' || $authDataId === '' || !hash_equals($authDataId, $trustedId))
		{
			return null;
		}

		return $trustedId;
	}

	private function identity(mixed $value): string
	{
		return is_int($value) || is_string($value) ? (string)$value : '';
	}

	private function positiveInteger(mixed $value): ?int
	{
		if (is_int($value))
		{
			return $value > 0 ? $value : null;
		}

		if (!is_string($value) || preg_match('/^[0-9]+$/D', $value) !== 1)
		{
			return null;
		}

		$integer = (int)$value;

		return $integer > 0 ? $integer : null;
	}

	private function deny(Result $result): Result
	{
		$result->addError(new Error('Access denied.', self::ACCESS_DENIED));

		return $result;
	}
}
