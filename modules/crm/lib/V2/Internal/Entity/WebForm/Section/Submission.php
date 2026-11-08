<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\WebForm\Section;

/**
 * What a submitted form answers with: the outcome of a successful and of a failed submission, how
 * long the redirect of a successful one waits, and whether the form offers to be filled again.
 *
 * The delay is a whole number of seconds and belongs to the redirect alone: a form answering with
 * a text of its own never waits.
 *
 * @internal
 */
final readonly class Submission
{
	public const FIELD_SUCCESS = 'success';
	public const FIELD_FAILURE = 'failure';
	public const FIELD_REDIRECT_DELAY = 'redirectDelay';
	public const FIELD_REFILL = 'refill';

	public function __construct(
		public ?SubmissionResult $success = null,
		public ?SubmissionResult $failure = null,
		public ?int $redirectDelay = null,
		public ?SubmissionRefill $refill = null,
	)
	{
	}
}
