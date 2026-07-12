<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Command\Project\Result;

use Bitrix\Main\Result;

class SyncProjectChatMembersResult extends Result
{
	public function getLastAddUserId(): int
	{
		return (int)($this->data['lastAddUserId'] ?? 0);
	}

	public function setLastAddUserId(int $lastAddUserId): self
	{
		$this->data['lastAddUserId'] = $lastAddUserId;

		return $this;
	}

	public function getLastDeleteUserId(): int
	{
		return (int)($this->data['lastDeleteUserId'] ?? 0);
	}

	public function setLastDeleteUserId(int $lastDeleteUserId): self
	{
		$this->data['lastDeleteUserId'] = $lastDeleteUserId;

		return $this;
	}

	public function hasMore(): bool
	{
		return (bool)($this->data['hasMore'] ?? false);
	}

	public function setHasMore(bool $hasMore): self
	{
		$this->data['hasMore'] = $hasMore;

		return $this;
	}
}
