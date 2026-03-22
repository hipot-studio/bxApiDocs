<?php

namespace Bitrix\Mobile;

class CacheId
{
	private array $segments;

	public function __construct(string ...$ids)
	{
		$this->segments = $ids;
	}

	public function add(string ...$ids): CacheId
	{
		foreach ($ids as $id)
		{
			$this->segments[] = $id;
		}

		return $this;
	}

	public function get(): string
	{
		return implode('|', $this->segments);
	}
}