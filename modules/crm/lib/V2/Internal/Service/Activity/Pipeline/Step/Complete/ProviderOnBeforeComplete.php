<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Complete;

use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Crm\V2\Internal\Service\Activity\Provider\Capability\LifecycleCapabilityInterface;
use Bitrix\Crm\V2\Internal\Service\Activity\Provider\ProviderResolver;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Tells the provider of the activity that the activity is about to be completed: row 3 of the owner table
 * STEP-COMPLETE (crm_activity.php:4397-4405), normative shape TPL-03.
 *
 * THE HOOK HAS NO VETO AND ITS ANSWER IS THROWN AWAY (item H1). Legacy calls it and does not look at what came
 * back, so nothing a provider says here keeps the activity from being completed: a refusal aimed at one user is a
 * right and belongs to the permission capability instead. That is why the step never fails past its two
 * preconditions and never aborts.
 *
 * THE PROVIDER IS RESOLVED HERE AND FROM THE STATE ROW 1 READ. It is resolved from the stored PROVIDER_ID alone -
 * the select of legacy carries no TYPE_ID, so the fallback by the legacy type of the activity has nothing to read
 * and does not happen. The answer is left on the run because the record of a failing step below names the
 * provider of the operation, and an activity whose provider is unknown simply has none: legacy calls no hook then
 * either.
 *
 * WHAT THE HOOK IS HANDED IS THE STATE BEFORE THE COMPLETION, not the state being reached. Legacy passes the row
 * it has just read (`$fields`, :4402), whose completion is still the old one, and a provider deciding by that
 * flag has to keep seeing the old one. Handing over the model of the run would show it the completion the
 * operation is about to write - a value no legacy provider has ever seen at this point.
 *
 * AN AUTOMATIC COMPLETION DOES NOT REACH THIS ROW AT ALL (item H2). `SetAutoCompleted` of legacy calls no hook
 * before completing (:4419), and the entry point of the service expresses that the way legacy expresses it - by
 * the option skipping the handler, which is the condition of this row.
 *
 * @internal
 */
final class ProviderOnBeforeComplete implements PipelineStepInterface
{
	/**
	 * The run reached this step with no activity, which the entry point of the service refuses to build. The hook
	 * is told about a stored activity, and the wrapper of a legacy provider would hand a zero over as its id.
	 */
	public const ERROR_NO_ID = 'CRM_ACTIVITY_HAS_NO_ID';

	/**
	 * The run reached this step without the state row 1 reads, so there is neither a provider to resolve nor a
	 * state to announce.
	 */
	public const ERROR_PREVIOUS_STATE_NOT_LOADED = 'CRM_ACTIVITY_PREVIOUS_STATE_NOT_LOADED';

	private readonly ProviderResolver $resolver;

	/**
	 * @param ProviderResolver|null $resolver what answers which provider owns the activity that was read.
	 */
	public function __construct(?ProviderResolver $resolver = null)
	{
		$this->resolver = $resolver ?? new ProviderResolver();
	}

	public function getName(): string
	{
		return 'ProviderOnBeforeComplete';
	}

	/**
	 * The caller has not asked for the hook to be skipped. Whether there is a provider at all is answered inside:
	 * the resolution happens here, and a condition resolving it would resolve it twice.
	 */
	public function isApplicable(PipelineContext $context): bool
	{
		return !$context->getOperationContext()->shouldSkipBeforeHandler();
	}

	public function execute(PipelineContext $context): Result
	{
		$result = new Result();

		if ($context->getActivityId() <= 0)
		{
			return $result->addError(new Error('The activity has no id.', self::ERROR_NO_ID));
		}

		if (!$context->hasPreviousState())
		{
			return $result->addError(new Error(
				'The previous state of the activity was not loaded.',
				self::ERROR_PREVIOUS_STATE_NOT_LOADED,
			));
		}

		$stateBefore = $context->getPreviousActivity();
		$provider = $this->resolver->resolve($stateBefore->getProviderId());
		$context->setProvider($provider);

		if ($provider instanceof LifecycleCapabilityInterface)
		{
			$provider->onBeforeComplete($stateBefore, $context->getOperationContext());
		}

		return $result;
	}

	/**
	 * An activity whose provider could not be told is completed all the same: legacy checks nothing here.
	 */
	public function isAbortingOnFailure(): bool
	{
		return false;
	}
}
