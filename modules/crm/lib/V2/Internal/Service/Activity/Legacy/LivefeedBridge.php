<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Legacy;

use Bitrix\Crm\Integration\StorageType;
use Bitrix\Crm\Settings;
use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Crm\V2\Internal\Entity\Activity\ActivityBinding;
use Bitrix\Crm\V2\Internal\Entity\Activity\ActivityChangeSet;
use Bitrix\Crm\V2\Internal\Entity\Activity\ActivityStorageElement;
use Bitrix\Crm\V2\Internal\Entity\Activity\StorageFieldTransit;
use Bitrix\Crm\V2\Internal\Service\Activity\ActivityLifecycleService;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Crm\V2\Internal\Service\Activity\Provider\LegacyProviderAdapter;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Result;
use CCrmActivity;
use CCrmOwnerType;
use CCrmSonetSubscription;
use CCrmSonetSubscriptionType;

/**
 * The record of the activity stream of an activity, still written by legacy: step 32 of the owner table of an
 * add and step 42 of the owner table of an update (LBS-1 section 9.1 for the registration of a record, 9.2 for
 * its synchronization, checklist item G5).
 *
 * A TRANSITIONAL STEP, AND IT IS MEANT TO BE THROWN AWAY. The activity stream is not carried into V2 at all -
 * neither the records of the log, nor the subscriptions of the social network, nor the hook of a provider
 * building such a record. It has to keep working while the pipelines of V2 run the writes, so this step stands
 * exactly where legacy stands - after the user fields and before the lifecycle hooks of the provider and the
 * events of an activity - and calls the legacy methods AS THEY ARE, together with the notification of the
 * messenger wired into them. That is what keeps the record and its notification one thing, and it is why the
 * step assembles a legacy field array rather than handing the typed model over. The condition of its removal is
 * the activity stream dying out, not a rewrite of it here.
 *
 * THE ENTRY OF THE RUN IS THE ONLY DIVIDER (canonical block ALG-06). This step runs when the operation came
 * through the legacy facade, and the extracted step of the messenger notification
 * ({@see \Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\NotifyResponsibleViaIm}) runs when it
 * did not: the two split the three sources of a call into "the facade" and "not the facade" and leave no third
 * outcome. The question is asked here as {@see PipelineContext::isNativeEntry()} negated, which is the mirror
 * image of the predicate of that step - so nothing about the responsible is notified twice, and a facade entry
 * whose gates below did not open notifies nobody, which is what legacy does today.
 *
 * THE GATE IS THE GATE OF LEGACY, REFUSAL OF THE PROVIDER INCLUDED. A record is written when the caller asked
 * for it ({@see \Bitrix\Crm\V2\Internal\Service\Activity\ActivityOperationContext::shouldRegisterSonetEvent()},
 * strictly true as legacy compares it) and the provider of the activity does not refuse the activity stream
 * (crm_activity.php:427-428 on an add, :1100-1104 on an update). The refusal has no form in the contract of a
 * V2 provider on purpose: nothing in V2 produces such records, so the predicate is asked of the legacy class
 * behind the provider and of nothing else.
 *
 * WHAT THIS STEP DOES NOT OWN. The record of the timeline of an update (step 41) sits between the gate and this
 * step in legacy and is unconditional, so it belongs to the pipeline rather than here. The gate of the replacement of
 * the subscription when an activity changes hands (step 43) is outside the gate of legacy as well - it happens even
 * when no record was written - and it is a step of the update pipeline; that step owns the gate and asks this layer to
 * perform the move ({@see self::moveSubscriptionOfTheResponsible()}), because the subscriptions of the activity
 * stream are what this layer is about. The removal of a record on a delete is owned the same way: row 19 of the owner
 * table of a delete ({@see \Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Delete\CleanupLivefeed}) holds the
 * gate - the activity really never reached the recycle bin - and asks this layer to perform it
 * ({@see self::removeRecordsOfTheActivity()}). The registry row RECYCLE-ALWAYS-ON states that the setting of the
 * module cannot switch the bin off; it states nothing about a portal without the module of the bin and nothing about a
 * transfer that failed, and those are the two states legacy performs the removal in.
 *
 * THE BRANCH RESTORING A RECORD IS OUT OF THE EQUIVALENCE GUARANTEES. An update whose synchronization found
 * nothing to synchronize falls back to registering a record from scratch, and legacy reads the activity back
 * with a permission check on the way (crm_activity.php:1145, LBS-permissions section 10 item 4). It is
 * reproduced as it stands - no cache of legacy is primed for it and no snapshot is handed over - and the
 * characterization does not cover it: a difference found there is not a difference (ADR decision 2026-07-28,
 * checklist item D11). A record that branch really did register is announced through
 * {@see PipelineContext::RUN_VALUE_SONET_RECORD_REGISTERED}, where legacy keeps the same fact in a local boolean: the
 * step moving the subscription of the responsible reads it and leaves the subscription of a fresh record alone.
 *
 * @internal
 */
final class LivefeedBridge implements PipelineStepInterface
{
	private const OPERATIONS = [
		ActivityLifecycleService::OPERATION_ADD,
		ActivityLifecycleService::OPERATION_UPDATE,
	];

	/** The key the legacy registration of a record states its refusal under, in the fields it was given. */
	private const KEY_ERROR = 'ERROR';

	/**
	 * @param string $operation which of the two pipelines this instance belongs to, named by whoever declares
	 *        it: {@see ActivityLifecycleService::OPERATION_ADD} registers a record of the activity stream,
	 *        {@see ActivityLifecycleService::OPERATION_UPDATE} keeps an existing one in step with the activity.
	 * @throws ArgumentException on an operation that writes no record of the activity stream.
	 */
	public function __construct(private readonly string $operation)
	{
		if (!in_array($operation, self::OPERATIONS, true))
		{
			throw new ArgumentException(
				"The '{$operation}' operation over an activity writes no record of the activity stream",
				'operation',
			);
		}
	}

	public function getName(): string
	{
		return 'LivefeedBridge';
	}

	/**
	 * The gate of the legacy block, in the order that asks the cheap questions first: the entry of the run, the
	 * option of the call, the activity itself, and only then the provider - which resolves a legacy class to
	 * answer.
	 */
	public function isApplicable(PipelineContext $context): bool
	{
		if ($context->isNativeEntry())
		{
			return false;
		}

		if (!$context->getOperationContext()->shouldRegisterSonetEvent())
		{
			return false;
		}

		if ($context->getActivityId() <= 0)
		{
			return false;
		}

		return !self::providerRefusesTheActivityStream($context);
	}

	public function execute(PipelineContext $context): Result
	{
		if ($this->isAdd())
		{
			$this->registerRecord($context);
		}
		else
		{
			$this->synchronizeRecord($context);
		}

		return new Result();
	}

	/**
	 * Legacy interrupts no operation over a record of the activity stream that was not written, and neither
	 * does this step.
	 */
	public function isAbortingOnFailure(): bool
	{
		return false;
	}

	private function isAdd(): bool
	{
		return $this->operation === ActivityLifecycleService::OPERATION_ADD;
	}

	/**
	 * Registers the record of a stored activity and subscribes its responsible to it - the two halves of the
	 * legacy block of an add (crm_activity.php:430-442).
	 *
	 * The error the registration reports is announced to the run
	 * ({@see PipelineContext::RUN_VALUE_LIVEFEED_ERROR}): legacy hands the same text back to the caller of
	 * `CCrmActivity::Add()` through the very field array it was given (LBS-1 section 11), and the reverse
	 * projection of the facade is what has such an array to write into. The array assembled here goes no
	 * further than this method.
	 */
	private function registerRecord(PipelineContext $context): void
	{
		$fields = self::legacyFieldsOf($context);
		CCrmActivity::RegisterLiveFeedEventInternal($fields);

		if (isset($fields[self::KEY_ERROR]))
		{
			$context->setRunValue(PipelineContext::RUN_VALUE_LIVEFEED_ERROR, $fields[self::KEY_ERROR]);
		}

		self::subscribeResponsible($context);
	}

	/**
	 * Brings the records of a stored activity in step with it, and registers one from scratch when there was
	 * nothing to bring in step - the legacy block of an update (crm_activity.php:1127-1162).
	 */
	private function synchronizeRecord(PipelineContext $context): void
	{
		$activityId = $context->getActivityId();
		if (CCrmActivity::SynchronizeLiveFeedEventInternal($activityId, self::synchronizationOf($context)))
		{
			return;
		}

		$itemFields = CCrmActivity::GetByID($activityId);
		if (!is_array($itemFields))
		{
			return;
		}

		$itemFields['BINDINGS'] = self::legacyBindingsOf($context);
		$recordId = CCrmActivity::RegisterLiveFeedEventInternal($itemFields);
		if (is_int($recordId) && $recordId > 0)
		{
			$context->setRunValue(PipelineContext::RUN_VALUE_SONET_RECORD_REGISTERED, true);
		}

		self::subscribeResponsible($context);
	}

	/**
	 * Moves the subscription of the responsible of the activity from the person who had it to the one who has it now
	 * (crm_activity.php:1166-1180) - the part of the legacy block of the activity stream that stands OUTSIDE its
	 * gate, and therefore the effect of a step of the update pipeline with a gate of its own
	 * ({@see \Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Update\MoveSonetSubscription}).
	 *
	 * IT LIVES HERE BECAUSE THE SUBSCRIPTIONS OF THE ACTIVITY STREAM DO. This layer already owns the other half of
	 * that knowledge - subscribing the responsible to a record it has just registered - and the stream is what both
	 * halves are about; a copy of the call inside the step would split one dying subsystem over two files. What does
	 * not live here is the gate: the step asks whether a record was written at all, whether the activity changed
	 * hands and whether records are generated on this portal.
	 *
	 * @param bool $registerSonetEvent whether the operation asked for a record of the stream, as the option stands
	 *        after a refusal of the provider reset it. Legacy hands the same value over as the force of the move.
	 */
	public static function moveSubscriptionOfTheResponsible(
		int $activityId,
		int $responsibleId,
		int $previousResponsibleId,
		bool $registerSonetEvent,
	): void
	{
		CCrmSonetSubscription::ReplaceSubscriptionByEntity(
			CCrmOwnerType::Activity,
			$activityId,
			CCrmSonetSubscriptionType::Responsibility,
			$responsibleId,
			$previousResponsibleId,
			$registerSonetEvent,
		);
	}

	/**
	 * Removes what the activity stream holds about an activity that was erased instead of being moved to the recycle
	 * bin: its log records with the relations built out of them, and every subscription to it
	 * (crm_activity.php:1529-1533). The gate belongs to row 19 of the owner table of a delete, exactly as the gate of
	 * the move above belongs to its own row.
	 *
	 * THE TWO HALVES ARE NOT ONE CALL AND ONE OF THEM SKIPS A DATABASE. Legacy guards the removal of the records with
	 * "not PostgreSQL" inside the method proxied here, and leaves the removal of the subscriptions outside that guard -
	 * so a portal on PostgreSQL keeps the records and loses the subscriptions all the same.
	 */
	public static function removeRecordsOfTheActivity(int $activityId): void
	{
		CCrmActivity::UnregisterLiveFeedEventInternal($activityId);
		CCrmSonetSubscription::UnRegisterSubscriptionByEntity(CCrmOwnerType::Activity, $activityId);
	}

	/**
	 * Subscribes the responsible of the activity to its record. The generation of records being switched off
	 * leaves no record to subscribe to, and legacy asks the same question in the same place.
	 */
	private static function subscribeResponsible(PipelineContext $context): void
	{
		$responsibleId = (int)$context->getActivity()->getResponsibleId();
		if ($responsibleId <= 0 || !Settings\Crm::isLiveFeedRecordsGenerationEnabled())
		{
			return;
		}

		CCrmSonetSubscription::RegisterSubscription(
			CCrmOwnerType::Activity,
			$context->getActivityId(),
			CCrmSonetSubscriptionType::Responsibility,
			$responsibleId,
		);
	}

	/**
	 * Whether the provider of the activity refuses the activity stream. A provider that is not there refuses
	 * nothing - legacy leaves the option of the call alone then - and a provider without a legacy class behind
	 * it refuses everything: the capability does not exist in V2, so nothing can declare it.
	 *
	 * ASKED OUTSIDE THIS LAYER AS WELL, and that is why it is public. Legacy counts a refusal as a record already
	 * dealt with (:1101-1104), which is one half of the gate of the step moving the subscription of the responsible -
	 * and legacy raises it whether or not its own gate of the stream ever opened, so a run this layer never applied
	 * to still has to be able to ask.
	 */
	public static function providerRefusesTheActivityStream(PipelineContext $context): bool
	{
		$provider = $context->getProvider();
		if ($provider === null)
		{
			return false;
		}

		if (!$provider instanceof LegacyProviderAdapter)
		{
			return true;
		}

		$legacyClassName = $provider->getLegacyClassName();

		return $legacyClassName::canUseLiveFeedEvents($context->getActivity()->getProviderTypeId()) === false;
	}

	/**
	 * The activity in the legacy form the methods of the activity stream read it in: the columns they name and
	 * nothing besides them. The bindings travel here as well, because the parents of a record and the owners a
	 * notification checks the rights over are built out of them.
	 *
	 * @return array<string, mixed>
	 */
	private static function legacyFieldsOf(PipelineContext $context): array
	{
		$activity = $context->getActivity();
		$transit = $context->getTransit();

		return [
			'ID' => $context->getActivityId(),
			'OWNER_TYPE_ID' => self::intOf($transit, 'OWNER_TYPE_ID', CCrmOwnerType::Undefined),
			'OWNER_ID' => self::intOf($transit, 'OWNER_ID'),
			'TYPE_ID' => self::intOf($transit, 'TYPE_ID'),
			'PROVIDER_ID' => (string)$activity->getProviderId(),
			'ASSOCIATED_ENTITY_ID' => self::intOf($transit, 'ASSOCIATED_ENTITY_ID'),
			'SUBJECT' => (string)$activity->getSubject(),
			'RESPONSIBLE_ID' => (int)$activity->getResponsibleId(),
			'AUTHOR_ID' => (int)$activity->getAuthorId(),
			'EDITOR_ID' => (int)$activity->getEditorId(),
			'STORAGE_TYPE_ID' => self::intOf($transit, 'STORAGE_TYPE_ID', StorageType::Undefined),
			'STORAGE_ELEMENT_IDS' => self::legacyStorageElementsOf($activity),
			'BINDINGS' => self::legacyBindingsOf($context),
		];
	}

	/**
	 * The parameters of a synchronization, as the legacy update assembles them (crm_activity.php:1129-1141).
	 * Every one of them is a fact of the run: what changed, who is doing it and what the stored activity looks
	 * like now.
	 *
	 * @return array<string, mixed>
	 */
	private static function synchronizationOf(PipelineContext $context): array
	{
		$activity = $context->getActivity();
		$changes = $context->getChanges();
		$bindingsChanged = $changes->isChanged(ActivityChangeSet::bindings);
		$responsibleId = (int)$activity->getResponsibleId();

		return [
			'PROCESS_BINDINGS' => $bindingsChanged,
			'BINDINGS' => $bindingsChanged ? self::legacyBindingsOf($context) : null,
			'REFRESH_DATE' => $changes->isChanged(ActivityChangeSet::isCompleted),
			'START_RESPONSIBLE_ID' => $changes->has(ActivityChangeSet::responsibleId)
				? (int)$changes->getPrevious(ActivityChangeSet::responsibleId)
				: $responsibleId
			,
			'FINAL_RESPONSIBLE_ID' => $responsibleId,
			'EDITOR_ID' => self::editorOf($context),
			'TYPE_ID' => self::intOf($context->getTransit(), 'TYPE_ID'),
			'SUBJECT' => (string)$activity->getSubject(),
		];
	}

	/**
	 * The user the synchronization is by: the editor of the activity, and the user the operation acts as when
	 * it has none - which is what legacy reads directly.
	 */
	private static function editorOf(PipelineContext $context): int
	{
		$editorId = (int)$context->getActivity()->getEditorId();

		return $editorId > 0 ? $editorId : $context->getOperationContext()->getCurrentUserId();
	}

	/**
	 * The bindings of the activity in the legacy form, the one the records of the activity stream and their
	 * relations are built out of.
	 *
	 * @return array<int, array<string, int>>
	 */
	private static function legacyBindingsOf(PipelineContext $context): array
	{
		return array_map(
			static fn(ActivityBinding $binding): array => [
				'OWNER_TYPE_ID' => $binding->getOwnerType()->getId(),
				'OWNER_ID' => $binding->getOwnerId(),
			],
			$context->getActivity()->getBindings() ?? [],
		);
	}

	/**
	 * The files of the activity as the ids legacy attaches to a record. An array of them, empty or not, is
	 * always given: it is what stops the registration from deriving the ids of an activity anew.
	 *
	 * @return int[]
	 */
	private static function legacyStorageElementsOf(Activity $activity): array
	{
		return array_map(
			static fn(ActivityStorageElement $element): int => $element->getElementId(),
			$activity->getFiles() ?? [],
		);
	}

	/**
	 * A column of the storage the transit does not carry, as the value legacy reads of a field array without
	 * it: the undefined owner, the undefined type of storage, nothing.
	 */
	private static function intOf(StorageFieldTransit $transit, string $field, int $default = 0): int
	{
		return $transit->has($field) ? (int)$transit->get($field) : $default;
	}
}
