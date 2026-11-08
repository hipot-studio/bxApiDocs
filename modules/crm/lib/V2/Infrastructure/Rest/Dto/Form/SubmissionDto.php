<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Form;

use Bitrix\Rest\V3\Dto\Dto;

/**
 * What a submitted form answers with.
 *
 * {@see self::$redirectDelay} is a whole number of seconds from 0 to 10 - the range both editors of
 * the form offer - and it applies to the redirect of a successful submission alone. A form with no
 * delay of its own answers the one the editors apply themselves.
 *
 * A form carrying both {@see self::$refill} and an address in {@see self::$success} is accepted:
 * the public runtime prefers the address, and this contract keeps that order rather than refusing
 * one of the two.
 *
 * Neither new property is filterable or sortable: they are stored inside one serialized column,
 * which the database can neither narrow nor order a page by.
 */
final class SubmissionDto extends Dto
{
	public SubmissionResultDto $success;

	public SubmissionResultDto $failure;

	public int $redirectDelay;

	public SubmissionRefillDto $refill;
}
