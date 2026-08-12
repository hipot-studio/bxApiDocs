<?php

namespace Bitrix\Superset\Public\Dto;

final class SupersetUserRuntimeDto
{
	public function __construct(
		private readonly int $externalId = 0,
		private readonly string $login = '',
		private readonly string $clientId = '',
		private readonly string $accessPassword = '',
	)
	{
	}

	public static function fromArray(array $data): self
	{
		return new self(
			externalId: (int)($data['externalId'] ?? $data['external_id'] ?? $data['id'] ?? 0),
			login: (string)($data['login'] ?? ''),
			clientId: (string)($data['clientId'] ?? $data['client_id'] ?? ''),
			accessPassword: (string)($data['accessPassword'] ?? $data['access_password'] ?? $data['password'] ?? ''),
		);
	}

	public function getExternalId(): int
	{
		return $this->externalId;
	}

	public function getLogin(): string
	{
		return $this->login;
	}

	public function getClientId(): string
	{
		return $this->clientId;
	}

	public function getAccessPassword(): string
	{
		return $this->accessPassword;
	}

	public function toArray(): array
	{
		return [
			'externalId' => $this->externalId,
			'login' => $this->login,
			'clientId' => $this->clientId,
			'accessPassword' => $this->accessPassword,
		];
	}
}
