<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline;

use Bitrix\Crm\V2\Internal\Service\Activity\ActivityLifecycleService;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Complete\LoadStateForComplete;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Complete\ProviderOnBeforeComplete;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Complete\RunUpdatePipeline;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Complete\SkipIfAlreadyInState;

/**
 * The declaration of the completion of an activity: the whole owner table STEP-COMPLETE, in its order, and nothing
 * else.
 *
 * THIS IS THE ONLY PLACE THE COMPOSITION OF THE COMPLETE EXISTS, exactly as {@see AddPipelineFactory} is for the
 * creation, {@see UpdatePipelineFactory} for the change and {@see DeletePipelineFactory} for the removal. The engine
 * holds no rule about any step and the steps hold no rule about each other, so the order of the effects of legacy is
 * readable here in one list and nowhere else - which is what makes it comparable with the owner table item by item,
 * and what the test of the composition reads.
 *
 * FOUR ROWS, AND THAT IS THE POINT OF THE OPERATION. A complete is a specialization of an update by the change of one
 * flag, with the same set of effects: the read the two questions of a completion are asked of, the early exit where
 * there is nothing to change, the hook of the provider, and the update that does the writing. Every effect of a
 * completion - the counters, the timeline, the notification of the responsible, the events, the hooks after the write
 * - belongs to the declaration of the update and is not repeated here.
 *
 * NEITHER THE STATUS OF THE COMPLETION NOR THE RIGHTS ARE THE BUSINESS OF THIS LIST. The status is derived by the
 * check of the fields of an update from the state before the write (item B3), which is what keeps an automatically
 * completed activity automatically completed - a row of a complete writing it would overwrite exactly that. The
 * rights are checked by the caller (item B4): legacy checks none here, and neither does this declaration. Postponing
 * an activity is no operation of the service at all (item H3 of LBS-1) and has no pipeline.
 *
 * THE ONE ROW THAT ENDS THE OPERATION SUCCESSFULLY IS ROW 2. An activity already in the state asked for stops the run
 * with a success ({@see PipelineStepInterface::STOP_WITH_SUCCESS}), and neither the hook nor the update happens - so
 * a second completion of a completed activity writes nothing, publishes nothing and recomputes nothing.
 *
 * THE ORDER IS NOT NEGOTIABLE, and two of its stretches carry an invariant of their own:
 * - the read stands above both questions asked of it: row 2 compares the stored flag with the one the operation
 *   wants, and row 3 resolves the provider from the stored PROVIDER_ID;
 * - the hook stands above the write and only above it, because that is what makes it a hook BEFORE a completion; it
 *   has no veto, so an interruption is never what keeps the write from happening.
 *
 * NO ROW OF A COMPLETE IS SHARED AND NONE HAS A MODE. All four are classes of the complete itself: unlike an add and
 * an update, which have to tell eight of their shared classes which operation they serve, a complete declares no
 * shared class at all - the effects those classes serve are performed by the update row 4 runs.
 *
 * WHY A FACTORY AND NOT A SERVICE. The lifecycle service takes a closure per operation so that a call of one
 * operation does not construct the steps of the other three; this is that closure for the complete, and the factory
 * of the services of the domain is where it is attached
 * ({@see \Bitrix\Crm\V2\Internal\Service\Activity\ActivityServiceFactory::createActivityLifecycleService()}). The
 * pipeline names itself after the operation, because the service refuses a declaration that names itself otherwise.
 *
 * @internal
 */
final class CompletePipelineFactory
{
	/**
	 * @param StepFailureLogger|null $failureLogger where the failures of the steps of the run are written; the default
	 *        is the logger of the module. A test running the whole pipeline hands over one of its own.
	 */
	public static function create(?StepFailureLogger $failureLogger = null): Pipeline
	{
		return new Pipeline(ActivityLifecycleService::OPERATION_COMPLETE, self::steps(), $failureLogger);
	}

	/**
	 * The rows of STEP-COMPLETE, in the order of the table. The numbers of the comments are the numbers of the owner
	 * table.
	 *
	 * @return PipelineStepInterface[]
	 */
	private static function steps(): array
	{
		return [
			// 1 - 2: the state the two questions of a completion are asked of, and the one that may end the run here
			new LoadStateForComplete(),
			new SkipIfAlreadyInState(),
			// 3 - 4: the provider told beforehand, and the update that writes the completion
			new ProviderOnBeforeComplete(),
			new RunUpdatePipeline(),
		];
	}
}
