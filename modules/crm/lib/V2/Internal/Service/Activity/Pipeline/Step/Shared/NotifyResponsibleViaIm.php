<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Internal\Access\Activity\ActivityAccess;
use Bitrix\Crm\V2\Internal\Entity\Activity\ActivityChangeSet;
use Bitrix\Crm\V2\Internal\Integration\Im\ActivityNotifier;
use Bitrix\Crm\V2\Internal\Service\Activity\ActivityLifecycleService;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Result;

/**
 * Tells the user a call or a meeting now belongs to that it is theirs, and tells the two users one changed
 * hands between what happened: step 33 of the owner table of an add, step 44 of the owner table of an update
 * (checklist items G6 and G7).
 *
 * AN EFFECT LEGACY KEEPS INSIDE ANOTHER ONE. Legacy has no such step: the notification is wired into the
 * methods writing a record of the live feed (crm_activity.php:7776-7896 on an add, :8000-8184 on an update),
 * so an activity is notified about only where such a record is being written - or where writing it failed
 * without an error, which is the state of a portal with the live feed switched off, and the notification is
 * sent there all the same. Extracting it into a step of its own is the whole point of the two items, and it
 * makes duplication the thing to be afraid of: see the marker of the entry below.
 *
 * THE ENTRY OF THE RUN IS THE ONLY DIVIDER (canonical block ALG-06). This step notifies when the operation
 * did NOT come through the legacy facade, and the transitional bridge of the live feed - which calls the
 * legacy methods with their own notification inside - runs when it did. The two split the three sources of a
 * call into "the facade" and "not the facade" and leave no third outcome, so nothing is notified twice and
 * nothing stops being notified. The question is asked as {@see PipelineContext::isNativeEntry()}, which is
 * "not the facade" rather than "is the native entry": the public command of the future belongs to the native
 * side of the pair, and a predicate comparing with the native entry would silence both halves for it.
 *
 * THE COMPARISON IS WITH THE INITIATOR, NOT WITH THE AUTHOR (item G6). An assignment is notified about when
 * the responsible is somebody other than the user the operation is by, and legacy resolves that user
 * differently per operation: an add takes the author of the activity, then its editor, then the user of the
 * hit (:7642-7646), while an update takes the editor and then the user of the hit (:7977). Comparing with the
 * author alone would notify a user who assigned an activity to themselves through an add that carries no
 * author, and comparing with the acting user alone would stop notifying where legacy notifies.
 *
 * THE RIGHTS ARE THE RIGHTS OF THE ADDRESSEE. Whether the notification carries a link to the activity, and
 * whether the user who lost it hears about it at all, follows from what THAT user may read - never from what
 * the caller of the operation may read. The messenger layer cannot tell the two apart: it is handed the fact
 * ({@see ActivityNotifier::INPUT_RESPONSIBLE_HAS_READ_ACCESS}), and putting the wrong user's rights into it
 * would leak an activity to somebody who may not see it, or hide it from somebody who may. The fact comes
 * from the access model of the module through {@see Container::getActivityAccess()}, which is assembled once
 * per hit: a step assembling its own copy would answer from a cache that no longer sees what the step saving
 * the bindings invalidated.
 *
 * THE NOTIFICATION POINTS AT NO RECORD OF THE LIVE FEED, and none is looked for. A run that reaches this step
 * is a run the bridge of the live feed did not run in, so no record of it exists; that is the very state legacy
 * is in on a portal with the live feed off, where it sends the notification with a record of zero. The layer
 * defaults to zero, so nothing about it is restated here - and should a native producer of such records ever
 * appear, the value would come from the step that wrote it, through the context of the run.
 *
 * ONE CLASS, TWO OPERATIONS, AND THE OPERATION IS TOLD. The two owner tables name the same step, and the
 * conditions of it differ: an add asks about the responsible against the initiator, an update asks whether the
 * responsible changed. Which of the two this instance is comes from whoever declares the pipeline and is never
 * derived from the state of the run - a set of changes that happens to be filled does not turn an add into an
 * update.
 *
 * @internal
 */
final class NotifyResponsibleViaIm implements PipelineStepInterface
{
	/** The storage column the legacy type of the activity travels in: the typed model does not carry it. */
	private const COLUMN_ACTIVITY_TYPE = 'TYPE_ID';

	private const OPERATIONS = [
		ActivityLifecycleService::OPERATION_ADD,
		ActivityLifecycleService::OPERATION_UPDATE,
	];

	private readonly ActivityNotifier $notifier;

	/**
	 * @param string $operation which of the two pipelines this instance belongs to, named by whoever declares
	 *        it: {@see ActivityLifecycleService::OPERATION_ADD} notifies an assignment,
	 *        {@see ActivityLifecycleService::OPERATION_UPDATE} notifies a change of the responsible.
	 * @param ActivityNotifier|null $notifier the messenger side of an activity; the layer of the module itself
	 *        by default.
	 * @throws ArgumentException on an operation that has no notification about the responsible.
	 */
	public function __construct(
		private readonly string $operation,
		?ActivityNotifier $notifier = null,
	)
	{
		if (!in_array($operation, self::OPERATIONS, true))
		{
			throw new ArgumentException(
				"The '{$operation}' operation over an activity has no notification about its responsible",
				'operation',
			);
		}

		$this->notifier = $notifier ?? new ActivityNotifier();
	}

	public function getName(): string
	{
		return 'NotifyResponsibleViaIm';
	}

	/**
	 * The conditions of the two owner tables, in the order that asks the cheap questions first: the entry of
	 * the run, the type of the activity, the users involved, and only then the messenger - which loads its
	 * module to answer.
	 */
	public function isApplicable(PipelineContext $context): bool
	{
		if (!$context->isNativeEntry())
		{
			return false;
		}

		if (!$this->notifier->supportsActivityType($this->activityTypeOf($context)))
		{
			return false;
		}

		$isNotified = $this->isAdd()
			? $this->isAssignmentNotified($context)
			: $context->getChanges()->isChanged(ActivityChangeSet::responsibleId)
		;

		return $isNotified && $this->notifier->isAvailable();
	}

	public function execute(PipelineContext $context): Result
	{
		if ($this->isAdd())
		{
			$this->notifier->notifyAssignment($this->assignment($context));
		}
		else
		{
			$this->notifier->notifyResponsibleChange($this->responsibleChange($context));
		}

		return new Result();
	}

	/**
	 * Legacy interrupts no operation over a notification that did not go out, and neither does this step: an
	 * activity is written, and whether its responsible was told about it is not a condition of that.
	 */
	public function isAbortingOnFailure(): bool
	{
		return false;
	}

	private function isAdd(): bool
	{
		return $this->operation === ActivityLifecycleService::OPERATION_ADD;
	}

	/**
	 * Whether an assignment is notified about at all: there is a user it belongs to, and that user is not the
	 * one who did it.
	 */
	private function isAssignmentNotified(PipelineContext $context): bool
	{
		$responsibleId = (int)$context->getActivity()->getResponsibleId();

		return $responsibleId > 0 && $responsibleId !== $this->initiatorOf($context);
	}

	/**
	 * @return array<string, mixed> the inputs of an assignment
	 */
	private function assignment(PipelineContext $context): array
	{
		$activityId = $context->getActivityId();
		$responsibleId = (int)$context->getActivity()->getResponsibleId();

		return [
			ActivityNotifier::INPUT_ACTIVITY_ID => $activityId,
			ActivityNotifier::INPUT_ACTIVITY_TYPE_ID => $this->activityTypeOf($context),
			ActivityNotifier::INPUT_SUBJECT => (string)$context->getActivity()->getSubject(),
			ActivityNotifier::INPUT_SENDER_ID => $this->initiatorOf($context),
			ActivityNotifier::INPUT_RESPONSIBLE_ID => $responsibleId,
			ActivityNotifier::INPUT_RESPONSIBLE_HAS_READ_ACCESS => $this->mayRead($responsibleId, $activityId),
		];
	}

	/**
	 * The inputs of a change of the responsible, both addressees at once. The pair of values is there to be
	 * read: the predicate above asked for it.
	 *
	 * @return array<string, mixed>
	 */
	private function responsibleChange(PipelineContext $context): array
	{
		$activityId = $context->getActivityId();
		$changes = $context->getChanges();
		$previousResponsibleId = (int)$changes->getPrevious(ActivityChangeSet::responsibleId);
		$responsibleId = (int)$changes->getCurrent(ActivityChangeSet::responsibleId);

		return [
			ActivityNotifier::INPUT_ACTIVITY_ID => $activityId,
			ActivityNotifier::INPUT_ACTIVITY_TYPE_ID => $this->activityTypeOf($context),
			ActivityNotifier::INPUT_SUBJECT => (string)$context->getActivity()->getSubject(),
			ActivityNotifier::INPUT_SENDER_ID => $this->initiatorOf($context),
			ActivityNotifier::INPUT_PREVIOUS_RESPONSIBLE_ID => $previousResponsibleId,
			ActivityNotifier::INPUT_PREVIOUS_RESPONSIBLE_HAS_READ_ACCESS => $this->mayRead(
				$previousResponsibleId,
				$activityId,
			),
			ActivityNotifier::INPUT_RESPONSIBLE_ID => $responsibleId,
			ActivityNotifier::INPUT_RESPONSIBLE_HAS_READ_ACCESS => $this->mayRead($responsibleId, $activityId),
		];
	}

	/**
	 * The user the operation is by: the author of the activity on an add, its editor otherwise, and the user
	 * the operation acts as when the activity names neither.
	 */
	private function initiatorOf(PipelineContext $context): int
	{
		$activity = $context->getActivity();

		if ($this->isAdd())
		{
			$authorId = (int)$activity->getAuthorId();
			if ($authorId > 0)
			{
				return $authorId;
			}
		}

		$editorId = (int)$activity->getEditorId();

		return $editorId > 0 ? $editorId : $this->actingUserOf($context);
	}

	/**
	 * The user the operation acts as: the one the caller named, and the user of the hit when it named nobody -
	 * which is what legacy reads directly.
	 */
	private function actingUserOf(PipelineContext $context): int
	{
		$actingUserId = $context->getOperationContext()->getCurrentUserId();

		return $actingUserId > 0 ? $actingUserId : Container::getInstance()->getContext()->getUserId();
	}

	/**
	 * Whether that user may read the activity. Nobody is not a user, and nothing is read on behalf of nobody:
	 * an id that is not one gets the answer of a user without rights rather than the rights of the hit.
	 */
	private function mayRead(int $userId, int $activityId): bool
	{
		if ($userId <= 0)
		{
			return false;
		}

		return $this->accessModel()->canRead($userId, $activityId);
	}

	/**
	 * The access model of the module, named rather than reached for through a getter alone: this step is the
	 * first consumer of it, and the guard of that rollout ([P3.T6]) lists the files referring to it by name.
	 * The one instance of the hit is what answers - it is assembled once, remembers its decisions, and the step
	 * saving the bindings of an activity has to be able to make it forget them.
	 */
	private function accessModel(): ActivityAccess
	{
		return Container::getInstance()->getActivityAccess();
	}

	/**
	 * The legacy type of the activity, zero when the operation carries none - and then nothing is notified
	 * about, because only two types have a notification written for them.
	 */
	private function activityTypeOf(PipelineContext $context): int
	{
		$transit = $context->getTransit();

		return $transit->has(self::COLUMN_ACTIVITY_TYPE) ? (int)$transit->get(self::COLUMN_ACTIVITY_TYPE) : 0;
	}
}
