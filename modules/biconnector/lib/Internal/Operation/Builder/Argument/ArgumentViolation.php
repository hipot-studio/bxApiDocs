<?php

namespace Bitrix\BIConnector\Internal\Operation\Builder\Argument;

final class ArgumentViolation
{
	/** @param list<string|int> $path */
	public function __construct(
		public readonly array $path,
		public readonly string $facet,
		public readonly array $expected,
	)
	{
	}

	public function isNested(): bool
	{
		return count($this->path) > 1;
	}
}
