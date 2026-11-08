<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline;

use Bitrix\Crm\V2\Internal\Service\Activity\ActivityLifecycleService;
use Bitrix\Crm\V2\Internal\Service\Activity\Legacy\LivefeedBridge;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\BuildSearchContent;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\CompressEmailDescription;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\EncodeEmoji;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\InvalidateTaggedCache;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\MaterializeProviderStatistics;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\NotifyResponsibleViaIm;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\PrepareStoragePayload;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\RegisterActivityStatisticEntry;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\SaveBindings;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\SaveCommunications;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\SaveStorageElements;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\SaveUserFields;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\ValidateAndNormalize;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Update\CascadeThread;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Update\DiffDeadline;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Update\DiffNotify;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Update\DiffScalars;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Update\EnrichIncomingChannel;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Update\InvalidateItemCache;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Update\LoadPreviousState;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Update\MoveSonetSubscription;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Update\NotifyCounterMonitor;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Update\NotifyTimelineOnModify;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Update\OnCompletedChanged;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Update\OnResponsibleChanged;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Update\ProviderOnAfterUpdate;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Update\ProviderUpdateAssociatedEntity;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Update\PublishOnActivityUpdate;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Update\PublishOnBeforeUpdate;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Update\RebindMailBody;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Update\RegisterEntityStatisticsByCurrentDeadline;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Update\RegisterEntityStatisticsByPrevDeadline;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Update\ReloadCurrentState;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Update\ResolveOwnerFromBindings;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Update\ResolvePingOffsets;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Update\ResolveThread;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Update\ResolveUserContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Update\SnapshotPreviousBindings;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Update\SyncBadges;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Update\SyncCalendarEvent;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Update\SyncChannelBinding;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Update\SyncFastSearch;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Update\SyncIncomingChannel;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Update\SyncLightCounter;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Update\SyncPingQueue;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Update\SyncUncompletedAggregate;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Update\UpdateActivity;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Update\UpdateCommunicationStatistics;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Update\WriteHistory;

/**
 * The declaration of the update of an activity: the whole owner table STEP-UPDATE, in its order, and nothing else.
 *
 * THIS IS THE ONLY PLACE THE COMPOSITION OF THE UPDATE EXISTS, exactly as {@see AddPipelineFactory} is for the
 * creation. The engine holds no rule about any step and the steps hold no rule about each other, so the order of the
 * effects of legacy is readable here in one list and nowhere else - which is what makes it comparable with the owner
 * table item by item, and what the test of the composition reads. A step added, removed or moved is a change of the
 * behaviour of the operation and has to look like one.
 *
 * WRITE, RE-READ, THEN THE EFFECTS. The model of an update is not the model of an add (item D1): the write stands in
 * the middle of the list, the row is read back right below it, and every question of every step under that point is
 * asked of the re-read state and of the set of changes derived from it. That is why the snapshot of the bindings
 * stands above the write and the differs of the `Diff` family below the re-read, and neither may move across it.
 *
 * THE ORDER IS NOT NEGOTIABLE, and six of its stretches carry an invariant of their own:
 * - the compression of the description of a letter and the escaping of the emoji stand ABOVE the event before the
 *   write - the inversion of the add (item D5), and the one place where a subscriber of an update sees a text an
 *   add would have shown it uncompressed;
 * - the snapshot of the previous bindings stands above the write, because after it the table of the bindings no
 *   longer holds what the activity was bound to (section 13 item 4);
 * - the re-read of the row stands above the write of the bindings, the differs and everything below them (item D1);
 * - the four rows of the incoming channel run in the order 20, 21, 22, 23: the enrichment is unconditional and the
 *   three branches below it are mutually exclusive (item D3), and the differ of the single-valued fields stands
 *   below all four because the block owns the question "did the responsible change";
 * - the light counter is recomputed above every reader of the moment it used to turn light at - the aggregate of
 *   the uncompleted activities, the monitor of the counters and the record of the timeline (section 13 item 5);
 * - the tail of the four rows 45 to 48 is item D12: the user fields, the hook of the provider, the two events, and
 *   the tag of the managed cache cleared last of all.
 *
 * THE PAIR OF THE ACTIVITY STREAM AND OF THE NOTIFICATION IS DECLARED WHOLE, and row 43 stands below both. Which
 * half of the pair applies is decided by the source of the call inside the steps (ALG-06), while the move of the
 * subscription of the responsible has a gate of its own - it happens even when no record was written - so it is a
 * step of this list rather than a branch of the layer of the activity stream.
 *
 * THE SHARED STEPS ARE TOLD WHICH PIPELINE THEY BELONG TO. Eight declarations here name the operation, and the
 * modes are not decoration: the same class checks the fields, compresses a letter, writes the storage elements, the
 * bindings, the statistical columns of a provider, the entry of the statistics of the activity, the record of the
 * activity stream and the notification of the responsible for both operations, and it does so by different rules.
 * The row clearing the tag of the managed cache is shared as well and has no mode at all.
 *
 * WHY A FACTORY AND NOT A SERVICE. The lifecycle service takes a closure per operation so that a call of one
 * operation does not construct the steps of the other three; this is that closure for the update, and the factory
 * of the services of the domain is where it is attached
 * ({@see \Bitrix\Crm\V2\Internal\Service\Activity\ActivityServiceFactory::createActivityLifecycleService()}). The
 * pipeline names itself after the operation, because the service refuses a declaration that names itself otherwise.
 *
 * @internal
 */
final class UpdatePipelineFactory
{
	/**
	 * @param StepFailureLogger|null $failureLogger where the failures of the steps of the run are written; the
	 *        default is the logger of the module. A test running the whole pipeline hands over one of its own.
	 */
	public static function create(?StepFailureLogger $failureLogger = null): Pipeline
	{
		return new Pipeline(ActivityLifecycleService::OPERATION_UPDATE, self::steps(), $failureLogger);
	}

	/**
	 * The rows of STEP-UPDATE, in the order of the table. The numbers of the comments are the numbers of the owner
	 * table; two rows have no number of their own - the differ of the single-valued fields, standing immediately
	 * above row 24, and the point materializing the statistics of the provider, standing between rows 25 and 26
	 * (ALG-05).
	 *
	 * @return PipelineStepInterface[]
	 */
	private static function steps(): array
	{
		return [
			// 1 - 4: what the update knows about the activity before it touches anything
			new LoadPreviousState(),
			new ResolveUserContext(),
			new ValidateAndNormalize(ActivityLifecycleService::OPERATION_UPDATE),
			new SnapshotPreviousBindings(),
			// 5 - 8: the values derived for the write
			new PrepareStoragePayload(),
			new ResolveOwnerFromBindings(),
			new ResolvePingOffsets(),
			new ResolveThread(),
			// 9 - 12: the texts, the event before the write, and the write itself
			new CompressEmailDescription(ActivityLifecycleService::OPERATION_UPDATE),
			new EncodeEmoji(),
			new PublishOnBeforeUpdate(),
			new UpdateActivity(),
			// 13 - 17: the rows beside the activity, and the state read back
			new CascadeThread(),
			new RebindMailBody(),
			new InvalidateItemCache(),
			new SaveStorageElements(ActivityLifecycleService::OPERATION_UPDATE),
			new ReloadCurrentState(),
			// 18 - 23: the sets of the activity and the mutually exclusive block of the incoming channel
			new SaveBindings(ActivityLifecycleService::OPERATION_UPDATE),
			new SaveCommunications(),
			new EnrichIncomingChannel(),
			new SyncIncomingChannel(),
			new OnResponsibleChanged(),
			new OnCompletedChanged(),
			// below the block, 24 - 25: what changed, told once for everybody below
			new DiffScalars(),
			new DiffDeadline(),
			new DiffNotify(),
			// between 25 and 26: the columns the statistics below read off the transit
			new MaterializeProviderStatistics(ActivityLifecycleService::OPERATION_UPDATE),
			// 26 - 31: the statistics, the registry of the channels and the search index
			new RegisterEntityStatisticsByPrevDeadline(),
			new RegisterEntityStatisticsByCurrentDeadline(),
			new UpdateCommunicationStatistics(),
			new RegisterActivityStatisticEntry(ActivityLifecycleService::OPERATION_UPDATE),
			new SyncChannelBinding(),
			new BuildSearchContent(),
			// 32 - 37: the counters, the badges, the fast search and the history
			new SyncLightCounter(),
			new SyncUncompletedAggregate(),
			new SyncBadges(),
			new NotifyCounterMonitor(),
			new SyncFastSearch(),
			new WriteHistory(),
			// 38 - 41: the provider, the calendar, the queue of the reminders and the timeline
			new ProviderUpdateAssociatedEntity(),
			new SyncCalendarEvent(),
			new SyncPingQueue(),
			new NotifyTimelineOnModify(),
			// 42 - 44: the record of the activity stream, the subscription it leaves alone, the notification
			new LivefeedBridge(ActivityLifecycleService::OPERATION_UPDATE),
			new MoveSonetSubscription(),
			new NotifyResponsibleViaIm(ActivityLifecycleService::OPERATION_UPDATE),
			// 45 - 48: the tail of item D12, with the tag of the managed cache last
			new SaveUserFields(),
			new ProviderOnAfterUpdate(),
			new PublishOnActivityUpdate(),
			new InvalidateTaggedCache(),
		];
	}
}
