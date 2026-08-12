<?php

namespace Bitrix\Superset\Public\Dto;

use Bitrix\Main\Security\Sign\Signer;

final class ServerRuntimeStateDto
{
	public function __construct(
		private readonly int $serverId,
		private readonly int $accountId,
		private readonly string $portalId = '',
		private readonly string $portalUrl = '',
		private readonly string $host = '',
		private readonly string $instanceKey = '',
		private readonly string $instanceUsername = '',
		private readonly string $startJobId = '',
		private readonly int $version = 0,
		private readonly bool $isPortalIdVerified = false,
		private readonly string $accessPassword = '',
		private readonly string $token = '',
		private readonly string $refreshToken = '',
		private readonly ?string $dateStartAttempt = null,
	)
	{
	}

	public static function fromArray(array $data): self
	{
		return new self(
			serverId: (int)($data['serverId'] ?? $data['server_id'] ?? $data['id'] ?? 0),
			accountId: (int)($data['accountId'] ?? $data['account_id'] ?? 0),
			portalId: (string)($data['portalId'] ?? $data['portal_id'] ?? ''),
			portalUrl: (string)($data['portalUrl'] ?? $data['portal_url'] ?? ''),
			host: (string)($data['host'] ?? ''),
			instanceKey: (string)($data['instanceKey'] ?? $data['instance_key'] ?? ''),
			instanceUsername: (string)($data['instanceUsername'] ?? $data['instance_username'] ?? ''),
			startJobId: (string)($data['startJobId'] ?? $data['start_job_id'] ?? ''),
			version: (int)($data['version'] ?? 0),
			isPortalIdVerified: self::normalizeVerifiedFlag($data['isPortalIdVerified'] ?? $data['is_portal_id_verified'] ?? false),
			accessPassword: (string)($data['accessPassword'] ?? $data['access_password'] ?? ''),
			token: (string)($data['token'] ?? ''),
			refreshToken: (string)($data['refreshToken'] ?? $data['refresh_token'] ?? ''),
			dateStartAttempt: self::normalizeDate($data['dateStartAttempt'] ?? $data['date_start_attempt'] ?? null),
		);
	}

	public function getServerId(): int
	{
		return $this->serverId;
	}

	public function getAccountId(): int
	{
		return $this->accountId;
	}

	public function getPortalId(): string
	{
		return $this->portalId;
	}

	public function getPortalUrl(): string
	{
		return $this->portalUrl;
	}

	public function getHost(): string
	{
		return $this->host;
	}

	public function getInstanceKey(): string
	{
		return $this->instanceKey;
	}

	public function getInstanceUsername(): string
	{
		return $this->instanceUsername;
	}

	public function getStartJobId(): string
	{
		return $this->startJobId;
	}

	public function getVersion(): int
	{
		return $this->version;
	}

	public function isPortalIdVerified(): bool
	{
		return $this->isPortalIdVerified;
	}

	public function getAccessPassword(): string
	{
		return $this->accessPassword;
	}

	public function getToken(): string
	{
		return $this->token;
	}

	public function getRefreshToken(): string
	{
		return $this->refreshToken;
	}

	public function getDateStartAttempt(): ?string
	{
		return $this->dateStartAttempt;
	}

	public function isReady(): bool
	{
		return $this->host !== '';
	}

	public function isInstanceCreating(): bool
	{
		return $this->host === '' && $this->instanceKey !== '' && $this->instanceUsername !== '';
	}

	public function getPortalToken(): string
	{
		if ($this->instanceKey === '' || $this->serverId <= 0)
		{
			return '';
		}

		return (new Signer())->sign("{$this->instanceKey}_{$this->serverId}", 'supersetEndpoint');
	}

	public function toArray(): array
	{
		return [
			'serverId' => $this->serverId,
			'accountId' => $this->accountId,
			'portalId' => $this->portalId,
			'portalUrl' => $this->portalUrl,
			'host' => $this->host,
			'isReady' => $this->isReady(),
			'isInstanceCreating' => $this->isInstanceCreating(),
			'instanceKey' => $this->instanceKey,
			'instanceUsername' => $this->instanceUsername,
			'startJobId' => $this->startJobId,
			'version' => $this->version,
			'isPortalIdVerified' => $this->isPortalIdVerified,
			'portalToken' => $this->getPortalToken(),
			'accessPassword' => $this->accessPassword,
			'token' => $this->token,
			'refreshToken' => $this->refreshToken,
			'dateStartAttempt' => $this->dateStartAttempt,
		];
	}

	private static function normalizeVerifiedFlag(mixed $value): bool
	{
		if (is_bool($value))
		{
			return $value;
		}

		return in_array($value, ['Y', 'y', 1, '1'], true);
	}

	private static function normalizeDate(mixed $value): ?string
	{
		if ($value instanceof \Bitrix\Main\Type\DateTime)
		{
			return $value->format('Y-m-d H:i:s');
		}

		if (is_string($value) && $value !== '')
		{
			return $value;
		}

		return null;
	}
}
