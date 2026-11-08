<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\WebForm\Section;

/**
 * @internal
 */
final readonly class AgreementItem
{
	public const FIELD_AGREEMENT_ID = 'agreementId';
	public const FIELD_CHECKED = 'checked';
	public const FIELD_REQUIRED = 'required';

	public function __construct(
		public ?int $agreementId = null,
		public ?bool $checked = null,
		public ?bool $required = null,
	)
	{
	}
}
