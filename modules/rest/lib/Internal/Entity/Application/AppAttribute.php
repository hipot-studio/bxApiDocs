<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Entity\Application;

use Bitrix\Main\Entity\EntityInterface;
use Bitrix\Main\Type\DateTime;

class AppAttribute implements EntityInterface
{
	public const TYPE_SYSTEM = 'S';
	public const TYPE_EXTERNAL = 'E';

	protected ?int $id = null;

	protected ?DateTime $dateCreate = null;

	public function __construct(
		protected ?int $appId,
		protected string $code,
		protected ?string $value = null,
		protected string $type = self::TYPE_SYSTEM,
	)
	{
	}

	public function getId(): ?int
	{
		return $this->id;
	}

	public function setId(int $id): self
	{
		$this->id = $id;

		return $this;
	}

	public function getAppId(): int
	{
		return $this->appId;
	}

	public function setAppId(int $appId): self
	{
		$this->appId = $appId;

		return $this;
	}

	public function getCode(): string
	{
		return $this->code;
	}

	public function setCode(string $code): self
	{
		$this->code = $code;

		return $this;
	}

	public function getValue(): ?string
	{
		return $this->value;
	}

	public function getIntValue(): ?int
	{
		return $this->value !== null ? (int)$this->value : null;
	}

	public function setValue(?string $value): self
	{
		$this->value = $value;

		return $this;
	}

	public function getType(): string
	{
		return $this->type;
	}

	public function setType(string $type): self
	{
		$this->type = $type;

		return $this;
	}

	public function isSystem(): bool
	{
		return $this->type === self::TYPE_SYSTEM;
	}

	public function isExternal(): bool
	{
		return $this->type === self::TYPE_EXTERNAL;
	}

	public function getDateCreate(): ?DateTime
	{
		return $this->dateCreate;
	}

	public function setDateCreate(DateTime $dateCreate): self
	{
		$this->dateCreate = $dateCreate;

		return $this;
	}
}
