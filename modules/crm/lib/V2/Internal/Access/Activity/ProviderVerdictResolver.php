<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Access\Activity;

use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Crm\V2\Internal\Repository\Activity\ActivityRepositoryInterface;
use Bitrix\Crm\V2\Internal\Repository\Activity\RememberingActivityRepository;
use Bitrix\Crm\V2\Internal\Service\Activity\Provider\Capability\PermissionCapabilityInterface;
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
 * WHAT IS READ IS THE WHOLE ROW, deliberately. The model is handed to the capability of the provider, and
 * for every provider still written as a legacy class that means the whole field array
 * ({@see \Bitrix\Crm\V2\Internal\Service\Activity\Provider\LegacyProviderAdapter}): the hooks read what
 * they read, and a narrower projection would answer some of them with null instead of a value - a
 * different decision about rights, taken silently.
 *
 * NO CAPABILITY MEANS NO OPINION, and that is a decision rather than a fallback. It covers three cases at
 * once: a provider with no permission rule of its own, a legacy provider that overrides no read or update
 * hook - its `Provider\Base` default is the core check restated, never an opinion - and a provider that is
 * switched off, whose tightenings stop applying while it is. In all three the question falls through to
 * the rules of the core, which is the only reading under which the check over the bindings of an activity
 * is ever reached.
 *
 * HOW IT IS HANDED OVER: `new ActivityAccess($activities, $bindings, $resolver->resolve(...))`.
 *
 * @internal
 */
final class ProviderVerdictResolver
{
	private readonly RememberingActivityRepository $activityRepository;

	private readonly ?Closure $capabilityLocator;

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

		$activity = $this->activityRepository->getById($activityId);
		if ($activity === null)
		{
			return AccessDecision::NoOpinion;
		}

		$capability = ($this->capabilityLocator)($activity);

		return $capability?->decide($userId, $operation, $activity) ?? AccessDecision::NoOpinion;
	}
}
