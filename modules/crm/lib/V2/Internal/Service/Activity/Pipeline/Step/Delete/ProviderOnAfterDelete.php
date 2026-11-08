<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Delete;

use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Crm\V2\Internal\Service\Activity\Provider\Capability\LifecycleCapabilityInterface;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Tells the provider of the activity that an activity of its own kind is gone: row 20 of the owner table STEP-DELETE
 * (crm_activity.php:1512-1515), normative shape TPL-03.
 *
 * IT STANDS ABOVE THE EVENT OF THE WEBHOOKS AND THAT ORDER IS THE CONTRACT, the same way it is on an add and on an
 * update: a third-party application learns about the removal from row 21, so the provider of the activity has always
 * finished with it by then. The two rows are the whole tail of a delete - legacy performs nothing between them - and
 * the tag of the managed cache is cleared below both.
 *
 * THE HOOK DOES NOT VETO AND ITS ANSWER IS NOTHING. It is announced after the activity is already gone and returns
 * nothing at all ({@see LifecycleCapabilityInterface::onAfterDelete()}), so the step never fails past its one
 * precondition and never aborts: whatever a provider makes of the news, the activity has been deleted.
 *
 * THE GATE IS THE ONE OF LEGACY - AN ACTIVITY WITH A PROVIDER - AND THE CAPABILITY IS ASKED INSIDE. That is the
 * reading the row of an add settled on and the row of an update repeated: legacy calls the hook whenever it resolved a
 * provider at all, because the base class of its providers defines the method as a no-op, while in V2 a provider states
 * a rule of its own by implementing the capability. Every legacy provider stands behind the adapter, which implements
 * the capability for all of them, so the two readings agree for everything on the portal today.
 *
 * THE PROVIDER IS THE ONE ROW 2 RESOLVED, off the row it loaded, and every row of a delete below it asks
 * {@see PipelineContext::getProvider()} for it: a second resolution here would be a second answer to one question -
 * and by now there is no row left to resolve it from anyway.
 *
 * WHAT THE HOOK IS HANDED. The state of the activity as row 2 read it, with the title of its provider in the subject
 * where row 5 put one, and the whole context of the operation. Legacy hands over two arguments - the identifier and the
 * fields it fetched - and the model carries both of them; projecting the model back into the legacy array is the
 * business of the wrapper of a legacy provider and not of this row.
 *
 * @internal
 */
final class ProviderOnAfterDelete implements PipelineStepInterface
{
	/**
	 * The run reached this step with no activity, which the entry point of the service refuses to build. The hook is
	 * told about a deleted activity, and the wrapper of a legacy provider would hand a zero over as its id.
	 */
	public const ERROR_NO_ID = 'CRM_ACTIVITY_HAS_NO_ID';

	public function getName(): string
	{
		return 'ProviderOnAfterDelete';
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

		$provider = $context->getProvider();
		if ($provider instanceof LifecycleCapabilityInterface)
		{
			$provider->onAfterDelete($context->getActivity(), $context->getOperationContext());
		}

		return $result;
	}

	/**
	 * An activity whose provider could not be told is deleted all the same: legacy checks nothing here.
	 */
	public function isAbortingOnFailure(): bool
	{
		return false;
	}
}
