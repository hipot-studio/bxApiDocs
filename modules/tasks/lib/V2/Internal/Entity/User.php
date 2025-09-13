<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity;

use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Tasks\V2\Internal\Entity\User\Gender;

class User extends AbstractEntity
{
	public function __construct(
		#[PositiveNumber]
		public readonly ?int $id = null,
		public readonly ?string $name = null,
		public readonly ?string $role = null, // role depends on context
		public readonly ?string $image = null,
		public readonly ?Gender $gender = null,
		public readonly ?string $email = null,
		public readonly ?string $externalAuthId = null,
	)
	{

	}

	public function getId(): ?int
	{
		return $this->id;
	}

	public static function mapFromArray(array $props): static
	{
		return new static(
			id: $props['id'] ?? null,
			name: $props['name'] ?? null,
			role: $props['role'] ?? null,
			image: $props['image'] ?? null,
			gender: Gender::tryFrom($props['gender'] ?? 'M'),
			email: $props['email'] ?? null,
			externalAuthId: $props['externalAuthId'] ?? null,
		);
	}

	public function getGender(): Gender
	{
		return $this->gender ?? Gender::Male;
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'name' => $this->name,
			'role' => $this->role,
			'image' => $this->image,
			'gender' => $this->gender?->value,
			'email' => $this->email,
			'externalAuthId' => $this->externalAuthId,
		];
	}
}
