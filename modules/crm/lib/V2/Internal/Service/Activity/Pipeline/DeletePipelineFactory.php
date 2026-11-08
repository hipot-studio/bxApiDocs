<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline;

use Bitrix\Crm\V2\Internal\Service\Activity\ActivityLifecycleService;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Delete\CheckCallDeletionRestriction;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Delete\CleanupLivefeed;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Delete\DeleteCalendarEvent;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Delete\DeleteUserFields;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Delete\EnrichIncomingChannel;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Delete\InnerDelete;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Delete\LoadActivity;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Delete\LoadBindings;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Delete\MoveToRecycleBin;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Delete\NotifyCounterMonitor;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Delete\NotifyTimelineBeforeDelete;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Delete\NotifyTimelineOnDelete;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Delete\ProviderDeleteAssociatedEntity;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Delete\ProviderOnAfterDelete;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Delete\PublishOnActivityDelete;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Delete\PublishOnBeforeDelete;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Delete\SyncFastSearch;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Delete\SyncLightCounterAndReadModel;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Delete\UnregisterStatisticEntryAndChannels;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Delete\UnregisterStatistics;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Delete\WriteHistory;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\InvalidateTaggedCache;

/**
 * The declaration of the deletion of an activity: the whole owner table STEP-DELETE, in its order, and nothing else.
 *
 * THIS IS THE ONLY PLACE THE COMPOSITION OF THE DELETE EXISTS, exactly as {@see AddPipelineFactory} is for the creation
 * and {@see UpdatePipelineFactory} for the change. The engine holds no rule about any step and the steps hold no rule
 * about each other, so the order of the effects of legacy is readable here in one list and nowhere else - which is what
 * makes it comparable with the owner table item by item, and what the test of the composition reads. A step added,
 * removed or moved is a change of the behaviour of the operation and has to look like one.
 *
 * TWENTY-TWO ROWS FOR TWENTY-TWO NUMBERS. Row 19 - unregistering the record of the activity stream and the subscription
 * of an activity that was NOT transferred to the bin - is conditional and not dead. The registry row RECYCLE-ALWAYS-ON
 * says the setting of the module cannot switch the recycle bin off, and that is all it says: the transfer of row 5 also
 * needs the module of the bin to be installed, and it only counts where it succeeded. On a portal without that module,
 * and on a transfer that failed, the activity is erased for good - so row 19 runs there, exactly as legacy does.
 *
 * NO ROW OF A DELETE HAS A MODE. Unlike an add and an update, whose shared steps have to be told which operation they
 * serve, the one shared class a delete declares - the row clearing the tag of the managed cache - behaves the same in
 * every pipeline, so it takes no parameter at all. Everything else is a class of the delete itself.
 *
 * THE ONE ROW THAT ENDS THE OPERATION SUCCESSFULLY IS ROW 5. A transfer to the recycle bin whose provider reports the
 * row is already gone stops the run with a success ({@see PipelineStepInterface::STOP_WITH_SUCCESS}), and everything
 * below it - the erasing, every effect, both rows of the tail - simply does not happen. That is what keeps the event of
 * a deleted activity from being published about an activity that can still be restored: the row stands below, and
 * nothing about it has to say so.
 *
 * THE ORDER IS NOT NEGOTIABLE, and four of its stretches carry an invariant of their own:
 * - the event with a veto stands above the read of the row (row 1 above row 2): a subscriber may refuse the deletion of
 *   an activity that is not there and of one the acting user may not even read, which is the case the subscribers of a
 *   mass cleanup are written for;
 * - the timeline told beforehand, the erasing and the counter of the activity run in the order 7 -> 8 -> 10 (section 13
 *   item 6): the moment the counter of the activity turns light at is read after the row of the activity is gone and
 *   before the row of the counter is, and row 10 is what takes the latter away;
 * - the four rows in the middle run in the order 12 -> 13 -> 14 -> 15, which is the order of legacy at :1465,
 *   :1468-1476 and :1478-1479;
 * - the tail is rows 20 and 21 in that order, with the tag of the managed cache cleared below both: a third-party
 *   application learns about the removal from row 21, so the provider of the activity has always finished with it by
 *   then.
 *
 * WHY A FACTORY AND NOT A SERVICE. The lifecycle service takes a closure per operation so that a call of one operation
 * does not construct the steps of the other three; this is that closure for the delete, and the factory of the services
 * of the domain is where it is attached
 * ({@see \Bitrix\Crm\V2\Internal\Service\Activity\ActivityServiceFactory::createActivityLifecycleService()}). The
 * pipeline names itself after the operation, because the service refuses a declaration that names itself otherwise.
 *
 * @internal
 */
final class DeletePipelineFactory
{
	/**
	 * @param StepFailureLogger|null $failureLogger where the failures of the steps of the run are written; the default
	 *        is the logger of the module. A test running the whole pipeline hands over one of its own.
	 */
	public static function create(?StepFailureLogger $failureLogger = null): Pipeline
	{
		return new Pipeline(ActivityLifecycleService::OPERATION_DELETE, self::steps(), $failureLogger);
	}

	/**
	 * The rows of STEP-DELETE, in the order of the table. The numbers of the comments are the numbers of the owner
	 * table - see the note of the class.
	 *
	 * @return PipelineStepInterface[]
	 */
	private static function steps(): array
	{
		return [
			// 1 - 3: what may refuse the deletion, and the state it is refused over
			new PublishOnBeforeDelete(),
			new LoadActivity(),
			new CheckCallDeletionRestriction(),
			// 4 - 5: the marker of the incoming channel, and the transfer that may end the operation here
			new EnrichIncomingChannel(),
			new MoveToRecycleBin(),
			// 6 - 8: the set of bindings, the timeline told beforehand, and the erasing itself
			new LoadBindings(),
			new NotifyTimelineBeforeDelete(),
			new InnerDelete(),
			// 9 - 10: what is keyed by the activity, and the counter whose moment is read after the row is gone
			new DeleteUserFields(),
			new SyncLightCounterAndReadModel(),
			// 11 - 15: the history, the statistics of the owners, the projection, the calendar, the own registrations
			new WriteHistory(),
			new UnregisterStatistics(),
			new ProviderDeleteAssociatedEntity(),
			new DeleteCalendarEvent(),
			new UnregisterStatisticEntryAndChannels(),
			// 16 - 18: the timeline, the counters of the owners and the table of the fast search
			new NotifyTimelineOnDelete(),
			new NotifyCounterMonitor(),
			new SyncFastSearch(),
			// 19: what the activity stream holds about an activity that never reached the bin
			new CleanupLivefeed(),
			// 20 - 21 and the tag of the managed cache below both
			new ProviderOnAfterDelete(),
			new PublishOnActivityDelete(),
			new InvalidateTaggedCache(),
		];
	}
}
