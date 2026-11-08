<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Digest\Dto;

use Bitrix\BIConnector\Internal\Digest\Reason\DigestReason;
use Bitrix\BIConnector\Internal\Digest\Reason\DigestReasonClass;

/**
 * Verdict on the run as a whole, in the three shapes it takes: the run goes on,
 * the run is refused with a reason, or the run yields to another one.
 *
 * Overlap carries no reason on purpose: neither a recipient nor the person who
 * set the digest up is told about it, so the class is the only thing that
 * tells it apart from a refusal.
 */
final class RunReadinessDto
{
	private function __construct(
		public readonly bool $isReady,
		public readonly ?DigestReason $reason,
		public readonly ?DigestReasonClass $reasonClass,
	)
	{
	}

	public static function ready(): self
	{
		return new self(true, null, null);
	}

	public static function refused(DigestReason $reason): self
	{
		return new self(false, $reason, $reason->reasonClass());
	}

	public static function overlapped(): self
	{
		return new self(false, null, DigestReasonClass::Overlap);
	}

	public function isOverlapping(): bool
	{
		return $this->reasonClass === DigestReasonClass::Overlap;
	}
}
