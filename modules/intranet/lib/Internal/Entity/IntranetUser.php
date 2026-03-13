<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Entity;

use Bitrix\Main\Entity\EntityInterface;

class IntranetUser implements EntityInterface
{
	private ?int $id = null;
	private int $userId = 0;
	private bool $initialized = false;

	public function getId(): ?int
	{
		return $this->id;
	}

	public function setId(int $id): self
	{
		$this->id = $id;

		return $this;
	}

	public function getUserId(): int
	{
		return $this->userId;
	}

	public function setUserId(int $userId): self
	{
		$this->userId = $userId;

		return $this;
	}

	public function isInitialized(): bool
	{
		return $this->initialized;
	}

	public function setInitialized(bool $initialized): self
	{
		$this->initialized = $initialized;

		return $this;
	}

	public function toArray(): array
	{
		return [
			'ID' => $this->id,
			'USER_ID' => $this->userId,
			'INITIALIZED' => $this->initialized ? 'Y' : 'N',
		];
	}
}
