<?php

namespace Bitrix\Superset\Internal\Entities;

final class OwnedEntityIds
{
	private const SUPERSET_USER_ID = 1;

	public function __construct(private readonly array $ownerIds)
	{
	}

	public static function fromOwnersPayload(array $owners): self
	{
		$ownerIds = [];
		foreach ($owners as $owner)
		{
			if (is_array($owner) && isset($owner['id']))
			{
				$ownerIds[] = (int)$owner['id'];
			}
		}

		return new self($ownerIds);
	}

	public function toArray(): array
	{
		return $this->ownerIds;
	}

	public function contains(int $ownerId): bool
	{
		return in_array($ownerId, $this->ownerIds, true);
	}

	public function withAdded(int $ownerId): self
	{
		$ownerIds = $this->ownerIds;
		$ownerIds[] = $ownerId;
		$ownerIds = array_values(array_unique($ownerIds));
		sort($ownerIds);

		return new self($ownerIds);
	}

	public function withReplaced(int $fromUserId, int $toUserId): self
	{
		$ownerIds = $this->ownerIds;
		$key = array_search($fromUserId, $ownerIds, true);
		if ($key !== false)
		{
			unset($ownerIds[$key]);
		}

		$ownerIds[] = $toUserId;
		$ownerIds = array_values(array_unique($ownerIds));
		sort($ownerIds);

		return new self($ownerIds);
	}

	public function isSystemOwned(): bool
	{
		return count($this->ownerIds) === 1 && current($this->ownerIds) === self::SUPERSET_USER_ID;
	}
}
