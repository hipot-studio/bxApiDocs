<?php

namespace Bitrix\UI\AccessRights\V2\Options\UserGroup;

use Bitrix\Main\Access\AccessCode;
use Bitrix\Main\Type\Contract\Arrayable;
use Bitrix\Main\UI\AccessRights\Entity\AccessRightEntityInterface;
use JsonSerializable;

class Member implements JsonSerializable, Arrayable
{
	public function __construct(
		protected int|string $id,
		protected ?string $name = null,
		protected ?string $type = null,
		protected ?string $avatarUrl = null,
	)
	{
	}

	public function getId(): int|string
	{
		return $this->id;
	}

	public function setId(string $id): static
	{
		$this->id = $id;

		return $this;
	}

	public function getName(): ?string
	{
		return $this->name;
	}

	public function setName(?string $name): static
	{
		$this->name = $name;

		return $this;
	}

	public function getType(): ?string
	{
		return $this->type;
	}

	/**
	 * @see AccessCode::TYPE_*
	 * @param string|null $type
	 * @return $this
	 */
	public function setType(?string $type): static
	{
		$this->type = $type;

		return $this;
	}

	public function getAvatarUrl(): ?string
	{
		return $this->avatarUrl;
	}

	public function setAvatarUrl(?string $avatarUrl): static
	{
		$this->avatarUrl = $avatarUrl;

		return $this;
	}

	public function toArray(): array
	{
		return [
			'id' => $this->getId(),
			'name' => $this->getName(),
			'type' => $this->getType(),
			'avatar' => $this->getAvatarUrl(),
		];
	}

	public function jsonSerialize(): array
	{
		return $this->toArray();
	}

	public static function fromAccessRightEntity(AccessRightEntityInterface $entity): static
	{
		return new static(
			$entity->getId(),
			$entity->getName(),
			$entity->getType(),
			$entity->getAvatar(22, 22),
		);
	}

	public static function tryFromArray(array $data): ?self
	{
		if (!isset($data['id']) || (!is_string($data['id']) && !is_int($data['id'])))
		{
			return null;
		}

		if (!isset($data['name']) || !is_string($data['name']))
		{
			return null;
		}

		if (!isset($data['type']) || !is_string($data['type']))
		{
			return null;
		}

		if (isset($data['avatar']) && !is_string($data['avatar']))
		{
			return null;
		}

		return new self(
			$data['id'],
			$data['name'],
			$data['type'],
			$data['avatar'] ?? '',
		);
	}
}
