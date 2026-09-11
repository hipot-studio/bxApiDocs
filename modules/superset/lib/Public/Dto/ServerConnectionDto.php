<?php

namespace Bitrix\Superset\Public\Dto;

final class ServerConnectionDto
{
	public function __construct(
		private readonly string $host,
		private readonly string $accessPassword,
		private readonly ?string $token = null,
		private readonly ?string $refreshToken = null,
		private readonly bool $sslVerificationEnabled = true,
	)
	{
	}

	public static function fromArray(array $data): self
	{
		return new self(
			host: (string)($data['host'] ?? ''),
			accessPassword: (string)($data['accessPassword'] ?? $data['access_password'] ?? ''),
			token: self::normalizeOptionalString($data['token'] ?? null),
			refreshToken: self::normalizeOptionalString($data['refreshToken'] ?? $data['refresh_token'] ?? null),
			sslVerificationEnabled: self::normalizeSslVerificationFlag($data['sslVerificationEnabled'] ?? null),
		);
	}

	public function getHost(): string
	{
		return $this->host;
	}

	public function getAccessPassword(): string
	{
		return $this->accessPassword;
	}

	public function getToken(): ?string
	{
		return $this->token;
	}

	public function getRefreshToken(): ?string
	{
		return $this->refreshToken;
	}

	public function isSslVerificationEnabled(): bool
	{
		return $this->sslVerificationEnabled;
	}

	private static function normalizeSslVerificationFlag(mixed $value): bool
	{
		if ($value === null || $value === '')
		{
			return true;
		}

		return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
	}

	private static function normalizeOptionalString(mixed $value): ?string
	{
		if (is_string($value) && $value !== '')
		{
			return $value;
		}

		return null;
	}
}
