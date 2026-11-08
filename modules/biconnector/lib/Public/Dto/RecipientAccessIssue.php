<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Public\Dto;

use Bitrix\BIConnector\Internal\Digest\Reason\DigestReason;

/**
 * Why a recipient will not be sent a digest of a report, in the shape a setup
 * form shows it: a stable code to branch on and a ready text to print. The
 * module's own reason enum stays internal.
 */
final class RecipientAccessIssue
{
	/**
	 * @param string $code Stable reason code, e.g. 'no_dashboard_right'.
	 * @param string $text Text addressed to whoever sets the digest up.
	 */
	public function __construct(
		public readonly string $code,
		public readonly string $text,
	)
	{
	}

	/**
	 * Some reasons hold for the setup as a whole rather than for the person they
	 * came back for, so they are shown once and without a name.
	 */
	public function isAboutSetup(): bool
	{
		return DigestReason::tryFrom($this->code)?->isAboutSetupAsWhole() ?? false;
	}
}
