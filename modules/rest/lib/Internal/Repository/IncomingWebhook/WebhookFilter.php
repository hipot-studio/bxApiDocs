<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Repository\IncomingWebhook;

use Bitrix\Rest\Enum\APAuth\PasswordType;

final class WebhookFilter
{
	private ?int $userId = null;
	/** @var string[]|null */
	private ?array $scopes = null;
	/** @var array<string, string|null>|null */
	private ?array $attributes = null;
	private ?PasswordType $type = null;

	public function userId(?int $userId): self
	{
		$this->userId = $userId;

		return $this;
	}

	public function type(?PasswordType $type): self
	{
		$this->type = $type;

		return $this;
	}

	/**
	 * @param string[]|null $scopes keep webhooks granting at least one of these scopes
	 */
	public function scopes(?array $scopes): self
	{
		$this->scopes = $scopes;

		return $this;
	}

	/**
	 * @param array<string, string|null>|null $attributes keep webhooks carrying every one of these external
	 *        attributes; map of code => required value (a null value matches any value)
	 */
	public function externalAttributes(?array $attributes): self
	{
		$this->attributes = ($attributes === null || $attributes === []) ? null : $attributes;

		return $this;
	}

	public function getUserId(): ?int
	{
		return $this->userId;
	}

	public function getType(): ?PasswordType
	{
		return $this->type;
	}

	/**
	 * @return string[]|null
	 */
	public function getScopes(): ?array
	{
		return $this->scopes;
	}

	/**
	 * @return array<string, string|null>|null
	 */
	public function getExternalAttributes(): ?array
	{
		return $this->attributes;
	}
}
