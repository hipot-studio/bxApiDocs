<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared;

use Bitrix\Crm\Activity\LastCommunication\SyncLastCommunication;
use Bitrix\Crm\Activity\Provider\ProviderManager;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Timeline\Monitor;
use Bitrix\Crm\Timeline\ActivityController;
use Bitrix\Crm\V2\Internal\Access\Activity\ActivityAccess;
use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Crm\V2\Internal\Entity\Activity\ActivityBinding;
use Bitrix\Crm\V2\Internal\Entity\Activity\ActivityChangeSet;
use Bitrix\Crm\V2\Internal\Repository\Activity\ActivityBindingRepository;
use Bitrix\Crm\V2\Internal\Service\Activity\ActivityLifecycleService;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Closure;

/**
 * Stores which CRM objects the activity belongs to: step 16 of the owner table of an add
 * (crm_activity.php:268, LBS-1 section 6), step 18 of the owner table of an update (:718-731).
 *
 * THE WRITE IS A DIFFERENCE, AND THE STEP IS WHY THAT IS OBSERVABLE. The rules of reducing the given set to
 * the effective one and of applying only the difference belong to the storage layer (canonical block ALG-02,
 * {@see ActivityBindingRepository}): duplicates collapse with the first occurrence winning, an owner of an
 * undefined type is dropped, a set longer than the limit is trimmed to the largest owner ids, and the rows
 * that stay keep their identifiers. What the storage layer deliberately does not do is act on the change - it
 * hands it over - so everything legacy recomputes around the write of the set is here, in one listener, and it
 * runs ONCE per write instead of twice.
 *
 * WHY TWICE IN LEGACY. Legacy replaces the whole set: `DoSaveBindings` first deletes every row through
 * `DeleteBindings` - which recomputes the badges over every binding as removed and tells the timeline and the
 * last-communication index about each of them (:2254-2270) - and then inserts the effective set back and
 * recomputes all of it again over the real difference (mysql/crm_activity.php:139-170). A binding that did not
 * move is therefore removed and added again as far as every consumer can tell. Here the consumers hear about
 * the changed subset only, once (registry of intentional deviations, rows F3/F4-BINDINGS).
 *
 * FOUR CONSUMERS OF THE CHANGE, AND ONE OF THEM IS NOT AN EFFECT BUT A CACHE. The badges of the objects the
 * activity is bound to, the timeline monitor, the last-communication index - those are the effects legacy
 * performs from inside its write. The fourth is the request cache of the permission model (open question Q-4):
 * the right to an activity is derived from its bindings, so a set that changed within a hit makes every
 * decision remembered about that activity stale. The model is asked for through the container and never built
 * here ({@see self::accessModel()}): a model of one's own would remember its own decisions and forget its own
 * bindings, and the invalidation would reach nobody.
 *
 * WHAT THIS STEP DOES NOT RECOMPUTE. The read model of uncompleted activities and the counters, which legacy
 * skips too when the write comes from a pipeline (checklist item F5, the `false` of :276): both are steps of
 * the owner table on their own (rows 21 and 26 of STEP-ADD), and doing them here would double them.
 *
 * THE TIMELINE BINDINGS ARE SYNCHRONIZED WHATEVER CHANGED, exactly as legacy does it from the core method and
 * not from the write (:2865): the call is a synchronization of a set against the rows of the timeline entries
 * of the activity, it computes its own difference, and on an add it finds no entry at all - the entries appear
 * with row 30 of the owner table, below this step.
 *
 * THE STEP HAS NO SEAM, AND THAT IS A CONSEQUENCE OF THE LISTENER. The storage layer takes it as a constructor
 * argument, so a repository handed to the step from outside would arrive without the one thing that makes this
 * step a step - and a step whose effects can be substituted away would be proven by nothing. What is written and
 * what is recomputed is observable in the storage itself, which is where the expectations look.
 *
 * ONE CLASS, TWO OPERATIONS, AND THE WRITE IS WHERE THEY DIFFER. An add always writes the set - every add has an
 * owner appended to it by the step above - while an update writes it only where the caller gave one and it differs
 * from the snapshot row 4 took before the write. That difference is not a gate of the step: an update ALWAYS runs
 * this row, because "what is the set of the activity now" is a question every update has to answer and this is the
 * one place answering it. Legacy answers it in the same single place and in three branches (:719-732): a set that
 * was given and moved becomes the effective set of the write, a set that was given and did not move stays as it was
 * given, and an update that named no set at all takes over the snapshot of row 4. The last branch is the reason the
 * row cannot be gated away - it is what every row below reads as "the bindings of the activity", and gating it on
 * "a set was given" would leave that answer empty for the majority of updates, each of which would then have to
 * invent the fallback for itself.
 *
 * WHAT AN UPDATE LEAVES BEHIND BESIDES THE SET. Two things, and both have exactly one producer here. The pair of
 * the change set ({@see ActivityChangeSet::bindings}) is the answer to "did the bindings change", which legacy
 * keeps in a local boolean and reads three rows below at the statistics of the previous deadline; the pair holds
 * the two sets legacy compares - the snapshot and the set as it was given - so that the answer is the comparison
 * legacy makes and not one made over the effective set. And the flag of the aggregate of the uncompleted
 * activities is raised on that same answer, never lowered, exactly as legacy raises its second local boolean.
 *
 * THE STORAGE COLUMNS OF THE RUN TRAVEL WITH THE SET, AND ONE OF THEM IS THE REASON. Writing the set brings the
 * owner columns of the activity in step with it, and the rule doing that is the one of the write path: an owner
 * the operation itself named wins over the one derived from a set. Without the columns of the run the storage
 * layer only ever sees the set, so an update naming both an owner and a set of bindings would have its owner
 * written by row 12 and taken away again here.
 *
 * ONE DIFFERENCE OF THE COMPARISON IS DELIBERATE. Legacy compares the two sets by size and by a one-directional
 * membership test (`IsBindingsEquals`, :2965-2993), so a given set of two identical bindings reads as equal to a
 * stored set of two different ones. What "changed" means for a collection is declared once, by the change set -
 * unordered by the keys of its elements - and asking it is what keeps a second answer from existing. The two differ
 * only for a caller repeating a binding, whose duplicate the effective set collapses anyway, so nothing is written
 * differently because of it.
 *
 * @internal
 */
final class SaveBindings implements PipelineStepInterface
{
	/**
	 * The run of an update reached this step without the state the activity was in, which only happens when the
	 * step reading that state is missing from the declaration or has failed. Whether the set of the activity moved
	 * is a question about that state, so there is nothing to decide and nothing to write without it.
	 */
	public const ERROR_PREVIOUS_STATE_NOT_LOADED = 'CRM_ACTIVITY_PREVIOUS_STATE_NOT_LOADED';

	private const OPERATIONS = [
		ActivityLifecycleService::OPERATION_ADD,
		ActivityLifecycleService::OPERATION_UPDATE,
	];

	/**
	 * @param string $operation which of the pipelines this instance belongs to, named by whoever declares it.
	 * @throws ArgumentException on an operation this step has no write of the set of its own for yet.
	 */
	public function __construct(private readonly string $operation)
	{
		if (!in_array($operation, self::OPERATIONS, true))
		{
			throw new ArgumentException(
				"The '{$operation}' operation over an activity has no write of the bindings of its own yet",
				'operation',
			);
		}
	}

	public function getName(): string
	{
		return 'SaveBindings';
	}

	/**
	 * Every add writes its set of bindings and every update answers what its set now is. Having an identifier is
	 * not a condition of the row but what a row of a binding is made of, and only the insert can hand one over.
	 */
	public function isApplicable(PipelineContext $context): bool
	{
		return $context->getActivityId() > 0;
	}

	public function execute(PipelineContext $context): Result
	{
		return $this->isUpdate() ? $this->answerTheSetOfAnUpdate($context) : $this->writeTheSetOfAnAdd($context);
	}

	/**
	 * A set of bindings that could not be stored does not end an operation: legacy checks the answer of its write
	 * nowhere on either path.
	 */
	public function isAbortingOnFailure(): bool
	{
		return false;
	}

	private function isUpdate(): bool
	{
		return $this->operation === ActivityLifecycleService::OPERATION_UPDATE;
	}

	private function writeTheSetOfAnAdd(PipelineContext $context): Result
	{
		$activityId = $context->getActivityId();
		$activity = $context->getActivity();

		$effective = $this->repositoryOfTheWrite()->save(
			$activityId,
			$activity->getBindings() ?? [],
			$context->getTransit(),
		);

		// The effective set is the answer of the write and the steps below read it from here: the statistics
		// per binding, the search content, the record of the activity stream. There is no second carrier of it,
		// and it is no change anybody asked for - hence the hydration path.
		$activity->internalSet(Activity::bindings, $effective);

		ActivityController::synchronizeBindings($activityId, self::legacyFormOf($effective));

		return new Result();
	}

	/**
	 * The three branches of an update, in the order legacy has them (:719-732). Whichever of them runs, the model
	 * of the activity carries the set of the activity afterwards and the change set carries the comparison.
	 */
	private function answerTheSetOfAnUpdate(PipelineContext $context): Result
	{
		$result = new Result();

		if (!$context->hasPreviousState())
		{
			return $result->addError(new Error(
				'The previous state of the activity was not loaded.',
				self::ERROR_PREVIOUS_STATE_NOT_LOADED,
			));
		}

		$activity = $context->getActivity();
		$snapshot = $context->getPreviousActivity()->getBindings() ?? [];
		$given = $activity->getBindings();

		$context->getChanges()->set(ActivityChangeSet::bindings, $snapshot, $given ?? $snapshot);

		if ($given === null)
		{
			// An update that named no set is bound to what it was bound to, and that is the answer every row
			// below reads.
			$activity->internalSet(Activity::bindings, $snapshot);

			return $result;
		}

		if (!$context->getChanges()->isChanged(ActivityChangeSet::bindings))
		{
			// The set of the activity is the set that was given, untouched by any write - the shape legacy keeps
			// in its variable when the comparison says the two are the same.
			return $result;
		}

		$activityId = $context->getActivityId();
		$activity->internalSet(
			Activity::bindings,
			$this->repositoryOfTheWrite()->save($activityId, $given, $context->getTransit()),
		);
		$context->setRunValue(PipelineContext::RUN_VALUE_UNCOMPLETED_AGGREGATE_NEEDS_REFRESH, true);

		ActivityController::synchronizeBindings($activityId, self::legacyFormOf($activity->getBindings() ?? []));

		return $result;
	}

	private function repositoryOfTheWrite(): ActivityBindingRepository
	{
		return new ActivityBindingRepository(onBindingsChanged: $this->listenerOfTheChange());
	}

	/**
	 * `function (int $activityId, ActivityBinding[] $added, ActivityBinding[] $removed): void` - called by the
	 * storage layer once per write that moved the set, with the changed subsets only.
	 */
	private function listenerOfTheChange(): Closure
	{
		return function (int $activityId, array $added, array $removed): void {
			$this->forgetWhatWasDecidedAboutTheActivity($activityId);
			$this->recomputeWhatDependsOnTheBindings($activityId, $added, $removed);
		};
	}

	/**
	 * Drops every decision of this hit about the activity, together with the bindings those decisions were
	 * derived from (open question Q-4). It goes first: whoever recomputes below may ask about rights, and an
	 * answer remembered before the set moved would be an answer about the set that no longer exists.
	 */
	private function forgetWhatWasDecidedAboutTheActivity(int $activityId): void
	{
		$this->accessModel()->forgetActivity($activityId);
	}

	/**
	 * The effects legacy performs from inside the write of the set, over the changed subset and once
	 * (mysql/crm_activity.php:139-170).
	 *
	 * @param ActivityBinding[] $added
	 * @param ActivityBinding[] $removed
	 */
	private function recomputeWhatDependsOnTheBindings(int $activityId, array $added, array $removed): void
	{
		ProviderManager::syncBadgesOnBindingsChange(
			$activityId,
			self::legacyFormOf($added),
			self::legacyFormOf($removed),
		);

		$monitor = Monitor::getInstance();
		$lastCommunication = new SyncLastCommunication();

		foreach ($added as $binding)
		{
			$identifier = self::identifierOf($binding);
			if ($identifier !== null)
			{
				$monitor->onActivityAddIfSuitable($identifier, $activityId);
			}
		}

		if ($added !== [])
		{
			$lastCommunication->onActivityAddIfSuitable($activityId);
		}

		foreach ($removed as $binding)
		{
			$identifier = self::identifierOf($binding);
			if ($identifier === null)
			{
				continue;
			}

			$monitor->onActivityRemoveIfSuitable($identifier, $activityId);
			$lastCommunication->onActivityRemoveIfSuitable($identifier, $activityId);
		}
	}

	/**
	 * The access model of the module, named rather than reached for through a getter alone: the guard of the
	 * rollout of the new rights ([P3.T6]) lists the files referring to it by name. The one instance of the hit
	 * is what has to forget - it is the same object the rest of the hit asks.
	 */
	private function accessModel(): ActivityAccess
	{
		return Container::getInstance()->getActivityAccess();
	}

	/**
	 * The shape every legacy consumer of a binding reads: the pair of owner columns of a row.
	 *
	 * @param ActivityBinding[] $bindings
	 * @return array<int, array<string, int>>
	 */
	private static function legacyFormOf(array $bindings): array
	{
		$rows = [];
		foreach ($bindings as $binding)
		{
			$rows[] = [
				'OWNER_TYPE_ID' => $binding->getOwnerType()->getId(),
				'OWNER_ID' => $binding->getOwnerId(),
			];
		}

		return $rows;
	}

	/**
	 * The owner of a binding as an item identifier, or null for an owner no identifier can be made of. Rows of
	 * `b_crm_act_bind` carry owner types no longer defined, and legacy skips exactly those bindings here rather
	 * than failing over them (`ItemIdentifier::createFromArray()`, mysql/crm_activity.php:146-168).
	 */
	private static function identifierOf(ActivityBinding $binding): ?ItemIdentifier
	{
		return ItemIdentifier::createByParams($binding->getOwnerType()->getId(), $binding->getOwnerId());
	}
}
