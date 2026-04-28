<?php

namespace Bitrix\Ldap;

use Bitrix\Ldap\Internal\Entry;

final class QueryResult implements \Countable
{
	public function __construct(
		private array $entries = [],
		private readonly string $cookie = ''
	)
	{
	}

	public function getEntries(): array
	{
		return $this->entries;
	}

	public function getCookie(): string
	{
		return $this->cookie;
	}

	public function addEntry(Entry $entry): self
	{
		$this->entries[] = $entry;

		return $this;
	}

	public function count(): int
	{
		return count($this->entries);
	}
}
