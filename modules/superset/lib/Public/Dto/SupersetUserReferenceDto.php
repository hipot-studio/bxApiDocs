<?php

namespace Bitrix\Superset\Public\Dto;

final class SupersetUserReferenceDto
{
	public function __construct(
		private readonly int $externalId = 0,
		private readonly string $login = '',
		private readonly string $clientId = '',
	)
	{
	}

	public static function fromArray(array $data): self
	{
		return new self(
			externalId: (int)($data['externalId'] ?? $data['external_id'] ?? $data['id'] ?? 0),
			login: (string)($data['login'] ?? $data['username'] ?? ''),
			clientId: (string)($data['clientId'] ?? $data['client_id'] ?? ''),
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

	public function toArray(): array
	{
		return [
			'externalId' => $this->externalId,
			'login' => $this->login,
			'clientId' => $this->clientId,
		];
	}
}
