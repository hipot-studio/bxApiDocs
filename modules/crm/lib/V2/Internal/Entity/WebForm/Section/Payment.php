<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\WebForm\Section;

/**
 * Whether a submission of the form is paid for, and which pay systems of the portal are switched
 * off in it.
 *
 * The list is a black list: it holds what this form does not offer, and everything the portal has
 * beside it is offered. A pay system connected to the portal later is therefore offered by every
 * form at once, which is what the builder of the form means by it as well.
 *
 * The list is kept whether the payment is switched on or off: the submission of a form that takes
 * no payment never reads it, and refusing the pair would break the cycle of reading a form and
 * sending it back on every form already carrying it.
 *
 * @internal
 */
final readonly class Payment
{
	public const FIELD_ENABLED = 'enabled';
	public const FIELD_DISABLED_PAY_SYSTEMS_ID = 'disabledPaySystemsId';

	/**
	 * @param null|array<int> $disabledPaySystemsId
	 */
	public function __construct(
		public ?bool $enabled = null,
		public ?array $disabledPaySystemsId = null,
	)
	{
	}
}
