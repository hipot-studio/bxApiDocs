<?php

namespace Bitrix\Crm\Service\Timeline\Navigation;

use Bitrix\Crm\Service\Timeline\Navigation\Catalog\CatalogRunStore;
use Bitrix\Main\Security\Cipher;
use Bitrix\Main\Security\Sign\Signer;
use Bitrix\Main\Web\Json;

final class ContinuationToken
{
	private const SIGN_SALT = 'crm.timeline.navigation.v1';
	private const ENCRYPT_SALT = 'crm.timeline.navigation.v1.encrypt';
	private const TTL = 1800;
	private const MAX_TOKEN_SIZE = 4096;

	public function __construct(
		private readonly ?Signer $signer = null,
		private readonly ?Cipher $cipher = null,
		private readonly mixed $clock = null,
	)
	{
	}

	public function issue(string $kind, CursorPayload|array $payload): string
	{
		if (!in_array($kind, ['history', 'catalog'], true))
		{
			throw new \InvalidArgumentException('Invalid cursor kind');
		}
		$values = $payload instanceof CursorPayload ? $payload->toArray() : $payload;
		$now = $this->now();
		$values['v'] = 1;
		$values['kind'] = $kind;
		$values['policyVersion'] = NavigationSnapshot::POLICY_VERSION;
		$values['issuedAt'] = $now;
		$values['expiresAt'] = $now + self::TTL;
		$this->validate($values, $kind);

		$signer = $this->signer ?? new Signer();
		$cipher = $this->cipher ?? new Cipher();
		$key = $signer->getSignature('cursor-key', self::ENCRYPT_SALT);
		$encrypted = $cipher->encrypt(Json::encode($values), $key);
		$encoded = rtrim(strtr(base64_encode($encrypted), '+/', '-_'), '=');
		$token = $signer->sign($encoded, self::SIGN_SALT);
		if (strlen($token) > self::MAX_TOKEN_SIZE)
		{
			throw new \InvalidArgumentException('Cursor is too large');
		}

		return $token;
	}

	public function verify(string $token, array $context): CursorPayload
	{
		if ($token === '' || strlen($token) > self::MAX_TOKEN_SIZE)
		{
			throw new \InvalidArgumentException('Invalid cursor');
		}
		try
		{
			$signer = $this->signer ?? new Signer();
			$encoded = $signer->unsign($token, self::SIGN_SALT);
			$binary = base64_decode(strtr($encoded, '-_', '+/'), true);
			if ($binary === false)
			{
				throw new \InvalidArgumentException('Invalid cursor encoding');
			}
			$key = $signer->getSignature('cursor-key', self::ENCRYPT_SALT);
			$values = Json::decode(($this->cipher ?? new Cipher())->decrypt($binary, $key));
			if (!is_array($values))
			{
				throw new \InvalidArgumentException('Invalid cursor payload');
			}
		}
		catch (\Throwable $exception)
		{
			throw new \InvalidArgumentException('Invalid cursor', 0, $exception);
		}

		$kind = (string)($context['kind'] ?? $values['kind'] ?? '');
		$this->validate($values, $kind);
		if ((int)$values['expiresAt'] < $this->now())
		{
			throw new \RuntimeException('Cursor expired');
		}
		foreach (['kind', 'userId', 'owner', 'scopeId', 'viewId', 'snapshotFingerprint', 'limit'] as $binding)
		{
			if (array_key_exists($binding, $context) && ($values[$binding] ?? null) !== $context[$binding])
			{
				throw new \InvalidArgumentException('Cursor binding does not match');
			}
		}
		if (
			$kind === 'catalog'
			&& array_key_exists('catalogDigest', $context)
			&& ($values['catalogDigest'] ?? null) !== $context['catalogDigest']
			&& !(new CatalogRunStore())->hasRun((string)($values['runId'] ?? ''))
		)
		{
			throw new \InvalidArgumentException('NAV_CATALOG_STALE');
		}

		return new CursorPayload($values);
	}

	private function validate(array $values, string $kind): void
	{
		$required = [
			'v', 'kind', 'userId', 'owner', 'scopeId', 'viewId',
			'policyVersion', 'issuedAt', 'expiresAt', 'position',
		];
		foreach ($required as $key)
		{
			if (!array_key_exists($key, $values))
			{
				throw new \InvalidArgumentException('Incomplete cursor payload');
			}
		}
		if (
			($values['v'] ?? null) !== 1
			|| ($values['kind'] ?? null) !== $kind
			|| !in_array($kind, ['history', 'catalog'], true)
		)
		{
			throw new \InvalidArgumentException('Invalid cursor version or kind');
		}
		if (
			$kind === 'catalog'
			&& (
				!is_string($values['catalogDigest'] ?? null)
				|| preg_match('/^[a-f0-9]{64}$/D', $values['catalogDigest']) !== 1
				|| !is_string($values['runId'] ?? null)
				|| (int)($values['expectedVersion'] ?? 0) < 1
			)
		)
		{
			throw new \InvalidArgumentException('Invalid catalog cursor');
		}
		if ((int)$values['userId'] <= 0 || !is_array($values['owner']) || !is_array($values['position']))
		{
			throw new \InvalidArgumentException('Invalid cursor identity');
		}
		if (
			$kind === 'history'
			&& (
				!isset($values['snapshotFingerprint'], $values['limit'])
				|| (int)$values['limit'] < 1
				|| (int)$values['limit'] > 50
			)
		)
		{
			throw new \InvalidArgumentException('Invalid history cursor');
		}
		if ((int)$values['expiresAt'] <= (int)$values['issuedAt'])
		{
			throw new \InvalidArgumentException('Invalid cursor lifetime');
		}
	}

	private function now(): int
	{
		return is_callable($this->clock) ? (int)($this->clock)() : time();
	}
}
