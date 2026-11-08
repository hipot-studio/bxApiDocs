<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Form;

use Bitrix\Rest\V3\Dto\Dto;

/**
 * The offer to fill a submitted form again, and the caption of that offer.
 *
 * Both properties are written on their own: sending the caption alone leaves the offer switched the
 * way it was, and the other way round.
 *
 * The caption may be empty, and a non-empty one is not promised: it is one stored string of the
 * form, and the form a builder has never saved carries none at all.
 */
final class SubmissionRefillDto extends Dto
{
	public bool $enabled;

	public ?string $caption;
}
