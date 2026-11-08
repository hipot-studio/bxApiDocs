<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Delete;

use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\EventPublisher;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\ThrowableTransparentStepInterface;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Asks the subscribers of the module whether the activity may be deleted at all: row 1 of the owner table
 * STEP-DELETE (crm_activity.php:1258-1265).
 *
 * IT IS THE ONE EVENT OF THE DOMAIN WITH A VETO, AND THE VETO IS STRICT (item E1). A subscriber answering exactly
 * false stops the deletion; anything else - null, zero, an empty string, the null a handler of an unavailable module
 * returns - lets it carry on, and the subscribers below the one that refused are not asked at all. Both halves are
 * the contract of the publication point ({@see EventPublisher::publishOnBeforeDelete()}) rather than a rule of this
 * step.
 *
 * IT RUNS BEFORE THE ROW IS READ, AND THAT ORDER IS THE POINT OF THE ROW. Legacy publishes at the very top of its
 * delete, above the load of the activity and above the one right check it has, so a subscriber may refuse the
 * deletion of an activity that is not there and of one the acting user may not even read. Moving this row below the
 * load would silently take that away: the veto would stop happening exactly where the row is missing, which is the
 * case the subscribers of a mass cleanup are written for.
 *
 * THE ACTIVITY IT NAMES IS AN IDENTIFIER AND NOTHING ELSE. The event carries no fields - the run has none yet - so
 * a subscriber that wants them reads them itself, exactly as it does today.
 *
 * @internal
 */
final class PublishOnBeforeDelete implements ThrowableTransparentStepInterface
{
	/**
	 * A subscriber refused the deletion. The message is the one legacy leaves in no error at all: it answers a veto
	 * with a plain false, so the text is ours and the code is what a caller reads.
	 */
	public const ERROR_VETOED = 'CRM_ACTIVITY_DELETE_VETOED';

	private readonly EventPublisher $publisher;

	/**
	 * @param EventPublisher|null $publisher the single publication point of the events of the domain; the one seam
	 *        of the step.
	 */
	public function __construct(?EventPublisher $publisher = null)
	{
		$this->publisher = $publisher ?? new EventPublisher();
	}

	public function getName(): string
	{
		return 'PublishOnBeforeDelete';
	}

	/**
	 * Every delete is published. Legacy has no path around this, and no option silences it.
	 */
	public function isApplicable(PipelineContext $context): bool
	{
		return true;
	}

	public function execute(PipelineContext $context): Result
	{
		$result = new Result();

		if (!$this->publisher->publishOnBeforeDelete($context->getActivityId()))
		{
			return $result->addError(new Error(
				'The deletion of the activity was refused by a subscriber.',
				self::ERROR_VETOED,
			));
		}

		return $result;
	}

	/**
	 * A veto ends the operation and leaves the activity untouched, which is what legacy does with its false.
	 */
	public function isAbortingOnFailure(): bool
	{
		return true;
	}
}
