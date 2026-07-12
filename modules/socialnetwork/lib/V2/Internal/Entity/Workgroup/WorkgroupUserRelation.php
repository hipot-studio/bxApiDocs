<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Entity\Workgroup;

use Bitrix\Socialnetwork\UserToGroupTable;
use Bitrix\Socialnetwork\V2\Internal\Entity\User\Role;

readonly class WorkgroupUserRelation
{
	public function __construct(
		public int $groupId,
		public int $userId,
		public Role $role,
		public ?string $initiatedByType = null,
		public ?int $initiatedByUserId = null,
		public bool $autoMember = false,
	)
	{
	}

	public function isMember(): bool
	{
		return in_array($this->role, [Role::Owner, Role::Moderator, Role::Member], true);
	}

	public function isIncomingInvite(): bool
	{
		return
			$this->role === Role::Request
			&& $this->initiatedByType === UserToGroupTable::INITIATED_BY_GROUP
		;
	}

	public function isOutgoingRequest(): bool
	{
		return
			$this->role === Role::Request
			&& $this->initiatedByType === UserToGroupTable::INITIATED_BY_USER
		;
	}

	public function isBan(): bool
	{
		return $this->role === Role::Ban;
	}
}
