<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Update;

use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Crm\V2\Internal\Service\Activity\Provider\Capability\LifecycleCapabilityInterface;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Tells the provider of the activity that the activity has been changed: row 46 of the owner table STEP-UPDATE
 * (crm_activity.php:1188-1196), normative shape TPL-03.
 *
 * THE SECOND OF THE FOUR ROWS OF THE TAIL, AND THE ORDER OF THEM IS THE CONTRACT (row D12). The user fields, then
 * the hook of the provider, then the two events of the tail, then the tag of the managed cache. A third-party
 * application learns about the change from the events below, so a provider has always finished with it by then;
 * moving this row under them would deliver a webhook about an activity its own provider has not seen yet.
 *
 * THE GATE IS THE ONE OF LEGACY - AN ACTIVITY WITH A PROVIDER - AND THE CAPABILITY IS ASKED INSIDE. That is the
 * reading the row of an add settled on (erratum 2 of package 15 of this phase) and nothing about an update changes
 * it: legacy calls the hook whenever it resolved a provider at all, because the base class of its providers
 * defines the method as a no-op, while in V2 a provider states a rule of its own by implementing the capability.
 * Every legacy provider stands behind the adapter, which implements the capability for all of them, so the two
 * readings agree for everything on the portal today.
 *
 * THE PROVIDER IS THE ONE THE RUN RESOLVED, and on an update that is the provider of the state the activity was in
 * (item B8). Legacy resolves it here off the row it re-read (:1189 uses the same variable it resolved at :503), and
 * the two can only differ for an update that changes the provider of an activity. A second resolution inside this
 * step would be a second answer to one question - the same reasoning row 38 states.
 *
 * WHAT THE HOOK IS HANDED, and none of it is assembled here: the activity of the run, the set of changes, the whole
 * context of the operation and the state the operation wrote
 * ({@see LifecycleCapabilityInterface::onAfterUpdate()}). Legacy hands over four arguments - the identifier, the
 * fields being written, the previous state and the re-read one - and the contract of V2 carries the same four
 * answers in another shape: the model of the run carries the identifier and the current state, the set of changes
 * carries what legacy expresses by the difference of its two snapshots, the context carries the options, and the
 * model of the input is the fields being written. Projecting all of it back into the four legacy arrays is the
 * business of the wrapper of a legacy provider and not of this row.
 *
 * THE FIELDS BEING WRITTEN COME OFF THE INPUT AND NOT OFF THE STATE OF THE RUN, exactly as they do for the
 * subscribers of row 47 ({@see PublishOnActivityUpdate}), and for the same reason: past the re-read of row 17 the
 * state of the run is the row, and a model hydrated from a row marks no field as changed - so a projection over it
 * would answer "the update wrote nothing" for every core column. The model of the input is the one the rows above
 * the write worked on ({@see PipelineContext::getInputActivity()}), which is why the escaped shape of the two texts
 * reaches a provider here just as it reaches a subscriber below.
 *
 * THE HOOK CANNOT REFUSE, so the step never fails past its two preconditions and never aborts: whatever a provider
 * makes of the news, the activity has been written.
 *
 * @internal
 */
final class ProviderOnAfterUpdate implements PipelineStepInterface
{
	/**
	 * The run reached this step with no activity, which the entry point of the service refuses to build. The hook
	 * is told about a stored activity, and the wrapper of a legacy provider would hand a zero over as its id.
	 */
	public const ERROR_NO_ID = 'CRM_ACTIVITY_HAS_NO_ID';

	/**
	 * The run reached this step without the state the activity was in, so the set of changes it would hand over
	 * says nothing changed - and a provider acts on that. The same refusal row 38 makes, for the same reason.
	 */
	public const ERROR_PREVIOUS_STATE_NOT_LOADED = 'CRM_ACTIVITY_PREVIOUS_STATE_NOT_LOADED';

	public function getName(): string
	{
		return 'ProviderOnAfterUpdate';
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

		$provider = $context->getProvider();
		if ($provider instanceof LifecycleCapabilityInterface)
		{
			$provider->onAfterUpdate(
				$context->getActivity(),
				$context->getChanges(),
				$context->getOperationContext(),
				$context->getInputActivity(),
			);
		}

		return $result;
	}

	/**
	 * An activity whose provider could not be told is written all the same: legacy checks nothing here.
	 */
	public function isAbortingOnFailure(): bool
	{
		return false;
	}
}
