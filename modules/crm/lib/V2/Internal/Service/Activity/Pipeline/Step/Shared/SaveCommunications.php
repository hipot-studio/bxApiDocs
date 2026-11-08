<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared;

use Bitrix\Crm\V2\Internal\Repository\Activity\ActivityCommunicationRepository;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Main\Result;

/**
 * Stores through which channels and at which addresses the activity talks: step 17 of the owner table of an add
 * (crm_activity.php:269-272, LBS-1 section 7), step 19 of the owner table of an update (:741-744).
 *
 * THE SET IS REPLACED AND NOT DIFFERENCED, unlike the bindings: the identifiers of the rows are not stable in
 * legacy and the registry of intentional deviations grants them no stability, so the storage layer deletes the
 * whole set and inserts the given one back (checklist item F6.3). Two values the caller cannot name travel with
 * it and are the business of that layer as well - the owner of a communication, which is always the owner of the
 * activity (F6.2), and the snapshot of the names of the entity it points at (F6.1).
 *
 * THREE STATES OF THE SET AND THE GATE IS ONE OF THEM. Legacy asks whether the key is there and whether it holds
 * an array (:269 on an add, :741 on an update, F6.4): no set means "leave what is stored alone", so it is not
 * that the step stores nothing - it does not run at all. A typed model answers both halves of that question at
 * once, an untouched set of communications being null, which is why the two rows of the owner tables read
 * differently and mean the same thing here.
 *
 * NO HISTORY OF A COMMUNICATION IS WRITTEN, and nothing has to be reproduced for that (F6.5): legacy passes
 * `false` for the registration of events from both pipelines. It would write none for an activity of a provider
 * anyway - the name of the event is a phrase suffixed by the type of the activity, and the types that have such
 * a phrase are the legacy ones (`GetEventMessageSuffix`, :5738-5742), so the lookup of a provider activity comes
 * back empty and nothing is registered (:5845).
 *
 * THE STEP TAKES NO MODE. Its gate and its effect are the same in both pipelines, so naming the operation would
 * configure nothing - the same reason the preparation of the payload of a provider takes none.
 *
 * @internal
 */
final class SaveCommunications implements PipelineStepInterface
{
	private readonly ActivityCommunicationRepository $repository;

	/**
	 * @param ActivityCommunicationRepository|null $repository the storage boundary of the communications; the
	 *        one seam of the step.
	 */
	public function __construct(?ActivityCommunicationRepository $repository = null)
	{
		$this->repository = $repository ?? new ActivityCommunicationRepository();
	}

	public function getName(): string
	{
		return 'SaveCommunications';
	}

	/**
	 * Only where the caller said something about the communications, and only for an activity whose rows can
	 * name it.
	 */
	public function isApplicable(PipelineContext $context): bool
	{
		if ($context->getActivityId() <= 0)
		{
			return false;
		}

		return $context->getActivity()->getCommunications() !== null;
	}

	public function execute(PipelineContext $context): Result
	{
		$this->repository->save($context->getActivityId(), $context->getActivity()->getCommunications());

		return new Result();
	}

	/**
	 * A set of communications that could not be stored does not end an add: legacy checks the answer of its
	 * write nowhere on this path.
	 */
	public function isAbortingOnFailure(): bool
	{
		return false;
	}
}
