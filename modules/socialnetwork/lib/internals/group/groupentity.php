<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Internals\Group;

use Bitrix\Socialnetwork\EO_Workgroup;
use Bitrix\Socialnetwork\Item\Workgroup\Type;

class GroupEntity extends EO_Workgroup
{
	public function isProject(): bool
	{
		return $this->typeIs(Type::PROJECT);
	}

	public function isScrum(): bool
	{
		return $this->typeIs(Type::SCRUM);
	}

	public function isGroup(): bool
	{
		return $this->typeIs(Type::GROUP);
	}

	public function typeIs(Type $type): bool
	{
		return $this->getType() === $type->value;
	}
}