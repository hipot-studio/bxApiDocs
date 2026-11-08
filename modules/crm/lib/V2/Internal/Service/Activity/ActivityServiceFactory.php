<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity;

use Bitrix\Crm\V2\Internal\Access\Activity\ActivityAccess;
use Bitrix\Crm\V2\Internal\Access\Activity\BindingAttributeLoader;
use Bitrix\Crm\V2\Internal\Access\Activity\CallDeletionRestrictionRule;
use Bitrix\Crm\V2\Internal\Access\Activity\DecisionCache;
use Bitrix\Crm\V2\Internal\Access\Activity\ProviderVerdictResolver;
use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Crm\V2\Internal\Repository\Activity\ActivityBindingRepository;
use Bitrix\Crm\V2\Internal\Repository\Activity\ActivityRepository;
use Bitrix\Crm\V2\Internal\Repository\Activity\RememberingActivityRepository;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\AddPipelineFactory;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\CompletePipelineFactory;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\DeletePipelineFactory;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\UpdatePipelineFactory;
use Bitrix\Crm\V2\Internal\Service\Activity\Provider\Capability\PermissionCapabilityInterface;
use Bitrix\Crm\V2\Internal\Service\Activity\Provider\ProviderRegistryInterface;
use Closure;

/**
 * What the services of the domain of activities are built of: the access model, the lifecycle service and the
 * collaborators they share.
 *
 * WHY THE WIRING LIVES HERE AND NOT IN THE CONTAINER OF THE MODULE. What an object of this domain consists of is
 * knowledge of the domain and changes with it. The container of the module keeps the lifetime of a service - one
 * instance per hit - and asks this factory for the object itself, so a collaborator added or replaced below is a
 * change of one namespace.
 *
 * @internal
 */
final class ActivityServiceFactory
{
	/**
	 * ONE REPOSITORY WITH A MEMORY OF THE HIT FOR ALL OF THEM. The row of an activity is what the verdict of
	 * its provider is typed by and what the bypass of the responsible person compares, so every collaborator
	 * below reads it; a repository per collaborator would be a pass over `b_crm_act` per collaborator, and a
	 * batch preload would warm a memory nobody else looks into.
	 *
	 * @param ProviderRegistryInterface $providerRegistry the single registry of the providers of the hit, handed
	 *        over by the caller: the factory builds the graph and resolves no service of its own.
	 */
	public function createActivityAccess(ProviderRegistryInterface $providerRegistry): ActivityAccess
	{
		$activityRepository = new RememberingActivityRepository(new ActivityRepository());
		$bindingRepository = new ActivityBindingRepository($activityRepository);
		$providerVerdictResolver = new ProviderVerdictResolver(
			$activityRepository,
			$this->createActivityPermissionCapabilityLocator($providerRegistry),
		);

		return new ActivityAccess(
			$activityRepository,
			$bindingRepository,
			$providerVerdictResolver->resolve(...),
			new CallDeletionRestrictionRule($bindingRepository),
			new DecisionCache(),
			new BindingAttributeLoader($activityRepository, $bindingRepository),
		);
	}

	/**
	 * The declarations of the pipelines are attached here as the phase adds them, each as the closure building
	 * it: an operation whose declaration is not attached yet refuses to run, and a declaration that is attached
	 * is never built until that operation is called.
	 */
	public function createActivityLifecycleService(): ActivityLifecycleService
	{
		return new ActivityLifecycleService(
			addPipelineFactory: AddPipelineFactory::create(...),
			updatePipelineFactory: UpdatePipelineFactory::create(...),
			deletePipelineFactory: DeletePipelineFactory::create(...),
			completePipelineFactory: CompletePipelineFactory::create(...),
		);
	}

	/**
	 * `function (Activity $activity): ?PermissionCapabilityInterface` - the permission rule of the provider of an
	 * activity, or null when it has none.
	 *
	 * THE DECLARATION OF THE REGISTRY DECIDES, NOT THE TYPE OF THE OBJECT. One adapter class stands behind every
	 * legacy provider and implements every capability, so asking it what it is would answer the same for all of
	 * them and the rule "a provider left on the defaults of `Provider\Base` states no opinion" would be lost.
	 * Asking the registry by capability keeps both that rule and the one about a provider switched off: an
	 * unavailable provider is not among the answers, so its tightenings stop applying while it is off.
	 */
	private function createActivityPermissionCapabilityLocator(ProviderRegistryInterface $registry): Closure
	{
		return static function (Activity $activity) use ($registry): ?PermissionCapabilityInterface {
			$providerId = (string)$activity->getProviderId();
			if ($providerId === '')
			{
				return null;
			}

			$provider = $registry->getByCapability(PermissionCapabilityInterface::class)[$providerId] ?? null;

			return $provider instanceof PermissionCapabilityInterface ? $provider : null;
		};
	}
}
