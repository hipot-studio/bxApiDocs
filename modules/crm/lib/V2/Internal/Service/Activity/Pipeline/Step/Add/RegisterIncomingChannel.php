<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Add;

use Bitrix\Crm\Activity\IncomingChannel;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Main\Result;

/**
 * Registers the activity as a channel somebody reached the company through: step 13 of the owner table of an add
 * (crm_activity.php:227-231).
 *
 * WHAT THE REGISTRY IS FOR. An incoming call, a letter or a message that nobody has answered yet is what the
 * counters of a CRM object call an incoming channel, and the registry is the read model behind that: it holds the
 * activity, the person responsible for answering and whether the answer has happened. Everything of it that can
 * change is what is written here, so the row is an upsert rather than an insert - which is what makes the same
 * call from the pipeline of an update the natural continuation of this one.
 *
 * THE MARKER IS THE ONE OF THE CALLER. Legacy asks a column of the fields it was given (`IS_INCOMING_CHANNEL`
 * being exactly `'Y'`), and so does this: the flag is a field of the model, and an activity that says nothing
 * about it is no incoming channel. The registry is not asked to derive it - the two facts written beside it, the
 * responsible person and the completion, are read from the model as well.
 *
 * @internal
 */
final class RegisterIncomingChannel implements PipelineStepInterface
{
	public function getName(): string
	{
		return 'RegisterIncomingChannel';
	}

	/**
	 * An activity the caller marked as an incoming channel, and one the storage has already named: a row of the
	 * registry names the activity it is about.
	 */
	public function isApplicable(PipelineContext $context): bool
	{
		if ($context->getActivity()->isIncomingChannel() !== true)
		{
			return false;
		}

		return $context->getActivityId() > 0;
	}

	public function execute(PipelineContext $context): Result
	{
		$activity = $context->getActivity();

		IncomingChannel::getInstance()->register(
			$context->getActivityId(),
			(int)$activity->getResponsibleId(),
			$activity->isCompleted() === true,
		);

		return new Result();
	}

	/**
	 * An activity whose channel could not be registered is stored all the same: legacy checks nothing here.
	 */
	public function isAbortingOnFailure(): bool
	{
		return false;
	}
}
