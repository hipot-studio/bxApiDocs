<?php

declare(strict_types=1);

namespace Bitrix\Crm\Service\Timeline\Navigation;

use Bitrix\Main\Security\Cipher;
use Bitrix\Main\Security\Sign\Signer;
use Bitrix\Main\Web\Json;

final class SelectedTypeIdentityCodec
{
	private const SIGN_SALT = 'crm.timeline.navigation.selected-type.v1';
	private const ENCRYPT_SALT = 'crm.timeline.navigation.selected-type.v1.encrypt';
	private const TTL = 2592000;

	public function __construct(
		private readonly ?Signer $signer = null,
		private readonly ?Cipher $cipher = null,
		private readonly mixed $clock = null,
	)
	{
	}

	public function issue(SelectedTypeIdentityPayload|array $payload): string
	{
		$values = $payload instanceof SelectedTypeIdentityPayload ? $payload->toArray() : $payload;
		$now = $this->now();
		$values['v'] = 1;
		$values['macKeyVersion'] = 1;
		$values['issuedAt'] = $now;
		$values['expiresAt'] = min((int)($values['expiresAt'] ?? PHP_INT_MAX), $now + self::TTL);
		SelectedTypeIdentityPayload::fromArray($values);
		$signer = $this->signer ?? new Signer();
		$key = $signer->getSignature('selected-type-key', self::ENCRYPT_SALT);
		$encrypted = ($this->cipher ?? new Cipher())->encrypt(Json::encode($values), $key);

		return $signer->sign(rtrim(strtr(base64_encode($encrypted), '+/', '-_'), '='), self::SIGN_SALT);
	}

	public function verify(string $identity, array $bindings = []): SelectedTypeIdentityPayload
	{
		try
		{
			$signer = $this->signer ?? new Signer();
			$encoded = $signer->unsign($identity, self::SIGN_SALT);
			$binary = base64_decode(strtr($encoded, '-_', '+/'), true);
			if ($binary === false)
			{
				throw new \InvalidArgumentException('Invalid selected type identity');
			}
			$key = $signer->getSignature('selected-type-key', self::ENCRYPT_SALT);
			$values = Json::decode(($this->cipher ?? new Cipher())->decrypt($binary, $key));
			if (!is_array($values) || ($values['v'] ?? null) !== 1 || ($values['macKeyVersion'] ?? null) !== 1)
			{
				throw new \InvalidArgumentException('Invalid selected type identity version');
			}
			$payload = SelectedTypeIdentityPayload::fromArray($values);
			if ($payload->expiresAt < $this->now())
			{
				throw new \InvalidArgumentException('Selected type identity expired');
			}
			foreach ($bindings as $key => $value)
			{
				if (($payload->bindings[$key] ?? null) !== $value)
				{
					throw new \InvalidArgumentException('Selected type identity binding does not match');
				}
			}

			return $payload;
		}
		catch (\Throwable $exception)
		{
			throw new \InvalidArgumentException('Invalid selected type identity', 0, $exception);
		}
	}

	private function now(): int
	{
		return is_callable($this->clock) ? (int)($this->clock)() : time();
	}
}
