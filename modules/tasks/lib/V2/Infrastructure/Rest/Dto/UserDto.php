<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Rest\Dto;

use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Tasks\V2\Internal\Entity\User;

class UserDto extends Dto
{
	#[Filterable, Sortable]
	public ?int $id;
	public ?string $name;
	public ?string $role;
	public ?FileDto $image;
	public ?string $gender;
	public ?string $email;
	public ?string $externalAuthId;
	public ?array $rights;

	public static function fromEntity(?User $user, ?Request $request = null): ?self
	{
		if (!$user)
		{
			return null;
		}
		$select = $request?->select?->getList(true) ?? [];
		$dto = new self();
		if (empty($select) || in_array('id', $select, true))
		{
			$dto->id = $user->id;
		}
		if (empty($select) || in_array('name', $select, true))
		{
			$dto->name = $user->name;
		}
		if (empty($select) || in_array('role', $select, true))
		{
			$dto->role = $user->role;
		}
		if (empty($select) || in_array('image', $select, true))
		{
			$dto->image = $user->image ? FileDto::fromEntity($user->image, $request) : null;
		}
		if (empty($select) || in_array('gender', $select, true))
		{
			$dto->gender = $user->gender?->value;
		}
		if (empty($select) || in_array('email', $select, true))
		{
			$dto->email = $user->email;
		}
		if (empty($select) || in_array('externalAuthId', $select, true))
		{
			$dto->externalAuthId = $user->externalAuthId;
		}
		if (empty($select) || in_array('rights', $select, true))
		{
			$dto->rights = $user->rights ?? [];
		}

		return $dto;
	}
}
