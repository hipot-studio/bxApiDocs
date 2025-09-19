<?php

namespace Bitrix\Intranet\Internal\Entity\UserProfile;

use Bitrix\Intranet\Internal\Entity\UserField\UserFieldCollection;
use Bitrix\Main\Entity\EntityInterface;

class UserFieldSection implements EntityInterface
{
	public function __construct(
		public readonly string $id,
		public readonly string $title,
		public readonly bool $isEditable,
		public readonly bool $isRemovable,
		public readonly UserFieldCollection $userFieldCollection,
		public readonly bool $isDefault,
	)
	{
	}

	public function getId(): string
	{
		return $this->id;
	}
}
