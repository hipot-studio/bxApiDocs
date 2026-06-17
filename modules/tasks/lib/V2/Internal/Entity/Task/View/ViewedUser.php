<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity\Task\View;

use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Tasks\V2\Internal\Entity\File;
use Bitrix\Tasks\V2\Internal\Entity\User;
use Bitrix\Tasks\V2\Internal\Entity\User\Gender;
use Bitrix\Tasks\V2\Internal\Entity\User\Type;

class ViewedUser extends User
{
	public function __construct(
		#[PositiveNumber]
		?int $id = null,
		?string $name = null,
		?Type $type = null,
		?File $image = null,
		?Gender $gender = null,
		public readonly ?int $viewedTs = null,
	) {
		parent::__construct(
			id: $id,
			name: $name,
			type: $type,
			image: $image,
			gender: $gender
		);
	}

	public static function mapFromArray(array $props): static
	{
		$data = parent::mapFromArray($props);
		$data['viewedTs'] = static::mapInteger($props, 'viewedTs');

		return $data;
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'name' => $this->name,
			'type' => $this->type?->value,
			'image' => $this->image?->toArray(),
			'gender' => $this->gender?->value,
			'viewedTs' => $this->viewedTs,
		];
	}
}
