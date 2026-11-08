<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Digest;

use Bitrix\Main\DI\ServiceLocator;

/**
 * One wait on Superset spread over several reads.
 *
 * A read timeout of its own for each read multiplies the wait by the number of reads. Each read gets what is
 * left instead, and less than a second left is no time to start a read at all: a timeout of zero would mean
 * the default one of the transport.
 */
final class DigestDeadline
{
	private function __construct(
		private readonly DigestClock $clock,
		private readonly float $deadline,
	)
	{
	}

	public static function in(int $seconds): self
	{
		$clock = ServiceLocator::getInstance()->get(DigestClock::class);

		return new self($clock, $clock->now() + $seconds);
	}

	public function getStreamTimeout(): int
	{
		return max(1, (int)floor($this->deadline - $this->clock->now()));
	}

	public function isExhausted(): bool
	{
		return $this->deadline - $this->clock->now() < 1;
	}
}
