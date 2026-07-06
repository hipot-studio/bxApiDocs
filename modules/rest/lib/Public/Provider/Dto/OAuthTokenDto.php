<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Provider\Dto;

use Bitrix\Rest\Public\Contract\Application\OAuthToken;

class OAuthTokenDto implements OAuthToken
{
	public function __construct(
		private readonly string $accessToken,
		private readonly int $userId,
		private readonly string $clientId,
		private readonly ?string $refreshToken = null,
		private readonly ?int $expiresIn = null,
		private readonly ?int $dateFinish = null,
		private readonly ?string $domain = null,
		private readonly ?string $memberId = null,
		private readonly ?string $serverEndpoint = null,
		private readonly ?string $clientEndpoint = null,
		private readonly ?string $status = null,
		private readonly ?string $scope = null,
	)
	{
	}

	public static function fromArray(array $data): self
	{
		return new self(
			accessToken: $data['access_token'],
			userId: (int)$data['user_id'],
			clientId: $data['client_id'],
			refreshToken: $data['refresh_token'] ?? null,
			expiresIn: isset($data['expires_in']) ? (int)$data['expires_in'] : null,
			dateFinish: isset($data['date_finish']) ? (int)$data['date_finish'] : null,
			domain: $data['domain'] ?? null,
			memberId: $data['member_id'] ?? null,
			serverEndpoint: $data['server_endpoint'] ?? null,
			clientEndpoint: $data['client_endpoint'] ?? null,
			status: $data['status'] ?? null,
			scope: $data['scope'] ?? null,
		);
	}

	public function getAccessToken(): string
	{
		return $this->accessToken;
	}

	public function getRefreshToken(): ?string
	{
		return $this->refreshToken;
	}

	public function getUserId(): int
	{
		return $this->userId;
	}

	public function getClientId(): string
	{
		return $this->clientId;
	}

	public function getExpiresIn(): ?int
	{
		return $this->expiresIn;
	}

	public function getDateFinish(): ?int
	{
		return $this->dateFinish;
	}

	public function getDomain(): ?string
	{
		return $this->domain;
	}

	public function getMemberId(): ?string
	{
		return $this->memberId;
	}

	public function getServerEndpoint(): ?string
	{
		return $this->serverEndpoint;
	}

	public function getClientEndpoint(): ?string
	{
		return $this->clientEndpoint;
	}

	public function getStatus(): ?string
	{
		return $this->status;
	}

	public function getScope(): ?string
	{
		return $this->scope;
	}
}
