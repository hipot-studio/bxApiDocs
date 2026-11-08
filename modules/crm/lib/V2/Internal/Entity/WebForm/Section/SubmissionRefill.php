<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\WebForm\Section;

/**
 * Offering the visitor of a submitted form to fill it again, and the caption of that offer.
 *
 * The two values are independent of each other: they live in one sub key of the settings of the
 * form, and sending one of them leaves the other as it is.
 *
 * The caption is a single stored string with no language of its own - the builder writes it in the
 * language of whoever saved the form, and a form nobody has saved carries none at all - so an empty
 * one is a state of the form rather than a value missing from it.
 *
 * A form carrying both this offer and an address to redirect to is a form the builder does not
 * produce, and the public runtime prefers the address. The contract keeps that order instead of
 * refusing one of the two.
 *
 * @internal
 */
final readonly class SubmissionRefill
{
	public const FIELD_ENABLED = 'enabled';
	public const FIELD_CAPTION = 'caption';

	public function __construct(
		public ?bool $enabled = null,
		public ?string $caption = null,
	)
	{
	}
}
