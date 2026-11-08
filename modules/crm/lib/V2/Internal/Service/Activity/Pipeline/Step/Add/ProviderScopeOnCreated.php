<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Add;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Crm\V2\Internal\Service\Activity\Provider\Capability\ScopeOperationsCapabilityInterface;
use Bitrix\Crm\V2\Internal\Service\Activity\Provider\ProviderRegistryInterface;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Tells every provider that subscribed to it that an activity has been created, whoever owns that activity: step 35
 * of the owner table of an add (crm_activity.php:456-459, providermanager.php:221), normative shape TPL-09.
 *
 * THIS IS NOT THE HOOK OF THE PROVIDER OF THE ACTIVITY. The step above announces the creation to the one provider
 * the activity belongs to; this one announces it to everybody who wants to hear about any activity at all, which is
 * what legacy does by looping over its whole list of providers. The two are separate rows of the owner table and
 * the order of them is part of item C11.
 *
 * THE SUBSCRIPTION IS THE GATE, AND THE ADAPTER SUBSCRIBES EVERY LEGACY PROVIDER. Declaring the capability is how a
 * provider gets asked ({@see ScopeOperationsCapabilityInterface}); one adapter class stands behind all the legacy
 * providers and declares it for each of them, which is the decision of phase 4 - until they are migrated one cannot
 * tell from the outside which of them would have answered, so the observable "everybody is called" is kept. The set
 * of reactions is not widened by that: the one legacy override of the creation belongs to a provider that was never
 * registered, so the loop has always been empty of effects.
 *
 * THE COMPOSITION OF THE SUBSCRIBERS IS THE REGISTRY'S ANSWER AND NOT A TYPE CHECK OF THE OBJECTS. Asked by
 * capability, an unavailable provider is not among them and its reaction stops applying while it is off - the same
 * rule the locator of the permission capability of the hit is built on.
 *
 * @internal
 */
final class ProviderScopeOnCreated implements PipelineStepInterface
{
	/**
	 * There is no creation to announce before the row exists. Legacy cannot reach this state: it interrupts an add
	 * on a failed insert.
	 */
	public const ERROR_NOT_STORED = 'CRM_ACTIVITY_SCOPE_CREATION_WITHOUT_ACTIVITY';

	private ?ProviderRegistryInterface $registry;

	/**
	 * @param ProviderRegistryInterface|null $registry the registry the subscribers are asked of; the one seam of the
	 *        step. Resolved when the step is asked rather than when it is declared, so that building the pipeline
	 *        touches nothing.
	 */
	public function __construct(?ProviderRegistryInterface $registry = null)
	{
		$this->registry = $registry;
	}

	public function getName(): string
	{
		return 'ProviderScopeOnCreated';
	}

	/**
	 * Somebody subscribed to the creation of activities. Nobody having subscribed is a step that does not apply
	 * rather than a call into an empty loop.
	 */
	public function isApplicable(PipelineContext $context): bool
	{
		return $this->subscribers() !== [];
	}

	public function execute(PipelineContext $context): Result
	{
		$activityId = $context->getActivityId();
		if ($activityId <= 0)
		{
			return (new Result())->addError(new Error('The activity has not been stored.', self::ERROR_NOT_STORED));
		}

		foreach ($this->subscribers() as $subscriber)
		{
			$subscriber->onActivityCreated($context->getActivity(), $context->getOperationContext());
		}

		return new Result();
	}

	/**
	 * A provider that could not be told is no reason to fail an activity that is already stored: legacy checks the
	 * result of none of these calls.
	 */
	public function isAbortingOnFailure(): bool
	{
		return false;
	}

	/**
	 * The providers subscribed to the scoped operations, in the order the registry declares them - the order legacy
	 * loops in.
	 *
	 * @return ScopeOperationsCapabilityInterface[]
	 */
	private function subscribers(): array
	{
		$capability = ScopeOperationsCapabilityInterface::class;

		$subscribers = [];
		foreach ($this->registryOfProviders()->getByCapability($capability) as $provider)
		{
			if ($provider instanceof ScopeOperationsCapabilityInterface)
			{
				$subscribers[] = $provider;
			}
		}

		return $subscribers;
	}

	private function registryOfProviders(): ProviderRegistryInterface
	{
		return $this->registry ??= Container::getInstance()->getActivityProviderRegistry();
	}
}
