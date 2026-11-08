<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Access\Activity;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Crm\V2\Internal\Entity\Activity\ActivityBinding;
use Bitrix\Crm\V2\Internal\Repository\Activity\ActivityBindingRepository;
use Bitrix\Crm\V2\Internal\Repository\Activity\ActivityRepositoryInterface;
use Bitrix\Crm\V2\Internal\Repository\Activity\RememberingActivityRepository;
use Closure;

/**
 * What a user may do with an activity: read it, update it, delete it, complete it, postpone it.
 *
 * THE SUBJECT IS ALWAYS AN EXPLICIT USER. There is no implicit "current user" here, and no system subject
 * either: a caller acting on behalf of the system does not ask about rights at all. A user id that is not a
 * user is nobody - not an administrator - so it is answered like anybody without rights.
 *
 * THE RIGHT IS DERIVED. CRM has no permission entity for an activity; the right to it follows from the
 * rights on the CRM objects it is bound to, by one quantifier for every one of the five operations: having
 * the right on any single binding is enough. Legacy asked for all of the bindings on a delete and for one
 * on everything else; that asymmetry is gone, so deleting is no longer harder than updating. The most
 * sensitive case, deleting a call, stays cut off by a restriction of the domain rather than by a stricter
 * quantifier.
 *
 * THE ORDER OF THE DECISION IS NORMATIVE, and {@see self::resolve()} spells it out. Three of its steps
 * deserve to be stated here, because all three are easy to get backwards:
 * - an activity no row answers for is refused first of all, ahead of the provider and ahead of the
 *   administrator bypass. What does not exist is not accessible to anybody, and an administrator is nobody's
 *   exception to that: a bypass reached before the data is looked at would answer "yes" about a row that is
 *   not there;
 * - the provider is asked before the administrator bypass, so a provider that forbids outweighs a portal
 *   administrator. This is not a hardening - a document activity forbids updating and postponing to
 *   everybody, administrators included, and legacy applies that hook on top of the core check;
 * - being the responsible person is a bypass for reading only. It is not one for updating, deleting,
 *   completing or postponing: the responsible person sees the activity, and changes it only with the rights
 *   on the objects behind it.
 *
 * THE DATA COMES FROM THE DATABASE. Bindings and the owner columns are read, never taken from fields the
 * caller passed in, and the responsible person is compared as an integer. Legacy did both the other way
 * round, which is why an activity known by its id alone was refused there.
 *
 * INSIDE IS THE MODERN PERMISSION FACADE. `CCrmPerms` is not handed out through this class in any form.
 *
 * WHERE IT GROWS. Three seams are deliberate. {@see self::decide()} is the entry point and
 * {@see self::resolve()} the order proper, so that remembering a decision per user, activity and operation
 * ({@see DecisionCache}) wraps the order instead of restructuring its early exits. The verdict of the
 * provider arrives as a closure, so the core rules do not depend on the provider layer. What the bindings are
 * read through ({@see BindingAttributeLoader}) answers a single question and a preloaded batch the same way,
 * so asking about many activities costs a number of queries that does not grow with their number. The rows of
 * the activities are the same story, and the memory of them belongs to the repository
 * ({@see RememberingActivityRepository}): a batch warms it, and the resolver of the provider verdict reads out
 * of the very same one - which is what makes both of them free after a preload.
 *
 * @internal
 */
final class ActivityAccess
{
	private readonly RememberingActivityRepository $activityRepository;

	private readonly ?Closure $providerVerdictResolver;

	private readonly CallDeletionRestrictionRule $callDeletionRestriction;

	private readonly DecisionCache $decisionCache;

	private readonly BindingAttributeLoader $bindingLoader;

	/**
	 * @param RememberingActivityRepository|null $activityRepository
	 *        the rows of the activities. THE MEMORY OF THE HIT IS PART OF WHAT IS ASKED FOR, not a decoration this
	 *        class adds: {@see RememberingActivityRepository::exists()} and
	 *        {@see RememberingActivityRepository::forget()} are declared by that class and by no interface, and
	 *        handing the very same object to the resolver of the provider verdict is what keeps a row read once
	 *        from being read again. Null builds one over the default storage boundary; a repository without a
	 *        memory is handed to THAT constructor and not to this one. The type says so rather than promising
	 *        {@see ActivityRepositoryInterface}: a double given by the interface would be silently wrapped here
	 *        and never asked anything.
	 * @param Closure|null $providerVerdictResolver
	 *        `function (int $userId, int $activityId, Operation $operation): AccessDecision` - the verdict of
	 *        the provider of the activity. Without one no provider states anything, which is also what a
	 *        provider that overrides no permission hook of its own means: the question falls through to the
	 *        core rules. {@see ProviderVerdictResolver} is what answers it in the end.
	 * @param DecisionCache|null $decisionCache
	 *        the decisions of the hit. One passed in is shared with whoever else holds it - the step of the
	 *        pipeline that forgets an activity after saving its bindings, above all; without one this object
	 *        remembers only for itself.
	 * @param BindingAttributeLoader|null $bindingLoader
	 *        likewise, and for the same reason: a loader passed in carries a batch somebody else preloaded.
	 */
	public function __construct(
		?RememberingActivityRepository $activityRepository = null,
		?ActivityBindingRepository $bindingRepository = null,
		?Closure $providerVerdictResolver = null,
		?CallDeletionRestrictionRule $callDeletionRestriction = null,
		?DecisionCache $decisionCache = null,
		?BindingAttributeLoader $bindingLoader = null,
	)
	{
		$this->activityRepository = $activityRepository ?? new RememberingActivityRepository();
		$bindingRepository ??= new ActivityBindingRepository($this->activityRepository);
		$this->providerVerdictResolver = $providerVerdictResolver;
		$this->callDeletionRestriction = $callDeletionRestriction
			?? new CallDeletionRestrictionRule($bindingRepository);
		$this->decisionCache = $decisionCache ?? new DecisionCache();
		$this->bindingLoader = $bindingLoader
			?? new BindingAttributeLoader($this->activityRepository, $bindingRepository);
	}

	public function canRead(int $userId, int $activityId): bool
	{
		return $this->can($userId, $activityId, Operation::Read);
	}

	public function canUpdate(int $userId, int $activityId): bool
	{
		return $this->can($userId, $activityId, Operation::Update);
	}

	/**
	 * Deleting takes the same rights as the other four operations, and on top of them the one restriction
	 * the domain puts on deleting: the record of a telephone conversation is not removed without an
	 * administrator ({@see CallDeletionRestrictionRule}).
	 *
	 * The restriction lives here and in no other operation, and the general {@see self::decide()} stays
	 * clear of it: it is not a right and it is remembered by nobody. The pipeline of a delete asks the rule
	 * directly rather than through this method - legacy has only the restriction wired into its delete, and
	 * the rights over the bound objects stay with the caller.
	 */
	public function canDelete(int $userId, int $activityId): bool
	{
		if ($this->callDeletionRestriction->isDeletionRestricted($userId, $activityId))
		{
			return false;
		}

		return $this->can($userId, $activityId, Operation::Delete);
	}

	public function canComplete(int $userId, int $activityId): bool
	{
		return $this->can($userId, $activityId, Operation::Complete);
	}

	public function canPostpone(int $userId, int $activityId): bool
	{
		return $this->can($userId, $activityId, Operation::Postpone);
	}

	public function can(int $userId, int $activityId, Operation $operation): bool
	{
		return $this->decide($userId, $activityId, $operation)->isAllowed();
	}

	/**
	 * The decision itself, always {@see AccessDecision::Allow} or {@see AccessDecision::Deny}.
	 *
	 * An id no activity can have is refused without asking anybody, and an id no row answers for is refused by
	 * the first step of the order: what does not exist is not accessible.
	 *
	 * A decision already taken in this hit is not taken twice: it is remembered per user, activity and
	 * operation ({@see DecisionCache}), and the order proper runs on the first question only.
	 */
	public function decide(int $userId, int $activityId, Operation $operation): AccessDecision
	{
		if ($activityId <= 0)
		{
			return AccessDecision::Deny;
		}

		return $this->decisionCache->remember(
			$userId,
			$activityId,
			$operation,
			fn(): AccessDecision => $this->resolve($userId, $activityId, $operation),
		);
	}

	/**
	 * Reads what the checks over these activities need, before them and in one batch: the bindings, the owners
	 * of the activities left without one, and the permission attributes of every object involved
	 * ({@see BindingAttributeLoader}), plus the rows of the activities themselves. The checks that follow read
	 * none of it again, whatever they ask about: the row is what the bypass of the responsible person compares
	 * and what the verdict of the provider is typed by, and both are served from the memory of the hit.
	 *
	 * Whoever knows its set is expected to hand it over - a pipeline over a set of activities, a reader of a
	 * list. A single check needs none of this and pays for what it reads on its own.
	 *
	 * @param int[] $activityIds
	 */
	public function preload(int $userId, array $activityIds): void
	{
		$this->bindingLoader->preload($userId, $activityIds);
		$this->activityRepository->getByIds($activityIds);
	}

	/**
	 * The activities of the set as this mode ({@see AccessMode}) lets the user see them, in the order they came
	 * in: the ones that may be read as they are, the rest either gone or replaced by a stub.
	 *
	 * The rights over the whole set are read before the first check and in one batch ({@see self::preload()}),
	 * so the checks that follow reach the database for nothing. Handing a set over without preloading it is what
	 * turns this into a query per activity.
	 *
	 * An activity that carries no id is nothing to check the rights of - there is no row to derive them from -
	 * and nothing to show a placeholder of either, so it is out of the result under both modes.
	 *
	 * @param Activity[] $activities
	 * @return array<int, Activity|RestrictedActivityStub>
	 */
	public function readable(int $userId, array $activities, AccessMode $mode): array
	{
		$this->preload($userId, $this->idsOf($activities));

		$result = [];
		foreach ($activities as $activity)
		{
			$activityId = (int)$activity->getId();
			if ($activityId <= 0)
			{
				continue;
			}

			if ($this->canRead($userId, $activityId))
			{
				$result[] = $activity;

				continue;
			}

			if ($mode === AccessMode::Restricted)
			{
				$result[] = new RestrictedActivityStub($activityId);
			}
		}

		return $result;
	}

	/**
	 * Bindings of the activity as the checks see them: the ones a decision over it was taken on, or a read of its
	 * own where no check has read them yet. A reader that needs the bindings after the check takes them here and
	 * reads them no second time.
	 *
	 * @return ActivityBinding[]
	 */
	public function getBindings(int $activityId): array
	{
		return $this->loadBindings($activityId);
	}

	/**
	 * Forgets what is known about this activity - every decision about it, its bindings and its row.
	 *
	 * The bindings of an activity are what the rights over it are derived from, so a change to them within a
	 * hit invalidates all three. They are not the only input, though: reading is granted to the responsible
	 * person as well, and that is a column of the row - so the write of the row invalidates the three too, and
	 * an activity that changed hands without changing its bindings would otherwise keep the decisions taken
	 * about the person it was on before.
	 *
	 * This is the one way to invalidate any of them (open question Q-4), and it has three callers, every one of
	 * them a step of a pipeline writing something a decision is derived from: the one saving the bindings, the one
	 * writing the row, and the one erasing the activity, which takes both inputs away at once. No storage boundary
	 * knows anything about rights, on purpose.
	 */
	public function forgetActivity(int $activityId): void
	{
		$this->decisionCache->forget($activityId);
		$this->bindingLoader->forget($activityId);
		$this->activityRepository->forget($activityId);
	}

	/**
	 * Whether the user may attach a new activity to this CRM object.
	 *
	 * Creating an activity is not an operation of its own: it is an update of the object the activity will
	 * belong to, and it is the caller who asks - the core write path checks no rights. This is the check to
	 * ask with.
	 */
	public function canAddToOwner(int $userId, ActivityBinding $owner): bool
	{
		return $this->hasOwnerPermission($userId, $owner, Operation::Update);
	}

	/**
	 * The order of the decision. Every step is named after the canonical algorithm and none of them may
	 * change places with another.
	 *
	 * THE FIRST STEP IS THE EXISTENCE OF THE ACTIVITY, and it stands above the verdict of the provider for the
	 * same reason it stands above the administrator bypass: a provider is typed by the model of an activity and
	 * has none to be typed by, so it states nothing about a row that is not there anyway. Asking the storage is
	 * cheap here - the memory of the hit answers for every id a batch preloaded, and it remembers an absence as
	 * a fact about the id ({@see RememberingActivityRepository::exists()}).
	 */
	private function resolve(int $userId, int $activityId, Operation $operation): AccessDecision
	{
		if (!$this->activityRepository->exists($activityId))
		{
			return AccessDecision::Deny;
		}

		$providerVerdict = $this->providerVerdict($userId, $activityId, $operation);
		if ($providerVerdict->hasOpinion())
		{
			return $providerVerdict;
		}

		if ($this->userPermissions($userId)->isAdmin())
		{
			return AccessDecision::Allow;
		}

		if ($operation === Operation::Read && $this->isResponsible($userId, $activityId))
		{
			return AccessDecision::Allow;
		}

		$bindings = $this->loadBindings($activityId);
		if ($bindings === [])
		{
			$owner = $this->syntheticBindingFromOwnerFields($activityId);
			$bindings = $owner === null ? [] : [$owner];
		}

		foreach ($bindings as $binding)
		{
			if ($this->hasOwnerPermission($userId, $binding, $operation))
			{
				return AccessDecision::Allow;
			}
		}

		return AccessDecision::Deny;
	}

	private function providerVerdict(int $userId, int $activityId, Operation $operation): AccessDecision
	{
		if ($this->providerVerdictResolver === null)
		{
			return AccessDecision::NoOpinion;
		}

		return ($this->providerVerdictResolver)($userId, $activityId, $operation);
	}

	/**
	 * Whether the activity is the user's own to look after. Compared as integers on purpose: legacy compared
	 * strictly in one place and loosely in another, and the difference was an accident.
	 */
	private function isResponsible(int $userId, int $activityId): bool
	{
		$activity = $this->activityRepository->getById($activityId, [Activity::responsibleId]);

		return $activity !== null && (int)$activity->getResponsibleId() === (int)$userId;
	}

	/** @return ActivityBinding[] */
	private function loadBindings(int $activityId): array
	{
		return $this->bindingLoader->getBindings($activityId);
	}

	/**
	 * The owner columns of the activity as one binding, for an activity that has no binding rows left.
	 * Null when there is no owner either - and then nothing grants the right.
	 */
	private function syntheticBindingFromOwnerFields(int $activityId): ?ActivityBinding
	{
		return $this->bindingLoader->getOwner($activityId);
	}

	/**
	 * Whether the user may do to the bound CRM object what the operation needs of it. Completing and
	 * postponing need what updating needs; the provider is what tells them apart, and it has already spoken.
	 */
	private function hasOwnerPermission(int $userId, ActivityBinding $owner, Operation $operation): bool
	{
		$itemPermissions = $this->userPermissions($userId)->item();
		$entityTypeId = $owner->getOwnerType()->getId();
		$entityId = $owner->getOwnerId();

		return match ($operation)
		{
			Operation::Read => $itemPermissions->canRead($entityTypeId, $entityId),
			Operation::Delete => $itemPermissions->canDelete($entityTypeId, $entityId),
			Operation::Update, Operation::Complete, Operation::Postpone
				=> $itemPermissions->canUpdate($entityTypeId, $entityId),
		};
	}

	private function userPermissions(int $userId): UserPermissions
	{
		return Container::getInstance()->getUserPermissions($userId);
	}

	/**
	 * @param Activity[] $activities
	 * @return int[]
	 */
	private function idsOf(array $activities): array
	{
		$ids = [];
		foreach ($activities as $activity)
		{
			$activityId = (int)$activity->getId();
			if ($activityId > 0)
			{
				$ids[] = $activityId;
			}
		}

		return $ids;
	}
}
