<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Digest\Dto;

use Bitrix\BIConnector\Internal\Digest\Reason\DigestReason;

/**
 * Verdict on a single recipient. The named constructors keep the two states
 * apart: a refusal always carries a reason and never a pinned context, a ready
 * verdict always carries a context and never a reason.
 */
final class ReadinessDto
{
	private function __construct(
		public readonly bool $isReady,
		public readonly ?DigestReason $reason,
		public readonly ?PinnedContextDto $pinnedContext,
	)
	{
	}

	public static function ready(PinnedContextDto $pinnedContext): self
	{
		return new self(true, null, $pinnedContext);
	}

	public static function refused(DigestReason $reason): self
	{
		return new self(false, $reason, null);
	}
}
