<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Entity\Activity\SetupTemplateActivity;

/**
 * A structural error found while parsing a setup wizard form.
 *
 * Carries the error code and the 1-based position it was found at, never the message: parse messages
 * are localized and written for the wizard owner in the settings panel, so they are not part of this
 * surface. Positions are null when the error is about the form as a whole (an unreadable or empty
 * blocks payload) or about a block rather than one of its items.
 */
final readonly class SetupParseError
{
	public function __construct(
		public string $code,
		public ?int $blockPosition = null,
		public ?int $itemPosition = null,
	) {}
}
