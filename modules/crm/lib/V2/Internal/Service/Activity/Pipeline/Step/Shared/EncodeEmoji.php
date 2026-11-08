<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared;

use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Main\Result;
use Bitrix\Main\Text\Emoji;

/**
 * Escapes the emoji of the two texts of the activity and keeps what they were: step 8 of the owner table of an
 * add (crm_activity.php:158-169), step 10 of the owner table of an update (:640-647), checklist item C6.
 *
 * THE ESCAPED SHAPE IS THE SHAPE OF A COLUMN, and the two pipelines differ in how long it lives. An add escapes
 * the texts right before the write and puts them back right after it (:211-218), so everything below the insert
 * - the search index, the timeline, the record of the activity stream, the event of a calendar - reads the text
 * as it was given; an update escapes them and never puts them back, so its own subscribers and its provider see
 * the escaped text (LBS-1 section 15 item 20, row D14 of the registry of intentional deviations, reproduced
 * bug for bug). That is why this step is one class with no mode of its own: it does the same thing in both
 * pipelines, and the difference is that only the add declares a step reading what the texts were
 * ({@see PipelineContext::RUN_VALUE_UNENCODED_SUBJECT}, {@see PipelineContext::RUN_VALUE_UNENCODED_DESCRIPTION};
 * the reader is `InsertActivity`).
 *
 * WHAT THE TEXTS ARE KEPT FOR. On an add they are what the caller gets back (LBS-1 section 11) and what the
 * thirty steps below the insert work on; for a letter the kept description is already the preview, this step
 * standing below the compression, which is exactly what legacy keeps.
 *
 * THE COLUMN WOULD BE ESCAPED ANYWAY, AND THE STEP IS STILL THE PLACE IT HAPPENS. The storage boundary of V2
 * writes through the entity, whose two text columns declare the escaping as their save modification, so the row
 * ends up escaped whether this step ran or not - and the escaping is idempotent, so doing it twice changes
 * nothing (proven by a probe). Legacy has no such boundary: it writes its array as raw SQL, so it escapes the
 * texts itself, and the pair "escape - write - put back" is what its subscribers, its providers and its callers
 * observe. Keeping the pair explicit is what keeps that observable difference between an add and an update where
 * legacy has it, instead of hiding it inside a field map neither pipeline mentions.
 *
 * @internal
 */
final class EncodeEmoji implements PipelineStepInterface
{
	public function getName(): string
	{
		return 'EncodeEmoji';
	}

	/**
	 * Legacy asks for each of the two texts on its own (`isset`), and an activity carrying neither has nothing
	 * to escape.
	 */
	public function isApplicable(PipelineContext $context): bool
	{
		$activity = $context->getActivity();

		return $activity->getSubject() !== null || $activity->getDescription() !== null;
	}

	public function execute(PipelineContext $context): Result
	{
		$activity = $context->getActivity();

		$subject = $activity->getSubject();
		if ($subject !== null)
		{
			$context->setRunValue(PipelineContext::RUN_VALUE_UNENCODED_SUBJECT, $subject);
			$activity->internalSet(Activity::subject, (string)Emoji::encode($subject));
		}

		$description = $activity->getDescription();
		if ($description !== null)
		{
			$context->setRunValue(PipelineContext::RUN_VALUE_UNENCODED_DESCRIPTION, $description);
			$activity->internalSet(Activity::description, (string)Emoji::encode($description));
		}

		return new Result();
	}

	/**
	 * Legacy interrupts nothing here.
	 */
	public function isAbortingOnFailure(): bool
	{
		return false;
	}
}
