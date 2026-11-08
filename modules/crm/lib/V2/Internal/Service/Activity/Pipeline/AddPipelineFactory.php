<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline;

use Bitrix\Crm\V2\Internal\Service\Activity\ActivityLifecycleService;
use Bitrix\Crm\V2\Internal\Service\Activity\Legacy\LivefeedBridge;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Add\AddMailBodyBinding;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Add\AppendOwnerBinding;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Add\ApplyStorageTypeDefault;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Add\InsertActivity;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Add\NormalizeForStorage;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Add\NotifyCounterMonitor;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Add\NotifyTimelineOnCreate;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Add\ProviderOnAfterAdd;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Add\ProviderScopeOnCreated;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Add\PublishOnActivityAdd;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Add\PublishOnBeforeAdd;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Add\RegisterCommunicationStatistics;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Add\RegisterEntityStatistics;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Add\RegisterIncomingChannel;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Add\RegisterPingQueue;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Add\RegisterWaitEntry;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Add\ResolveOwnerFromBindings;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Add\ResolvePingOffsets;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Add\ResolveThread;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Add\SetSelfThread;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Add\SyncCalendarEvent;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Add\SyncFastSearch;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Add\SyncLightCounter;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Add\SyncUncompletedAggregate;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Add\WriteHistory;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\BuildSearchContent;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\CompressEmailDescription;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\EncodeEmoji;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\MaterializeProviderStatistics;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\NotifyResponsibleViaIm;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\PrepareStoragePayload;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\RegisterActivityStatisticEntry;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\SaveBindings;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\SaveCommunications;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\SaveStorageElements;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\SaveUserFields;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\ValidateAndNormalize;

/**
 * The declaration of the creation of an activity: the whole owner table STEP-ADD, in its order, and nothing else.
 *
 * THIS IS THE ONLY PLACE THE COMPOSITION OF THE ADD EXISTS. The engine holds no rule about any step and the steps
 * hold no rule about each other, so the order of the effects of legacy is readable here in one list and nowhere
 * else - which is what makes it comparable with the owner table item by item, and what the test of the composition
 * reads. A step added, removed or moved is a change of the behaviour of the operation and has to look like one.
 *
 * THE ORDER IS NOT NEGOTIABLE, and five of its stretches carry an invariant of their own:
 * - the serialization of the payload, the event before the write and the compression of the description of a letter
 *   stand in that order and no other (section 13 item 1 of LBS-1, item C4): a subscriber sees the settings
 *   serialized and the description uncompressed, and that is the shape it has read for years;
 * - the search index is built below the bindings and the communications, because its builder re-reads both out of
 *   storage (section 13 item 3): moved above them, it indexes an activity nobody can find by the name of its owner
 *   or by a phone number;
 * - the calendar stands above the reminders, the timeline and the user fields, and that is why the steps below it do
 *   not see the location it consumed (section 13 item 7, item G3);
 * - the point materializing the statistics of the provider stands between the communications and the statistics of
 *   the entities, because everything below it reads those columns off the transit (ALG-05);
 * - the tail of the four steps below the notifications - the hook of the provider, the subscription of every
 *   provider, the entries of the waiting, the event of the webhooks - is item C11 and is not reordered.
 *
 * THE PAIR OF THE ACTIVITY STREAM AND OF THE NOTIFICATION IS DECLARED WHOLE. Both steps are here and both are asked
 * on every run; which of them applies is decided by the source of the call inside them (ALG-06), so the declaration
 * carries no condition of its own and the mutual exclusion stays in one place.
 *
 * WHY A FACTORY AND NOT A SERVICE. The lifecycle service takes a closure per operation so that a call of one
 * operation does not construct the steps of the other three; this is that closure for the add, and the factory of the
 * services of the domain is where it is attached
 * ({@see \Bitrix\Crm\V2\Internal\Service\Activity\ActivityServiceFactory::createActivityLifecycleService()}). The
 * pipeline names itself after the operation, because the service refuses a declaration that names itself otherwise.
 *
 * @internal
 */
final class AddPipelineFactory
{
	/**
	 * @param StepFailureLogger|null $failureLogger where the failures of the steps of the run are written; the
	 *        default is the logger of the module. A test running the whole pipeline hands over one of its own.
	 */
	public static function create(?StepFailureLogger $failureLogger = null): Pipeline
	{
		return new Pipeline(ActivityLifecycleService::OPERATION_ADD, self::steps(), $failureLogger);
	}

	/**
	 * The rows of STEP-ADD, in the order of the table. The numbers of the rows are the numbers of the owner table,
	 * and the point materializing the statistics stands between rows 17 and 18 without a number of its own.
	 *
	 * @return PipelineStepInterface[]
	 */
	private static function steps(): array
	{
		return [
			// 1 - 5: what an activity is completed and checked with before anything is written
			new ResolveOwnerFromBindings(),
			new ApplyStorageTypeDefault(),
			new ValidateAndNormalize(ActivityLifecycleService::OPERATION_ADD),
			new PrepareStoragePayload(),
			new ResolveThread(),
			// 6 - 11: the write itself and the texts around it
			new PublishOnBeforeAdd(),
			new CompressEmailDescription(ActivityLifecycleService::OPERATION_ADD),
			new EncodeEmoji(),
			new NormalizeForStorage(),
			new InsertActivity(),
			new AddMailBodyBinding(),
			// 12 - 17: the rows that belong to the activity beside its own
			new SetSelfThread(),
			new RegisterIncomingChannel(),
			new SaveStorageElements(ActivityLifecycleService::OPERATION_ADD),
			new AppendOwnerBinding(),
			new SaveBindings(ActivityLifecycleService::OPERATION_ADD),
			new SaveCommunications(),
			// between 17 and 18: the columns the statistics below read off the transit
			new MaterializeProviderStatistics(ActivityLifecycleService::OPERATION_ADD),
			// 18 - 23: the statistics, the counters and the reminders
			new RegisterEntityStatistics(),
			new ResolvePingOffsets(),
			new SyncLightCounter(),
			new SyncUncompletedAggregate(),
			new RegisterCommunicationStatistics(),
			new RegisterActivityStatisticEntry(),
			// 24 - 27: the search index, the history and the monitors
			new BuildSearchContent(),
			new WriteHistory(),
			new NotifyCounterMonitor(),
			new SyncFastSearch(),
			// 28 - 31: the calendar, the reminders queue, the timeline and the user fields
			new SyncCalendarEvent(),
			new RegisterPingQueue(),
			new NotifyTimelineOnCreate(),
			new SaveUserFields(),
			// 32 - 33: the record of the activity stream and the notification, mutually exclusive by ALG-06
			new LivefeedBridge(ActivityLifecycleService::OPERATION_ADD),
			new NotifyResponsibleViaIm(ActivityLifecycleService::OPERATION_ADD),
			// 34 - 37: the tail of item C11, in the order the webhooks depend on
			new ProviderOnAfterAdd(),
			new ProviderScopeOnCreated(),
			new RegisterWaitEntry(),
			new PublishOnActivityAdd(),
		];
	}
}
