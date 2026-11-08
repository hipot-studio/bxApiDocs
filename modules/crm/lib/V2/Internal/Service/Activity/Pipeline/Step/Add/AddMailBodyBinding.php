<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Add;

use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\EmailBranch;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\RestorationMode;
use Bitrix\Main\Result;

/**
 * Stores the full text of a letter and binds it to the activity just written: step 11 of the owner table of an
 * add (crm_activity.php:196-208), checklist item C5.
 *
 * THE STEP EXISTS BECAUSE THE COLUMN KEEPS A PREVIEW. The compression above the write replaced the description
 * with a preview of it and handed the full text to the run
 * ({@see PipelineContext::RUN_VALUE_UNCOMPRESSED_DESCRIPTION}); this step is the reader of that value, and the
 * only reason it stands below the insert is that a row of a body names the activity it belongs to.
 *
 * A RESTORATION BINDS NOTHING. An activity recreated out of the recycle bin still owns the body it had, so the
 * mode of the operation silences this step - one of the three effects it silences and the only one of them in
 * this cluster ({@see RestorationMode::SUPPRESSED_EFFECTS}).
 *
 * THE FALLBACK OF LEGACY IS NOT REPRODUCED, AND THE STEP IS SHAPED SO THAT IT NEED NOT BE. Legacy binds
 * `$uncompressedDescription ?? $arFields['DESCRIPTION'] ?? ''` (:207), so a run whose gate is true while the
 * compression never happened would bind the escaped preview as the body of the letter - silently storing
 * something nobody wrote. Here the presence of the full text is part of the gate instead: a compressed letter
 * whose compression did not run binds nothing at all, which is the honest half of that quirk. The gate is
 * reachable only through the compression anyway - being a compressed letter implies being a letter, which is
 * what the compression is gated by - so the case means one thing only: the step above failed and was logged.
 *
 * @internal
 */
final class AddMailBodyBinding implements PipelineStepInterface
{
	public function getName(): string
	{
		return 'AddMailBodyBinding';
	}

	/**
	 * A compressed letter of an operation that is not a restoration, whose row has been written and whose full
	 * text the run carries. From the cheapest question to the most expensive one.
	 */
	public function isApplicable(PipelineContext $context): bool
	{
		if (RestorationMode::isActive($context))
		{
			return false;
		}

		if ($context->getActivityId() <= 0)
		{
			return false;
		}

		if (!$context->hasRunValue(PipelineContext::RUN_VALUE_UNCOMPRESSED_DESCRIPTION))
		{
			return false;
		}

		return EmailBranch::isCompressedEmail($context->getActivity());
	}

	public function execute(PipelineContext $context): Result
	{
		EmailBranch::bindMailBody(
			$context->getActivityId(),
			(string)$context->getRunValue(PipelineContext::RUN_VALUE_UNCOMPRESSED_DESCRIPTION),
		);

		return new Result();
	}

	/**
	 * A letter whose body could not be bound is stored all the same: legacy interrupts nothing here.
	 */
	public function isAbortingOnFailure(): bool
	{
		return false;
	}
}
