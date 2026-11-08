<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Update;

use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\CompressEmailDescription;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\EmailBranch;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Stores the new text of a letter as its body and unbinds the one it had: row 14 of the owner table of an update
 * (crm_activity.php:681-694).
 *
 * THE COLUMN KEEPS A PREVIEW AND THE TEXT LIVES IN A ROW OF ITS OWN, so a letter whose text changed needs two
 * writes and this is the second of them. The full text is the one the caller gave, taken out of the column by the
 * compression of row 9 and carried here by the run - not the description of the activity, which is the preview by
 * now.
 *
 * THE GATE IS TWO QUESTIONS AND BOTH ARE NEEDED. The flag of the run says the text really differs from the stored
 * body, which is the whole reason to touch it: a letter saved again with the body it already has keeps that body
 * and the identifiers pointing at it. The provider columns are asked AGAIN, and not because row 9 already asked
 * them: between the two rows stands the event before a write, whose subscribers may change both columns
 * ({@see PublishOnBeforeUpdate}), and legacy re-reads them here for exactly that reason (:683-687).
 *
 * THE PREVIOUS BODY IS NOT ALWAYS DELETED, and who decides that is {@see EmailBranch::rebindMailBody()}: a body
 * shared with another activity survives, a body only this activity pointed at goes. Legacy decides it one row
 * earlier, while comparing the texts, and the answer is the same - nothing in between touches either the bodies or
 * their bindings.
 *
 * THE FALLBACK OF LEGACY IS NOT REPRODUCED, and its absence is deliberate. Where the full text is missing legacy
 * binds `$arFields['DESCRIPTION']` (:693), which by that line is the preview - it would store the preview as the
 * body of the letter. It is unreachable: the flag and the text are set together by one step
 * ({@see CompressEmailDescription}), so a run that raised the one and not the other is a broken declaration
 * rather than a state of the domain, and it is reported as the programming error it is.
 *
 * @internal
 */
final class RebindMailBody implements PipelineStepInterface
{
	/**
	 * The run reached this step with no activity, which the entry point of the service refuses to build. A body
	 * names the activity it belongs to, so there is nothing to bind it to.
	 */
	public const ERROR_NO_ID = 'CRM_ACTIVITY_HAS_NO_ID';

	public function getName(): string
	{
		return 'RebindMailBody';
	}

	/**
	 * Only a compressed letter whose text differs from the body stored for it.
	 */
	public function isApplicable(PipelineContext $context): bool
	{
		if (!$context->hasRunValue(PipelineContext::RUN_VALUE_MAIL_BODY_CHANGED))
		{
			return false;
		}

		return EmailBranch::isCompressedEmail($context->getActivity());
	}

	public function execute(PipelineContext $context): Result
	{
		$result = new Result();

		$activityId = $context->getActivityId();
		if ($activityId <= 0)
		{
			return $result->addError(new Error('The activity has no id.', self::ERROR_NO_ID));
		}

		EmailBranch::rebindMailBody(
			$activityId,
			(string)$context->getRunValue(PipelineContext::RUN_VALUE_UNCOMPRESSED_DESCRIPTION),
		);

		return $result;
	}

	/**
	 * Legacy checks nothing of what came of either write, and an update whose body was not rebound still performs
	 * every effect below it.
	 */
	public function isAbortingOnFailure(): bool
	{
		return false;
	}
}
