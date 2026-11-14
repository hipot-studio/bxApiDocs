<?php

namespace Bitrix\Disk\Volume;

/**
 * Raw SQL subquery built by module code; never construct from user input.
 */
final class SqlSubQuery
{
	public function __construct(
		private readonly string $sql,
	)
	{
	}

	public function getSql(): string
	{
		return $this->sql;
	}
}
