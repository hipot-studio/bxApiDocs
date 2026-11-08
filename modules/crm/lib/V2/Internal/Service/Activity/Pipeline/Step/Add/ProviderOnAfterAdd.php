<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Add;

use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Crm\V2\Internal\Service\Activity\Provider\Capability\LifecycleCapabilityInterface;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Tells the provider of the activity that an activity of its own kind has been created: step 34 of the owner table
 * of an add (crm_activity.php:444-452), normative shape TPL-03.
 *
 * THE FIRST OF THE FOUR STEPS OF THE TAIL, AND THE ORDER OF THEM IS THE CONTRACT. The hook of the provider, then
 * the subscription every provider gets, then the entries of the waiting, then the event the webhooks are delivered
 * from (item C11 of the matrix, section 13 item 3 of LBS-1). A third-party application learns about a new activity
 * from the last of the four, so anything reordered here changes what its providers have already done by then.
 *
 * THE HOOK DOES NOT VETO AND ITS ANSWER IS NOTHING. It is announced after the write and returns nothing at all
 * ({@see LifecycleCapabilityInterface}), so the step never fails and never aborts: whatever a provider makes of
 * the news, the activity is stored.
 *
 * THE GATE IS THE ONE OF LEGACY - AN ACTIVITY WITH A PROVIDER - AND THE CAPABILITY IS ASKED INSIDE. Legacy calls
 * the hook whenever it resolved a provider at all, because the base class of its providers defines the method as a
 * no-op; in V2 a provider states a rule of its own by implementing the capability, and one that states none has
 * nothing to be called. Every legacy provider stands behind the adapter, which implements the capability for all
 * of them, so the two readings agree for everything on the portal today and the answer for a natively written
 * provider without lifecycle hooks is the no-op of the base class rather than a failure.
 *
 * THE RESTORATION NEEDS NO CARRIER HERE. Legacy hands the flag over inside the parameters of the hook; in V2 every
 * hook of a provider receives the whole context of the operation and reads the flag off it, so the step passes the
 * activity and the context and nothing else.
 *
 * @internal
 */
final class ProviderOnAfterAdd implements PipelineStepInterface
{
	/**
	 * There is no creation to announce before the row exists. Legacy cannot reach this state: it interrupts an add
	 * on a failed insert.
	 */
	public const ERROR_NOT_STORED = 'CRM_ACTIVITY_PROVIDER_ON_AFTER_ADD_WITHOUT_ACTIVITY';

	public function getName(): string
	{
		return 'ProviderOnAfterAdd';
	}

	/**
	 * An activity whose provider is known. See the note of the class on where the capability is asked.
	 */
	public function isApplicable(PipelineContext $context): bool
	{
		return $context->getProvider() !== null;
	}

	public function execute(PipelineContext $context): Result
	{
		$activityId = $context->getActivityId();
		if ($activityId <= 0)
		{
			return (new Result())->addError(new Error('The activity has not been stored.', self::ERROR_NOT_STORED));
		}

		$provider = $context->getProvider();
		if ($provider instanceof LifecycleCapabilityInterface)
		{
			$provider->onAfterAdd($context->getActivity(), $context->getOperationContext());
		}

		return new Result();
	}

	/**
	 * An activity whose provider could not be told is stored all the same: legacy checks nothing here.
	 */
	public function isAbortingOnFailure(): bool
	{
		return false;
	}
}
