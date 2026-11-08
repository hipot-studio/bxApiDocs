<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Collab\Control;

use Bitrix\Socialnetwork\Provider\CollabUserPolicyProvider;

final class ExternalMembershipValidationContext
{
	private bool $isValid = true;

	private function __construct(
		private readonly int $groupId,
		private readonly string $role,
		private array $internalUserIds,
	)
	{
	}

	public static function create(int $groupId, array $userIds, string $role): self
	{
		$classification = CollabUserPolicyProvider::getInstance()->classify($userIds);

		return new self($groupId, $role, array_fill_keys($classification['internal'], true));
	}

	public function invalidate(): void
	{
		$this->isValid = false;
	}

	public function consume(int $groupId, int $userId, string $role): bool
	{
		if ($this->groupId !== $groupId || $this->role !== $role || !isset($this->internalUserIds[$userId]))
		{
			return false;
		}

		unset($this->internalUserIds[$userId]);
		if ($this->isValid)
		{
			return true;
		}

		$classification = CollabUserPolicyProvider::getInstance()->classify([$userId]);

		return in_array($userId, $classification['internal'], true);
	}
}
