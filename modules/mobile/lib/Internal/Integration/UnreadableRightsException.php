<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Internal\Integration;

/**
 * The rights of a pack that could not be read for some of its users. The rest of the pack was read and
 * comes along, so a failure of one user leaves the others answered.
 *
 * The message and the previous throwable are those of the first failure.
 */
final class UnreadableRightsException extends \RuntimeException
{
	/**
	 * @param array<int, bool> $rights userId => the right, for the users that were read
	 * @param non-empty-array<int, \Throwable> $failures userId => what the read of his right met
	 */
	public function __construct(private readonly array $rights, private readonly array $failures)
	{
		$first = reset($failures);

		parent::__construct($first->getMessage(), 0, $first);
	}

	/**
	 * @return array<int, bool>
	 */
	public function getRights(): array
	{
		return $this->rights;
	}

	/**
	 * @return array<int, \Throwable>
	 */
	public function getFailures(): array
	{
		return $this->failures;
	}
}
