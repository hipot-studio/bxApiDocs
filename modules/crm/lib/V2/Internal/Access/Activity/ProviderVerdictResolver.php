<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Access\Activity;

use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Crm\V2\Internal\Entity\Activity\ActivityBinding;
use Bitrix\Crm\V2\Internal\Repository\Activity\ActivityRepositoryInterface;
use Bitrix\Crm\V2\Internal\Repository\Activity\RememberingActivityRepository;
use Bitrix\Crm\V2\Internal\Service\Activity\Provider\Capability\PermissionCapabilityInterface;
use Bitrix\Crm\V2\Internal\Service\Activity\Provider\LegacyProviderAdapter;
use Bitrix\Crm\V2\Public\OwnerType;
use Closure;

/**
 * Asks the provider of an activity what it thinks about an access question and answers with one of the
 * three outcomes the first step of {@see ActivityAccess} expects.
 *
 * WHY ANYTHING SITS BETWEEN THE TWO. The capability of a provider is typed by the model of an activity
 * ({@see PermissionCapabilityInterface}), while whoever asks about rights knows an activity by its id. The
 * model is loaded here, through the same repository the access rules read with, so that a batch preload of
 * activities serves both and no second pass over the table appears. The access rules stay independent of
 * the provider layer this way: they hand out the question and take back a verdict.
 *
 * THE ROW IS READ ONCE PER HIT, AND THAT TAKES THE VERY INSTANCE THE ACCESS RULES HOLD
 * ({@see RememberingActivityRepository}, handed over by {@see \Bitrix\Crm\Service\Container}). The five
 * operations are asked about one activity one after another and every one of them needs the model; a
 * repository of its own here would be a second pass over `b_crm_act`. A resolver assembled without one builds
 * a memory of its own, so it costs a read per activity and not a read per question - a memory nobody shares.
 *
 * WHAT IS READ IS THE WHOLE ROW, except for a reading of a preloaded batch. The model is handed to the
 * capability of the provider, and for every provider still written as a legacy class that means the whole
 * field array ({@see \Bitrix\Crm\V2\Internal\Service\Activity\Provider\LegacyProviderAdapter}): the hooks
 * read what they read, and a narrower projection would answer some of them with null instead of a value - a
 * different decision about rights, taken silently. Only the capability gets it, though: which provider to
 * ask is found by the provider alone, so an activity whose provider has no rule of its own costs no whole
 * row here.
 *
 * A QUESTION OF READING ABOUT AN ACTIVITY {@see self::preload()} READ FOR is answered on the model read under
 * {@see self::READING_PROJECTION}: the read hooks of the legacy providers read the responsible person of the
 * model and, past the model, the columns the preload has read already - the owner of the row and its
 * associated entity. A batch of candidates thus reads no text of an activity. A provider with a rule that
 * needs more of the model for reading widens the projection. Every other question - another operation, an
 * activity not preloaded - gets the whole row.
 *
 * NO CAPABILITY MEANS NO OPINION, and that is a decision rather than a fallback. It covers three cases at
 * once: a provider with no permission rule of its own, a legacy provider that overrides no read or update
 * hook - its `Provider\Base` default is the core check restated, never an opinion - and a provider that is
 * switched off, whose tightenings stop applying while it is. In all three the question falls through to
 * the rules of the core, which is the only reading under which the check over the bindings of an activity
 * is ever reached.
 *
 * HOW IT IS HANDED OVER: `new ActivityAccess($activities, $bindings, $resolver->resolve(...))`, and the resolver
 * itself as the last argument where a batch is read for reading ({@see self::preload()}).
 *
 * @internal
 */
final class ProviderVerdictResolver
{
	/** What the locator of a capability is asked with: the provider of the activity. */
	private const PROVIDER_FIELDS = [Activity::id, Activity::providerId];

	/** What a rule of a provider is handed of the model to decide about reading a preloaded activity. */
	public const READING_PROJECTION = [Activity::id, Activity::providerId, Activity::responsibleId];

	private readonly RememberingActivityRepository $activityRepository;

	private readonly ?Closure $capabilityLocator;

	/**
	 * @var array<int, PermissionCapabilityInterface> the capability asked about a preloaded activity, by its id;
	 *      a legacy adapter holds the columns preloaded for it
	 */
	private array $preloadedBy = [];

	/** @var array<int, int> the associated entity the preloaded columns name, by activity id */
	private array $associatedEntities = [];

	/**
	 * @param RememberingActivityRepository|null $activityRepository
	 *        the rows of the hit, and the memory of them is part of what is asked for: the same object serves
	 *        the access rules and this resolver, which is the whole reason a row is read once. Null builds one
	 *        over the default storage boundary; a repository without a memory is handed to THAT constructor and
	 *        not to this one. The type says so rather than promising {@see ActivityRepositoryInterface}: a
	 *        double given by the interface would be silently wrapped here and never asked anything.
	 * @param Closure|null $capabilityLocator
	 *        `function (Activity $activity): ?PermissionCapabilityInterface` - the permission capability of
	 *        the provider of this activity, or null when it has none. The registry of providers is what
	 *        answers that in the end, and a locator aware of an unavailable provider is what keeps a
	 *        switched-off one from being asked. Without a locator no provider states anything.
	 */
	public function __construct(
		?RememberingActivityRepository $activityRepository = null,
		?Closure $capabilityLocator = null,
	)
	{
		$this->activityRepository = $activityRepository ?? new RememberingActivityRepository();
		$this->capabilityLocator = $capabilityLocator;
	}

	/**
	 * The verdict of the provider: {@see AccessDecision::Allow}, {@see AccessDecision::Deny} or
	 * {@see AccessDecision::NoOpinion}. An activity no row answers for is nothing to ask about, so nothing
	 * is stated about it either - and the rules of the core refuse it on their own.
	 */
	public function resolve(int $userId, int $activityId, Operation $operation): AccessDecision
	{
		if ($this->capabilityLocator === null)
		{
			return AccessDecision::NoOpinion;
		}

		$head = $this->activityRepository->getById($activityId, self::PROVIDER_FIELDS);
		if ($head === null)
		{
			return AccessDecision::NoOpinion;
		}

		$capability = ($this->capabilityLocator)($head);
		if ($capability === null)
		{
			return AccessDecision::NoOpinion;
		}

		$activity = $operation === Operation::Read && isset($this->preloadedBy[$activityId])
			? $this->activityRepository->getById($activityId, self::READING_PROJECTION)
			: $this->activityRepository->getById($activityId)
		;
		if ($activity === null)
		{
			return AccessDecision::NoOpinion;
		}

		return $capability->decide($userId, $operation, $activity);
	}

	/**
	 * Reads for these activities, in one query whatever their providers, what a rule of a provider reads per
	 * activity on its own: the columns a legacy hook is handed past the model
	 * ({@see LegacyProviderAdapter::fetchPermissionFields()}), and hands every adapter the columns of its
	 * activities. Only the legacy adapter reads anything of the kind, so no other capability is asked; the
	 * activities of every capability are answered about reading on the narrow model from then on
	 * ({@see self::READING_PROJECTION}). The heads of the activities are read through the memory of the hit, which
	 * a batch has warmed already. What is preloaded and not forgotten yet is not read again.
	 *
	 * @param int[] $activityIds
	 * @return array<int, ActivityBinding> the owners of the rows just read, by activity id: the same columns name
	 *         them, so nobody reads them apart. A row that names no owner is not among them.
	 */
	public function preload(array $activityIds): array
	{
		if ($this->capabilityLocator === null || $activityIds === [])
		{
			return [];
		}

		$adapters = [];
		foreach ($this->activityRepository->getByIds($activityIds, self::PROVIDER_FIELDS) as $activityId => $head)
		{
			if (isset($this->preloadedBy[(int)$activityId]))
			{
				continue;
			}

			$capability = ($this->capabilityLocator)($head);
			if ($capability instanceof LegacyProviderAdapter)
			{
				$adapters[(int)$activityId] = $capability;
			}
			elseif ($capability !== null)
			{
				$this->preloadedBy[(int)$activityId] = $capability;
			}
		}
		if ($adapters === [])
		{
			return [];
		}

		$rows = LegacyProviderAdapter::fetchPermissionFields(array_keys($adapters));
		$rowsByAdapter = [];
		$owners = [];
		foreach ($adapters as $activityId => $adapter)
		{
			$row = $rows[$activityId] ?? null;
			$rowsByAdapter[spl_object_id($adapter)][$activityId] = $row;
			$this->preloadedBy[$activityId] = $adapter;
			$this->associatedEntities[$activityId] = (int)($row['ASSOCIATED_ENTITY_ID'] ?? 0);

			$owner = $row === null ? null : self::ownerOf($row);
			if ($owner !== null)
			{
				$owners[$activityId] = $owner;
			}
		}
		foreach ($rowsByAdapter as $activityRows)
		{
			$this->preloadedBy[array_key_first($activityRows)]->rememberPermissionFields($activityRows);
		}

		return $owners;
	}

	/**
	 * Forgets what {@see self::preload()} read for this activity. Nothing about it has changed: a reader drops it
	 * to keep the memory of the hit from growing with every activity it passed by.
	 */
	public function forget(int $activityId): void
	{
		$capability = $this->preloadedBy[$activityId] ?? null;
		if ($capability instanceof LegacyProviderAdapter)
		{
			$capability->forgetPermissionFields([$activityId]);
		}
		unset($this->preloadedBy[$activityId], $this->associatedEntities[$activityId]);
	}

	/**
	 * Forgets what {@see self::preload()} read for every activity: the reading it was read for is over, and a
	 * question asked later reads the row anew.
	 */
	public function forgetPreloaded(): void
	{
		foreach (array_keys($this->preloadedBy) as $activityId)
		{
			$this->forget($activityId);
		}
	}

	/**
	 * The activities of these whose rule answers a question of reading itself, so the order of the decision never
	 * reaches their bindings: the preloaded ones of a legacy provider with a read hook of its own, which states
	 * "yes" or "no" and nothing else. The opinion of any other capability is known only by asking.
	 *
	 * @param int[] $activityIds
	 * @return int[]
	 */
	public function answeredAboutReading(array $activityIds): array
	{
		$answered = [];
		foreach ($activityIds as $activityId)
		{
			$capability = $this->preloadedBy[(int)$activityId] ?? null;
			if ($capability instanceof LegacyProviderAdapter && $capability->hasOwnReadPermissionRule())
			{
				$answered[] = (int)$activityId;
			}
		}

		return $answered;
	}

	/**
	 * The associated entities of these activities, as the columns {@see self::preload()} read name them, grouped by
	 * the legacy class of the rule that reads them: what a rule checks besides the owner of the row - the document
	 * a signing rule reads the rights of, the call list a rule of a call list reads. An activity not preloaded and
	 * one without an associated entity are left out.
	 *
	 * @param int[] $activityIds
	 * @return array<string, int[]> the legacy class => the identifiers of the associated entities, each once
	 */
	public function associatedEntitiesOf(array $activityIds): array
	{
		$entities = [];
		foreach ($activityIds as $activityId)
		{
			$activityId = (int)$activityId;
			$entityId = $this->associatedEntities[$activityId] ?? 0;
			$adapter = $this->preloadedBy[$activityId] ?? null;
			if ($entityId > 0 && $adapter instanceof LegacyProviderAdapter)
			{
				$entities[$adapter->getLegacyClassName()][$entityId] = $entityId;
			}
		}

		return array_map(array_values(...), $entities);
	}

	/**
	 * The owner the columns of a row name, or null when they name none - the reading of
	 * {@see ActivityRepositoryInterface::getOwners()}.
	 *
	 * @param array<string, mixed> $row
	 */
	private static function ownerOf(array $row): ?ActivityBinding
	{
		$ownerId = (int)($row['OWNER_ID'] ?? 0);
		$ownerTypeId = (int)($row['OWNER_TYPE_ID'] ?? 0);
		if ($ownerId <= 0 || $ownerTypeId <= 0)
		{
			return null;
		}

		return new ActivityBinding(OwnerType::fromId($ownerTypeId), $ownerId);
	}
}
