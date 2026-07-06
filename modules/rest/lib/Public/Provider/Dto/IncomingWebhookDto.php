<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Provider\Dto;

use Bitrix\Main\Type\DateTime;

class IncomingWebhookDto
{
	/**
	 * @param string[] $scopes
	 */
	public function __construct(
		private readonly int $id,
		private readonly int $userId,
		private readonly string $webhookUrl,
		private readonly bool $active = true,
		private readonly ?string $title = null,
		private readonly array $scopes = [],
		private readonly ?DateTime $dateCreate = null,
		private readonly ?DateTime $dateLogin = null,
		private readonly ?string $lastIp = null,
	)
	{
	}

	public function getId(): int
	{
		return $this->id;
	}

	public function getUserId(): int
	{
		return $this->userId;
	}

	public function getWebhookUrl(): string
	{
		return $this->webhookUrl;
	}

	public function isActive(): bool
	{
		return $this->active;
	}

	public function getTitle(): ?string
	{
		return $this->title;
	}

	/**
	 * @return string[]
	 */
	public function getScopes(): array
	{
		return $this->scopes;
	}

	public function getDateCreate(): ?DateTime
	{
		return $this->dateCreate;
	}

	public function getDateLogin(): ?DateTime
	{
		return $this->dateLogin;
	}

	public function getLastIp(): ?string
	{
		return $this->lastIp;
	}
}
