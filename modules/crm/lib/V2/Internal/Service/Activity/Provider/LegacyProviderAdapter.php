<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Provider;

use Bitrix\Crm\Activity\Provider;
use Bitrix\Crm\ActivityTable;
use Bitrix\Crm\Service\Context;
use Bitrix\Crm\V2\Internal\Access\Activity\AccessDecision;
use Bitrix\Crm\V2\Internal\Access\Activity\Operation;
use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Crm\V2\Internal\Entity\Activity\ActivityBinding;
use Bitrix\Crm\V2\Internal\Entity\Activity\ActivityChangeSet;
use Bitrix\Crm\V2\Internal\Entity\Activity\ProviderPayload;
use Bitrix\Crm\V2\Internal\Entity\Activity\StorageFieldTransit;
use Bitrix\Crm\V2\Internal\Repository\Activity\ActivityFieldMap;
use Bitrix\Crm\V2\Internal\Service\Activity\ActivityOperationContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Provider\Capability\AssociatedEntityCapabilityInterface;
use Bitrix\Crm\V2\Internal\Service\Activity\Provider\Capability\BadgeCapabilityInterface;
use Bitrix\Crm\V2\Internal\Service\Activity\Provider\Capability\CalendarCapabilityInterface;
use Bitrix\Crm\V2\Internal\Service\Activity\Provider\Capability\CompletionCapabilityInterface;
use Bitrix\Crm\V2\Internal\Service\Activity\Provider\Capability\LifecycleCapabilityInterface;
use Bitrix\Crm\V2\Internal\Service\Activity\Provider\Capability\PermissionCapabilityInterface;
use Bitrix\Crm\V2\Internal\Service\Activity\Provider\Capability\PingCapabilityInterface;
use Bitrix\Crm\V2\Internal\Service\Activity\Provider\Capability\ScopeOperationsCapabilityInterface;
use Bitrix\Crm\V2\Internal\Service\Activity\Provider\Capability\TimelineCapabilityInterface;
use Bitrix\Crm\V2\Internal\Service\Activity\Provider\Timeline\LegacyTimelineItemFactory;
use Bitrix\Crm\V2\Internal\Service\Activity\Provider\Timeline\TimelineItemRequest;
use Bitrix\Crm\V2\Public\Command\Item\Scope;
use Bitrix\Crm\V2\Public\ItemId;
use Bitrix\Crm\V2\Public\OwnerType;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use CCrmDateTimeHelper;
use ReflectionMethod;

/**
 * One provider still written as a legacy class, seen through the contracts of a V2 provider.
 *
 * WHAT IT TRANSLATES. Legacy expressed a provider as a class name and about sixty static methods over a
 * field array; V2 expresses it as an object, a typed model and a capability per subject. This class is the
 * only place the two meet, and it holds the whole translation: the identity and the check of the fields of
 * {@see ActivityProviderInterface}, plus every capability whose legacy methods exist. No legacy provider
 * class is touched, now or later - they are migrated one by one to the native contract, and this class
 * disappears with the last of them.
 *
 * A DEFAULT OF `Provider\Base` IS NOT AN OPINION, and telling the two apart is the point of the whole
 * design. Legacy gave every provider an answer to every question, so "I have no rule here" and "my rule
 * happens to be the default" looked identical. Here a capability is declared only when the legacy class
 * overrides at least one method of it ({@see self::getDeclaredCapabilities()}), and the declaration lives on
 * the descriptor of the registry: one adapter class serves three dozen providers, so asking it with
 * instanceof would answer the same for every one of them. The rule of the answer is computed from the legacy
 * class, which is why it belongs here and not in the registry.
 *
 * THE SUBSCRIPTION TO THE SCOPE OPERATIONS IS THE ONE EXCEPTION to that rule. Legacy asked every provider
 * about every activity created and about every owner moved or erased, so every wrapped provider is
 * subscribed whether it overrides anything or not: which of them would have answered cannot be told from
 * outside until they are migrated.
 *
 * IDENTITY STAYS A STRING. The value of the PROVIDER_ID column is what {@see self::getId()} answers, and the
 * name of the legacy class is what {@see self::getLegacyClassName()} answers - existing code compares that
 * name literally and hands it to class_exists, and an object in its place would break both.
 *
 * THREE REPRESENTATIONS OF ONE ACTIVITY, and this class converts between two of them. The typed model plus
 * the payload and the storage columns of the operation are one; the field array of legacy is another; the
 * columns of the table as the ORM takes them are the third, and that one is the business of
 * {@see ActivityFieldMap} and of the repository. So the conversions here are the adapter's own: dates travel
 * as the strings legacy parses, completion as 'Y' and 'N', and a value the provider wrote is put back where
 * its column belongs - the payload for the serialized three, the transit for the storage columns, the model
 * for the rest.
 *
 * WHAT A LEGACY PROVIDER MAY WRITE AND NO COLUMN CAN HOLD. A check of the fields may add a key that is no
 * column of the table at all: the provider of to-do items enriches the description of a calendar event that
 * way. The list of storage columns is closed by contract, so such a key is never invented into the model -
 * it is answered instead, in the data of the result of {@see self::validate()}, and which of them a run
 * carries is decided by the pipeline.
 *
 * @internal
 */
final class LegacyProviderAdapter implements
	ActivityProviderInterface,
	AssociatedEntityCapabilityInterface,
	BadgeCapabilityInterface,
	CalendarCapabilityInterface,
	CompletionCapabilityInterface,
	LifecycleCapabilityInterface,
	PermissionCapabilityInterface,
	PingCapabilityInterface,
	ScopeOperationsCapabilityInterface,
	TimelineCapabilityInterface
{
	/**
	 * The legacy methods each capability is made of: an own override of any one of them declares it.
	 *
	 * The timeline is absent on purpose - a legacy class has no method to build a card with and never gets
	 * one, so its part in the timeline is declared by its descriptor
	 * ({@see ProviderDescriptor::getTimelineCapabilities()}) and not by an override.
	 */
	private const CAPABILITY_METHODS = [
		CalendarCapabilityInterface::class => [
			'canUseCalendarEvents',
			'canAddCalendarEvents',
			'canKeepCompletedInCalendar',
			'canKeepReassignedInCalendar',
			'skipCalendarSync',
		],
		LifecycleCapabilityInterface::class => [
			'onAfterAdd',
			'onAfterUpdate',
			'onAfterDelete',
			'onBeforeComplete',
			'processMovingToRecycleBin',
			'processRestorationFromRecycleBin',
			'onAfterRecycleBinErase',
		],
		CompletionCapabilityInterface::class => [
			'isCompletable',
			'checkForWaitingCompletion',
			'canCompleteOnView',
			'tryPostpone',
		],
		PingCapabilityInterface::class => [
			'getDefaultPingOffsets',
			'needSynchronizePingQueue',
		],
		BadgeCapabilityInterface::class => [
			'syncBadges',
		],
		AssociatedEntityCapabilityInterface::class => [
			'updateAssociatedEntity',
			'deleteAssociatedEntity',
			'rebindAssociatedEntity',
		],
		PermissionCapabilityInterface::class => [
			'checkReadPermission',
			'checkUpdatePermission',
			'checkCompletePermission',
			'checkPostponePermission',
		],
	];

	/** Columns the legacy permission hooks read that no typed model of an activity holds. */
	private const PERMISSION_COLUMNS = ['OWNER_ID', 'OWNER_TYPE_ID', 'ASSOCIATED_ENTITY_ID', 'SETTINGS'];

	/** Columns of the model a legacy provider may write: every core column that has a setter. */
	private const WRITABLE_COLUMNS = [
		'PROVIDER_ID',
		'PROVIDER_TYPE_ID',
		'SUBJECT',
		'DESCRIPTION',
		'DESCRIPTION_TYPE',
		'COMPLETED',
		'STATUS',
		'RESPONSIBLE_ID',
		'AUTHOR_ID',
		'EDITOR_ID',
		'DIRECTION',
		'START_TIME',
		'END_TIME',
		'PARENT_ID',
		'AUTOCOMPLETE_RULE',
	];

	/** Of those, the ones a provider may clear rather than only change. */
	private const NULLABLE_COLUMNS = ['PROVIDER_TYPE_ID', 'START_TIME', 'END_TIME', 'PARENT_ID'];

	/**
	 * Whether a legacy class declares a method itself rather than inheriting it - by class and by method.
	 *
	 * READ ONCE PER CLASS AND KEPT FOR THE PROCESS. Reflection is the only way to tell the two apart, and the
	 * registry asks it of three dozen classes while composing itself, on every hit; every wrapped provider then
	 * asks again about the hook of an operation.
	 *
	 * THE INVARIANT THIS AND {@see self::$declaredCapabilities} REST ON: the key is the name of a legacy class,
	 * and within one process a name means one composition of methods. PHP holds up that half by itself - a name
	 * binds to one declaration and cannot be rebound - so there is nothing here to invalidate and no cache to
	 * clear: replacing the composition of a registry, which is what a test doing so changes, changes which
	 * classes are ASKED about and never what one of them declares. What follows for a fixture bringing a
	 * provider for the length of a single test: it must bring no legacy class along with it, because what is
	 * read off that class outlives the fixture by the whole process
	 * ({@see \Bitrix\Crm\Tests\TestUtils\Fixture\ArtificialActivityProviderFixture}, which guards exactly that).
	 *
	 * @var array<string, array<string, bool>>
	 */
	private static array $ownOverrides = [];

	/**
	 * @var array<string, string[]> the capabilities each legacy class declares, by class name - kept for the
	 *      process under the invariant stated on {@see self::$ownOverrides}
	 */
	private static array $declaredCapabilities = [];

	private readonly LegacyTimelineItemFactory $timelineItemFactory;

	/** @var array<int, array<string, mixed>> the columns of {@see self::PERMISSION_COLUMNS} by activity id */
	private array $permissionColumns = [];

	/**
	 * @param string $legacyClassName A subclass of `Provider\Base`.
	 * @param TimelineCapabilitySet $timelineCapabilities What the descriptor of this provider declares about
	 *        its part in the timeline - the only source of the two predicates, since the legacy class has
	 *        nothing to answer them with.
	 *
	 * @throws ArgumentException when the class is not a legacy provider.
	 */
	public function __construct(
		private readonly string $legacyClassName,
		private readonly TimelineCapabilitySet $timelineCapabilities = new TimelineCapabilitySet(),
		?LegacyTimelineItemFactory $timelineItemFactory = null,
	)
	{
		self::assertLegacyProvider($legacyClassName);

		$this->timelineItemFactory = $timelineItemFactory ?? new LegacyTimelineItemFactory();
	}

	/**
	 * The capabilities a wrapped provider of this legacy class takes part in, named by the interface of each.
	 *
	 * @return string[]
	 * @throws ArgumentException when the class is not a legacy provider.
	 */
	public static function getDeclaredCapabilities(string $legacyClassName): array
	{
		self::assertLegacyProvider($legacyClassName);

		if (isset(self::$declaredCapabilities[$legacyClassName]))
		{
			return self::$declaredCapabilities[$legacyClassName];
		}

		$capabilities = [];
		foreach (self::CAPABILITY_METHODS as $capability => $methods)
		{
			foreach ($methods as $method)
			{
				if (self::hasOwnOverrideOf($legacyClassName, $method))
				{
					$capabilities[] = $capability;

					break;
				}
			}
		}

		// Asked of everybody in legacy, so subscribed for everybody here - see the note on the class.
		$capabilities[] = ScopeOperationsCapabilityInterface::class;

		return self::$declaredCapabilities[$legacyClassName] = $capabilities;
	}

	/**
	 * The name of the legacy class behind this provider: what existing code compares literally and passes to
	 * class_exists. The contract of a provider does not publish it - only a wrapped legacy one has one.
	 */
	public function getLegacyClassName(): string
	{
		return $this->legacyClassName;
	}

	//region the mandatory core

	public function getId(): string
	{
		return (string)($this->legacyClassName)::getId();
	}

	public function getName(): string
	{
		// Two legacy providers declare a nullable name and the localization may answer nothing at all.
		return (string)($this->legacyClassName)::getName();
	}

	/**
	 * Availability was never part of the legacy base class: the two providers it applies to declare a static
	 * of their own, and a provider without one applies everywhere.
	 */
	public function isAvailable(): bool
	{
		return method_exists($this->legacyClassName, 'isAvailable')
			? (bool)($this->legacyClassName)::isAvailable()
			: true
		;
	}

	public function isActive(): bool
	{
		return (bool)($this->legacyClassName)::isActive();
	}

	public function getTypes(): array
	{
		$types = [];
		foreach (($this->legacyClassName)::getTypes() as $type)
		{
			$types[] = [
				'name' => (string)($type['NAME'] ?? ''),
				'providerTypeId' => (string)($type['PROVIDER_TYPE_ID'] ?? ''),
				'directions' => array_map('strval', $type['DIRECTIONS'] ?? []),
			];
		}

		return $types;
	}

	/**
	 * Legacy answered the same question with an empty string, and an empty string was then written into the
	 * column. Here it becomes "no default of my own", because the core only ever fills an empty value.
	 */
	public function getDefaultTypeId(): ?string
	{
		$typeId = (string)($this->legacyClassName)::getTypeId([]);

		return $typeId === '' ? null : $typeId;
	}

	/**
	 * Runs the legacy check of the fields and puts back whatever it changed.
	 *
	 * THE ARRAY IS THE INPUT OF THE OPERATION, not the state of the row: only the fields the caller touched
	 * are in it, exactly as legacy handed over what it was given. A deadline is taken out on an update
	 * because legacy takes it out there (crm_activity.php:2126) - it is derived by the provider and never
	 * accepted from a caller.
	 *
	 * NOTHING IS FILLED IN HERE. The identifier of the provider and the default type of an activity are
	 * filled after this method has run and only where the value is empty, which is the order legacy kept
	 * (:2140-2151) and the reason {@see self::getDefaultTypeId()} is a question of its own.
	 *
	 * WHAT THE CHECK PRODUCED AND NO COLUMN CARRIES IS ANSWERED, not dropped: the data of the result holds
	 * those keys under the names legacy gave them ({@see self::uncarriedFields()}). The pipeline decides
	 * which of them it carries - the enriched description of a calendar event is one - and everything it does
	 * not know about dies with the array, exactly as it did before this answer existed.
	 */
	public function validate(Activity $activity, ProviderPayload $payload, ValidationContext $context): Result
	{
		$transit = $context->getTransit();
		$fields = $this->toLegacyFields($activity, $payload, $transit, changedOnly: true);
		if ($context->isUpdate())
		{
			unset($fields['DEADLINE']);
		}

		$before = $fields;
		$legacyResult = ($this->legacyClassName)::checkFields(
			$context->isAdd() ? Provider\Base::ACTION_ADD : Provider\Base::ACTION_UPDATE,
			$fields,
			$activity->getId() ?? 0,
			$this->validationParams($context),
		);

		$this->applyLegacyFields($before, $fields, $activity, $payload, $transit);

		return (new Result())
			->addErrors($legacyResult->getErrors())
			->setData($this->uncarriedFields($before, $fields))
		;
	}

	/**
	 * The keys the check of the fields wrote that no column of an activity carries, by the name legacy uses.
	 *
	 * A legacy provider is free to add anything to the field array, and the enriched description of a calendar
	 * event is such an addition with a real reader (`todo.php:311`, read back at `crm_activity.php:7016`).
	 * The closed lists of V2 cannot hold it, so instead of inventing a column for it the wrapper answers with
	 * it and leaves the carrier to the pipeline.
	 *
	 * @param array<string, mixed> $before
	 * @param array<string, mixed> $after
	 * @return array<string, mixed>
	 */
	private function uncarriedFields(array $before, array $after): array
	{
		$uncarried = [];
		foreach ($after as $key => $value)
		{
			$key = (string)$key;
			if (array_key_exists($key, $before) && $before[$key] === $value)
			{
				continue;
			}

			if (self::isCarriedKey($key))
			{
				continue;
			}

			$uncarried[$key] = $value;
		}

		return $uncarried;
	}

	/**
	 * Whether the key belongs to a column of an activity - a raw one of legacy included, since those are put
	 * back by {@see self::writeLegacyValue()} or belong to the storage alone.
	 */
	private static function isCarriedKey(string $key): bool
	{
		return str_starts_with($key, '~')
			|| ActivityFieldMap::hasColumn($key)
			|| in_array($key, StorageFieldTransit::getSupportedFields(), true)
		;
	}

	//endregion

	//region rights

	/**
	 * THE DEFAULT OF THE LEGACY BASE CLASS IS NEVER ASKED. It restates the check of the core, so taking it
	 * for a verdict would leave that check unreachable; a provider that overrides no hook of the operation
	 * states nothing. An overridden hook may still state nothing - the hooks of completing and of postponing
	 * answer null for "the core decides", and that answer is carried over as it is.
	 */
	public function decide(int $userId, Operation $operation, Activity $activity): AccessDecision
	{
		$hook = $this->permissionHook($operation);
		if ($hook === null || !$this->hasOwnOverride($hook))
		{
			return AccessDecision::NoOpinion;
		}

		$fields = $this->permissionFields($activity);
		$verdict = match ($operation)
		{
			Operation::Read => ($this->legacyClassName)::checkReadPermission($fields, $userId),
			Operation::Update => ($this->legacyClassName)::checkUpdatePermission($fields, $userId),
			Operation::Complete => ($this->legacyClassName)::checkCompletePermission(
				(int)($fields['ASSOCIATED_ENTITY_ID'] ?? 0),
				$fields,
				$userId,
			),
			Operation::Postpone => ($this->legacyClassName)::checkPostponePermission(
				(int)($fields['ASSOCIATED_ENTITY_ID'] ?? 0),
				$fields,
				$userId,
			),
			default => null,
		};

		if (!is_bool($verdict))
		{
			return AccessDecision::NoOpinion;
		}

		return $verdict ? AccessDecision::Allow : AccessDecision::Deny;
	}

	/**
	 * The reason of a refused completion as legacy keeps it: one static of the base class shared by every
	 * provider, falling back to the plain "access denied" while nobody has written into it. It is neither
	 * reset nor copied per provider here, so the text of one provider goes on reaching the answer of another
	 * within one hit - the behaviour of the providers that have not been migrated.
	 */
	public function getCompletionDeniedError(): ?Error
	{
		return ($this->legacyClassName)::getCompletionDeniedError();
	}

	//endregion

	//region the calendar

	public function canUseCalendarEvents(?string $providerTypeId = null): bool
	{
		return (bool)($this->legacyClassName)::canUseCalendarEvents($providerTypeId);
	}

	public function canAddCalendarEvents(?string $providerTypeId = null): bool
	{
		return (bool)($this->legacyClassName)::canAddCalendarEvents($providerTypeId);
	}

	public function canKeepCompletedInCalendar(?string $providerTypeId = null): bool
	{
		return (bool)($this->legacyClassName)::canKeepCompletedInCalendar($providerTypeId);
	}

	public function canKeepReassignedInCalendar(?string $providerTypeId = null): bool
	{
		return (bool)($this->legacyClassName)::canKeepReassignedInCalendar($providerTypeId);
	}

	public function skipCalendarSync(Activity $activity, ActivityOperationContext $context): bool
	{
		return (bool)($this->legacyClassName)::skipCalendarSync(
			$this->toInputFields($activity, $context),
			$this->calendarOptions($context),
		);
	}

	//endregion

	//region the lifecycle

	public function onAfterAdd(Activity $activity, ActivityOperationContext $context): void
	{
		($this->legacyClassName)::onAfterAdd(
			$this->toInputFields($activity, $context),
			['IS_RESTORATION' => $context->isRestoration()],
		);
	}

	/**
	 * The options of the operation are not handed on, here or to the hook of a delete: legacy announced both
	 * with the fields alone (crm_activity.php:1190, :1514), and widening what an existing provider sees is
	 * not this class's business.
	 *
	 * THE SECOND LEGACY ARGUMENT IS THE FIELDS BEING WRITTEN, and they are projected off the state the
	 * operation named rather than off the state of the run: an update replaces the latter with the row it read
	 * back, and a model hydrated from a row marks no field as changed - so the projection of the changed fields
	 * over it would come out without a single core column, and a provider deciding by `COMPLETED` or by
	 * `RESPONSIBLE_ID` would be told the update moved neither. The caller of the hook hands that state over
	 * ({@see LifecycleCapabilityInterface::onAfterUpdate()}); where it hands none - an operation whose state of
	 * the run IS the state being written - the model of the run answers, which is what it did before the
	 * argument existed.
	 */
	public function onAfterUpdate(
		Activity $activity,
		ActivityChangeSet $changes,
		ActivityOperationContext $context,
		?Activity $inputActivity = null
	): void
	{
		($this->legacyClassName)::onAfterUpdate(
			$activity->getId() ?? 0,
			$this->toLegacyFields(
				$inputActivity ?? $activity,
				$context->getInputPayload(),
				$context->getInputTransit(),
				true,
			),
			$this->toChangeSetFields($changes, previous: true),
			$this->toInputFields($activity, $context),
		);
	}

	public function onAfterDelete(Activity $activity, ActivityOperationContext $context): void
	{
		($this->legacyClassName)::onAfterDelete(
			$activity->getId() ?? 0,
			$this->toInputFields($activity, $context),
		);
	}

	public function onBeforeComplete(Activity $activity, ActivityOperationContext $context): void
	{
		($this->legacyClassName)::onBeforeComplete(
			$activity->getId() ?? 0,
			$this->toInputFields($activity, $context),
			['CURRENT_USER' => $context->getCurrentUserId()],
		);
	}

	/**
	 * The nested options of the two recycle-bin hooks are not handed over: legacy assembles them from
	 * constants of its own at the call sites of the recycle bin (recycling/activitycontroller.php:263, :425),
	 * and those call sites are not served by this class.
	 */
	public function processMovingToRecycleBin(Activity $activity, ActivityOperationContext $context): Result
	{
		return ($this->legacyClassName)::processMovingToRecycleBin($this->toInputFields($activity, $context));
	}

	public function processRestorationFromRecycleBin(
		Activity $activity,
		ActivityOperationContext $context
	): Result
	{
		return ($this->legacyClassName)::processRestorationFromRecycleBin(
			$this->toInputFields($activity, $context),
		);
	}

	public function onAfterRecycleBinErase(Activity $activity, ActivityOperationContext $context): void
	{
		($this->legacyClassName)::onAfterRecycleBinErase($this->toInputFields($activity, $context));
	}

	//endregion

	//region completing and postponing

	public function isCompletable(): bool
	{
		return (bool)($this->legacyClassName)::isCompletable();
	}

	public function checkForWaitingCompletion(Activity $activity): bool
	{
		return (bool)($this->legacyClassName)::checkForWaitingCompletion(
			$this->toLegacyFields($activity, null, null),
		);
	}

	public function canCompleteOnView(): bool
	{
		return (bool)($this->legacyClassName)::canCompleteOnView();
	}

	/**
	 * THE SHIFT IS WRITTEN ONTO THE ACTIVITY and nothing is recorded in the set of changes. A legacy
	 * provider shifts the start and the end of an activity, and both are fields of the model; the deadline it
	 * may also write is dropped, exactly as legacy drops it before an update reaches the storage, so a
	 * deadline moves only where the check of the fields derives it again from the start time.
	 */
	public function tryPostpone(int $offsetSeconds, Activity $activity, ActivityChangeSet $changes): Result
	{
		$shiftedFields = [];
		$isShifted = ($this->legacyClassName)::tryPostpone(
			$offsetSeconds,
			$this->toLegacyFields($activity, null, null),
			$shiftedFields,
		);

		$result = new Result();
		if ($isShifted === false)
		{
			return $result->addError(new Error('Postpone denied by provider.'));
		}

		foreach (['START_TIME', 'END_TIME'] as $column)
		{
			if (array_key_exists($column, $shiftedFields))
			{
				$this->writeModelColumn($activity, $column, $shiftedFields[$column]);
			}
		}

		return $result;
	}

	//endregion

	//region reminders and badges

	public function getDefaultPingOffsets(int $entityTypeId, ?int $categoryId): array
	{
		$offsets = ($this->legacyClassName)::getDefaultPingOffsets([
			'entityTypeId' => $entityTypeId,
			// An owner without funnels has no category, and legacy names that zero rather than nothing.
			'categoryId' => (int)($categoryId ?? 0),
		]);

		return array_map('intval', $offsets);
	}

	public function needSynchronizePingQueue(Activity $activity, ActivityOperationContext $context): bool
	{
		return (bool)($this->legacyClassName)::needSynchronizePingQueue(
			$this->toInputFields($activity, $context),
		);
	}

	/**
	 * The set of owners is handed over as legacy shaped it and is not corrected: two of the implementations
	 * ignore it and work by the owner columns of the activity instead.
	 */
	public function syncBadges(
		int $activityId,
		Activity $activity,
		array $bindings,
		ActivityOperationContext $context
	): void
	{
		($this->legacyClassName)::syncBadges(
			$activityId,
			$this->toInputFields($activity, $context),
			$this->toLegacyBindings($bindings),
		);
	}

	//endregion

	//region the projection of an activity in another subsystem

	/**
	 * The set of changes is not handed on: a legacy provider decides by the state of the activity and by the
	 * options of the caller, and both of those it gets whole.
	 */
	public function updateAssociatedEntity(
		int $associatedEntityId,
		Activity $activity,
		StorageFieldTransit $transit,
		ActivityChangeSet $changes,
		ActivityOperationContext $context
	): void
	{
		($this->legacyClassName)::updateAssociatedEntity(
			$associatedEntityId,
			$this->toStoredFields($activity, $transit, $context),
			$this->associatedEntityOptionsOfAnUpdate($context),
		);
	}

	public function deleteAssociatedEntity(
		int $associatedEntityId,
		Activity $activity,
		ActivityOperationContext $context
	): void
	{
		($this->legacyClassName)::deleteAssociatedEntity(
			$associatedEntityId,
			$this->toInputFields($activity, $context),
			$this->associatedEntityOptionsOfADelete($context),
		);
	}

	public function rebindAssociatedEntity(int $associatedEntityId, ItemId $from, ItemId $to): void
	{
		($this->legacyClassName)::rebindAssociatedEntity(
			$associatedEntityId,
			$from->getEntityType()->getId(),
			$to->getEntityType()->getId(),
			$from->getId(),
			$to->getId(),
		);
	}

	//endregion

	//region what a provider hears about regardless of whose activity it is

	public function onActivityCreated(Activity $activity, ActivityOperationContext $context): void
	{
		($this->legacyClassName)::processCreation(
			$this->toInputFields($activity, $context),
			[
				'BINDINGS' => $this->toLegacyBindings($activity->getBindings() ?? []),
				'IS_RESTORATION' => $context->isRestoration(),
			],
		);
	}

	public function transferOwnership(
		OwnerType $fromOwnerType,
		int $fromOwnerId,
		OwnerType $toOwnerType,
		int $toOwnerId
	): void
	{
		($this->legacyClassName)::transferOwnership(
			$fromOwnerType->getId(),
			$fromOwnerId,
			$toOwnerType->getId(),
			$toOwnerId,
		);
	}

	public function deleteByOwner(OwnerType $ownerType, int $ownerId): void
	{
		($this->legacyClassName)::deleteByOwner($ownerType->getId(), $ownerId);
	}

	//endregion

	//region the timeline

	public function isTimelineSupported(): bool
	{
		return $this->timelineCapabilities->isTimelineSupported();
	}

	public function isSupportedByTimelineController(): bool
	{
		return $this->timelineCapabilities->isSupportedByTimelineController();
	}

	/**
	 * A legacy class has no method to build its card with and is not given one: the branches live in the
	 * factory of the cards of legacy providers, and a provider it does not recognize gets none, which is how
	 * the generic cards keep rendering it.
	 */
	public function createTimelineItem(TimelineItemRequest $request): ?object
	{
		return $this->timelineItemFactory->createTimelineItem($this->getId(), $request);
	}

	//endregion

	//region the field array of legacy

	/**
	 * The activity as legacy shaped it: the fields of the model, the serialized ones and the storage columns.
	 *
	 * The storage columns go last because they carry the richer legacy form of the two values the model also
	 * holds - the three grades of the priority and the deadline the model treats as read-only.
	 *
	 * @param bool $changedOnly Only the fields the caller touched, which is the input of an operation.
	 * @return array<string, mixed>
	 */
	private function toLegacyFields(
		Activity $activity,
		?ProviderPayload $payload,
		?StorageFieldTransit $transit,
		bool $changedOnly = false,
	): array
	{
		$fields = array_merge(
			$this->toCoreFields($activity, $changedOnly),
			$this->toPayloadFields($payload),
			$transit === null ? [] : $this->toTransitFields($transit),
		);

		$bindings = $activity->getBindings();
		if ($bindings !== null)
		{
			$fields['BINDINGS'] = $this->toLegacyBindings($bindings);
		}

		return $fields;
	}

	/**
	 * The whole input representation of an operation: the state being written, not a row read back.
	 *
	 * @return array<string, mixed>
	 */
	private function toInputFields(Activity $activity, ActivityOperationContext $context): array
	{
		return $this->toLegacyFields($activity, $context->getInputPayload(), $context->getInputTransit());
	}

	/**
	 * The activity as legacy shaped the row it re-read after a write: the state of the run rather than the input of
	 * the operation, which is the difference between the two projections - the columns of the transit are the ones
	 * the run holds, and there the re-read row answers for every column no step of the run touched.
	 *
	 * THE SERIALIZED FIELDS ARE THE ONES OF THE OPERATION AND ARE NOT READ AGAIN. Legacy has the stored ones here,
	 * because its snapshot is a plain fetch of the row; the difference is invisible to every implementation - none
	 * of them looks at the settings or at the parameters of a provider - and a read of the row for nobody would be
	 * a query per update on the hot path. Where an implementation really decides by them, the step reads them
	 * itself: {@see \Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Update\SyncBadges} does exactly that.
	 *
	 * @return array<string, mixed>
	 */
	private function toStoredFields(
		Activity $activity,
		StorageFieldTransit $transit,
		ActivityOperationContext $context,
	): array
	{
		return $this->toLegacyFields($activity, $context->getInputPayload(), $transit);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function toCoreFields(Activity $activity, bool $changedOnly): array
	{
		$fields = [];
		foreach (ActivityFieldMap::getCoreColumns() as $column)
		{
			$fieldName = ActivityFieldMap::getFieldName($column);
			if ($fieldName === null || ($changedOnly && !$activity->isFieldChanged($fieldName)))
			{
				continue;
			}

			$value = $this->readModelField($activity, $fieldName);
			if ($value !== null)
			{
				$fields[$column] = $this->toLegacyValue($column, $value);
			}
			elseif ($changedOnly)
			{
				// A field the caller cleared is the empty value of legacy, not an absent key: a provider
				// tells "no end time any more" from "the end time is none of my business" by that very
				// difference, and the provider of tasks answers the first one with a deadline of its own.
				$fields += $this->toEmptyLegacyValue($column);
			}
		}

		return $fields;
	}

	/**
	 * The empty form of the column in the field array of legacy, or nothing when it has none - a flag and an
	 * enumeration are never cleared, because the setter of neither accepts nothing.
	 *
	 * @return array<string, mixed>
	 */
	private function toEmptyLegacyValue(string $column): array
	{
		return match (ActivityFieldMap::getType($column))
		{
			ActivityFieldMap::TYPE_DATETIME, ActivityFieldMap::TYPE_STRING => [$column => ''],
			ActivityFieldMap::TYPE_INT => [$column => 0],
			default => [],
		};
	}

	/**
	 * @return array<string, mixed>
	 */
	private function toPayloadFields(?ProviderPayload $payload): array
	{
		if ($payload === null)
		{
			return [];
		}

		$fields = [];
		foreach ($payload->getValues() as $field => $value)
		{
			$fields[ActivityFieldMap::getPayloadColumn($field)] = $value;
		}

		return $fields;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function toTransitFields(StorageFieldTransit $transit): array
	{
		$fields = $transit->getValues();
		if (isset($fields['DEADLINE']) && $fields['DEADLINE'] instanceof DateTime)
		{
			$fields['DEADLINE'] = $fields['DEADLINE']->toString();
		}

		return $fields;
	}

	/**
	 * One side of a set of changes as legacy would have read it off a row.
	 *
	 * Only the names that have a column of their own are here: what a change is about and what a row holds
	 * do not coincide, and inventing a key for a name that has no column would put a value where no legacy
	 * provider looks for one.
	 *
	 * @return array<string, mixed>
	 */
	private function toChangeSetFields(ActivityChangeSet $changes, bool $previous): array
	{
		$columns = [
			ActivityChangeSet::isCompleted => 'COMPLETED',
			ActivityChangeSet::completionStatus => 'STATUS',
			ActivityChangeSet::responsibleId => 'RESPONSIBLE_ID',
			ActivityChangeSet::deadline => 'DEADLINE',
			ActivityChangeSet::pingOffsets => 'PING_OFFSETS',
			ActivityChangeSet::storageElements => 'STORAGE_ELEMENT_IDS',
		];

		$fields = [];
		foreach ($columns as $name => $column)
		{
			if (!$changes->has($name))
			{
				continue;
			}

			$value = $previous ? $changes->getPrevious($name) : $changes->getCurrent($name);
			if ($value === null)
			{
				continue;
			}

			// The reminder offsets travel next to a row rather than in one, so nothing converts them.
			$fields[$column] = ActivityFieldMap::hasColumn($column)
				? $this->toLegacyValue($column, $value)
				: $value
			;
		}

		if ($changes->has(ActivityChangeSet::bindings))
		{
			$bindings = $previous
				? $changes->getPrevious(ActivityChangeSet::bindings)
				: $changes->getCurrent(ActivityChangeSet::bindings)
			;
			$fields['BINDINGS'] = $this->toLegacyBindings($bindings);
		}

		if ($changes->has(ActivityChangeSet::notify))
		{
			$notify = $previous
				? $changes->getPrevious(ActivityChangeSet::notify)
				: $changes->getCurrent(ActivityChangeSet::notify)
			;
			$fields['NOTIFY_TYPE'] = $notify['type'];
			$fields['NOTIFY_VALUE'] = $notify['value'];
		}

		return $fields;
	}

	/**
	 * @param ActivityBinding[] $bindings
	 * @return array<int, array{OWNER_TYPE_ID: int, OWNER_ID: int}>
	 */
	private function toLegacyBindings(array $bindings): array
	{
		$legacyBindings = [];
		foreach ($bindings as $binding)
		{
			$legacyBindings[] = [
				'OWNER_TYPE_ID' => $binding->getOwnerType()->getId(),
				'OWNER_ID' => $binding->getOwnerId(),
			];
		}

		return $legacyBindings;
	}

	/**
	 * Puts every value the legacy provider changed back where its column belongs.
	 *
	 * A key that is no column of an activity is dropped, and so is a change to a column the model assigns
	 * itself - the identifier, the audit stamps and the flag of an incoming channel. See the note on the
	 * class for what that costs today.
	 *
	 * @param array<string, mixed> $before
	 * @param array<string, mixed> $after
	 */
	private function applyLegacyFields(
		array $before,
		array $after,
		Activity $activity,
		ProviderPayload $payload,
		StorageFieldTransit $transit,
	): void
	{
		foreach ($after as $column => $value)
		{
			if (array_key_exists($column, $before) && $before[$column] === $value)
			{
				continue;
			}

			$this->writeLegacyValue($column, $value, $activity, $payload, $transit);
		}

		foreach (array_keys(array_diff_key($before, $after)) as $column)
		{
			$this->removeLegacyValue((string)$column, $payload, $transit);
		}
	}

	private function writeLegacyValue(
		string $column,
		mixed $value,
		Activity $activity,
		ProviderPayload $payload,
		StorageFieldTransit $transit,
	): void
	{
		// The one channel a legacy provider has to a column the model treats as read-only.
		if ($column === 'DEADLINE')
		{
			$transit->set('DEADLINE', $this->toModelDateTime($column, $value));

			return;
		}

		if ($column === '~DEADLINE')
		{
			$transit->set('DEADLINE', $this->toRawDeadline($value));

			return;
		}

		$payloadField = $this->payloadFieldOfColumn($column);
		if ($payloadField !== null)
		{
			$payload->set($payloadField, $value);

			return;
		}

		if (in_array($column, StorageFieldTransit::getSupportedFields(), true))
		{
			$transit->set($column, $value);

			return;
		}

		$this->writeModelColumn($activity, $column, $value);
	}

	private function removeLegacyValue(
		string $column,
		ProviderPayload $payload,
		StorageFieldTransit $transit,
	): void
	{
		$payloadField = $this->payloadFieldOfColumn($column);
		if ($payloadField !== null)
		{
			$payload->remove($payloadField);
		}
		elseif (in_array($column, StorageFieldTransit::getSupportedFields(), true))
		{
			$transit->remove($column);
		}
	}

	private function payloadFieldOfColumn(string $column): ?string
	{
		foreach (ProviderPayload::getSupportedFields() as $field)
		{
			if (ActivityFieldMap::getPayloadColumn($field) === $column)
			{
				return $field;
			}
		}

		return null;
	}

	/**
	 * Writes one value of the legacy field array onto the model, and ignores what the model does not own: a
	 * key that is no column of an activity at all, and a column the storage assigns itself.
	 */
	private function writeModelColumn(Activity $activity, string $column, mixed $value): void
	{
		if (!in_array($column, self::WRITABLE_COLUMNS, true))
		{
			return;
		}

		if ($value === null && !in_array($column, self::NULLABLE_COLUMNS, true))
		{
			return;
		}

		$modelValue = $this->toModelValue($column, $value);

		match ($column)
		{
			'PROVIDER_ID' => $activity->setProviderId((string)$modelValue),
			'PROVIDER_TYPE_ID' => $activity->setProviderTypeId($modelValue === null ? null : (string)$modelValue),
			'SUBJECT' => $activity->setSubject((string)$modelValue),
			'DESCRIPTION' => $activity->setDescription((string)$modelValue),
			'DESCRIPTION_TYPE' => $activity->setDescriptionType($modelValue),
			'COMPLETED' => $activity->setCompleted((bool)$modelValue),
			'STATUS' => $activity->setCompletionStatus($modelValue),
			'RESPONSIBLE_ID' => $activity->setResponsibleId((int)$modelValue),
			'AUTHOR_ID' => $activity->setAuthorId((int)$modelValue),
			'EDITOR_ID' => $activity->setEditorId((int)$modelValue),
			'DIRECTION' => $activity->setDirection($modelValue),
			'START_TIME' => $activity->setStartTime($modelValue),
			'END_TIME' => $activity->setEndTime($modelValue),
			'PARENT_ID' => $activity->setParentActivityId($modelValue),
			'AUTOCOMPLETE_RULE' => $activity->setAutocompleteRule($modelValue),
			default => null,
		};
	}

	/**
	 * The value of a model field as the field array of legacy carries it.
	 */
	private function toLegacyValue(string $column, mixed $value): mixed
	{
		return match ($column)
		{
			// A boolean would read as a completed activity for either of its two values.
			'COMPLETED' => $value === true ? 'Y' : 'N',
			'CREATED', 'LAST_UPDATED', 'START_TIME', 'END_TIME', 'DEADLINE' => $value instanceof DateTime
				? $value->toString()
				: $value
			,
			default => ActivityFieldMap::toColumnValue($column, $value),
		};
	}

	/**
	 * The value of the field array of legacy as the model holds it.
	 */
	private function toModelValue(string $column, mixed $value): mixed
	{
		return match ($column)
		{
			'COMPLETED' => $value === 'Y',
			'START_TIME', 'END_TIME' => $this->toModelDateTime($column, $value),
			default => ActivityFieldMap::toFieldValue($column, $value),
		};
	}

	/**
	 * @throws ArgumentException when the provider wrote something no date can be read out of.
	 */
	private function toModelDateTime(string $column, mixed $value): ?DateTime
	{
		if ($value instanceof DateTime)
		{
			return $value;
		}

		if (!is_string($value) || $value === '')
		{
			return null;
		}

		$dateTime = DateTime::tryParse($value);
		if ($dateTime === null)
		{
			throw new ArgumentException(
				"The activity provider '{$this->legacyClassName}' wrote '{$value}' into {$column},"
				. ' which is no date'
			);
		}

		return $dateTime;
	}

	/**
	 * The raw channel of a deadline hands the write path a literal to insert as it is, quoting included, and
	 * the one literal legacy ever puts through it is the date the column uses instead of NULL - which is to
	 * say no deadline at all, expressed here the way the field map expresses it. Anything else is refused
	 * rather than guessed: a literal of the database is not something this class can read a date out of.
	 *
	 * @throws ArgumentException when the provider wrote a raw literal that is not that date.
	 */
	private function toRawDeadline(mixed $value): DateTime
	{
		if ($value instanceof DateTime)
		{
			return $value;
		}

		if (is_string($value) && $value === CCrmDateTimeHelper::GetMaxDatabaseDate())
		{
			return ActivityFieldMap::getDeadlineSentinel();
		}

		throw new ArgumentException(
			"The activity provider '{$this->legacyClassName}' wrote a raw deadline this class cannot read"
		);
	}

	private function readModelField(Activity $activity, string $fieldName): mixed
	{
		return match ($fieldName)
		{
			Activity::id => $activity->getId(),
			Activity::providerId => $activity->getProviderId(),
			Activity::providerTypeId => $activity->getProviderTypeId(),
			Activity::subject => $activity->getSubject(),
			Activity::description => $activity->getDescription(),
			Activity::descriptionType => $activity->getDescriptionType(),
			Activity::isCompleted => $activity->isCompleted(),
			Activity::completionStatus => $activity->getCompletionStatus(),
			Activity::responsibleId => $activity->getResponsibleId(),
			Activity::authorId => $activity->getAuthorId(),
			Activity::editorId => $activity->getEditorId(),
			Activity::isImportant => $activity->isImportant(),
			Activity::direction => $activity->getDirection(),
			Activity::createdTime => $activity->getCreatedTime(),
			Activity::updatedTime => $activity->getUpdatedTime(),
			Activity::startTime => $activity->getStartTime(),
			Activity::endTime => $activity->getEndTime(),
			Activity::deadline => $activity->getDeadline(),
			Activity::parentActivityId => $activity->getParentActivityId(),
			Activity::autocompleteRule => $activity->getAutocompleteRule(),
			default => null,
		};
	}

	//endregion

	//region the options of legacy

	/**
	 * @return array<string, mixed>
	 */
	private function validationParams(ValidationContext $context): array
	{
		$params = [
			'CURRENT_USER' => $context->getUserId(),
			'INITIATED_BY_CALENDAR' => $context->isInitiatedByCalendar(),
		];

		if ($context->isAdd())
		{
			$params['PRESERVE_CREATION_TIME'] = $context->shouldPreserveCreationTime();

			return $params;
		}

		$params['PREVIOUS_FIELDS'] = $this->toLegacyFields(
			$context->getPreviousActivity(),
			$context->getPreviousPayload(),
			$context->getPreviousTransit(),
		);

		return $params;
	}

	/**
	 * The options an update hands its provider: the set of the caller, with the user of the operation written back
	 * into it exactly as legacy writes it back before it reaches this point (:521-523, :1018).
	 *
	 * THE WHOLE SET TRAVELS, BECAUSE THE KEYS OF A PROVIDER ARE ITS OWN. Three of them belong to the provider of
	 * tasks alone - leaving the subject, the responsible or the renewal of a task alone - and no rule of the domain
	 * reads any of the three, so the closed projection of the options has no row for them and never will
	 * ({@see ActivityOperationContext::getLegacyOptions()}).
	 *
	 * THE RAW USER IS WHY THE TWO USER KEYS ARE WRITTEN OVER THE SET: the provider of tasks picks who performs a
	 * task by the named executor, then that raw value, then the person responsible, so collapsing the raw value into
	 * the effective one silently reassigns tasks. The executor is written back only where the operation really names
	 * one - a zero under that key is not the absence of it, and it would cut the chain at its first link.
	 *
	 * @return array<string, mixed>
	 */
	private function associatedEntityOptionsOfAnUpdate(ActivityOperationContext $context): array
	{
		$options = $context->getLegacyOptions();
		$options['CURRENT_USER'] = $context->getCurrentUserId();
		$options['~CURRENT_USER'] = $context->getRawCurrentUser();

		if ($context->getExecutorId() > 0)
		{
			$options['EXECUTOR_ID'] = $context->getExecutorId();
		}

		return $options;
	}

	/**
	 * The options a delete hands its provider: the set of the caller as it was given and nothing written over it -
	 * legacy hands its own array on untouched (:1496), and neither user key of an update is written back by a delete
	 * at all. Two keys of a provider live in that set: taking the task of the activity along and erasing the
	 * document of one while the recycle bin is being emptied.
	 *
	 * The demand to leave the tasks alone is stated over the set for the operations that named it typed rather than
	 * through an option array, so that a caller of the service reaches the provider with it too.
	 *
	 * @return array<string, mixed>
	 */
	private function associatedEntityOptionsOfADelete(ActivityOperationContext $context): array
	{
		$options = $context->getLegacyOptions();
		if ($context->shouldSkipTasks())
		{
			$options['SKIP_TASKS'] = true;
		}

		return $options;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function calendarOptions(ActivityOperationContext $context): array
	{
		$options = ['SKIP_CURRENT_CALENDAR_EVENT' => $context->shouldSkipCurrentCalendarEvent()];

		$scope = $context->getScope();
		if ($scope !== null)
		{
			$options['CONTEXT'] = (new Context())->setScope($this->toLegacyScope($scope));
		}

		return $options;
	}

	/**
	 * Only one of the legacy scopes is ever compared - an automated origin skips the check of the calendar
	 * section - so the two V2 scopes legacy never had are mapped onto its non-interactive one rather than
	 * onto the scope of a person acting by hand.
	 */
	private function toLegacyScope(Scope $scope): string
	{
		return match ($scope)
		{
			Scope::Manual => Context::SCOPE_MANUAL,
			Scope::Rest => Context::SCOPE_REST,
			Scope::Automation => Context::SCOPE_AUTOMATION,
			Scope::Ai => Context::SCOPE_AI,
			Scope::Import, Scope::System => Context::SCOPE_TASK,
		};
	}

	//endregion

	//region what the legacy class states itself

	/**
	 * The columns of the row the legacy permission hooks read and no typed model holds: the owner of the
	 * activity, the row of another subsystem it projects and the settings of its provider.
	 *
	 * Read past the repository of activities, as the restriction on deleting calls is: none of the four
	 * reaches a model of the domain, and the storage boundary publishes no reader for them. Remembered per
	 * activity, because the five operations are asked about one activity one after another.
	 *
	 * @return array<string, mixed>
	 */
	private function permissionFields(Activity $activity): array
	{
		$activityId = $activity->getId() ?? 0;
		if (!isset($this->permissionColumns[$activityId]))
		{
			$row = $activityId > 0
				? ActivityTable::getList([
					'select' => self::PERMISSION_COLUMNS,
					'filter' => ['=ID' => $activityId],
				])->fetch()
				: false
			;

			$this->permissionColumns[$activityId] = is_array($row)
				? $row
				: array_fill_keys(self::PERMISSION_COLUMNS, null)
			;
		}

		return array_merge(
			$this->toLegacyFields($activity, null, null),
			$this->permissionColumns[$activityId],
		);
	}

	/**
	 * The legacy hook of the operation, or null when legacy never had one - a delete asks no provider.
	 */
	private function permissionHook(Operation $operation): ?string
	{
		return match ($operation)
		{
			Operation::Read => 'checkReadPermission',
			Operation::Update => 'checkUpdatePermission',
			Operation::Complete => 'checkCompletePermission',
			Operation::Postpone => 'checkPostponePermission',
			Operation::Delete => null,
		};
	}

	private function hasOwnOverride(string $method): bool
	{
		return self::hasOwnOverrideOf($this->legacyClassName, $method);
	}

	/**
	 * Whether the legacy class declares this method itself rather than inheriting it from its base class - a
	 * declaration by an intermediate class counts, which is how the four providers of messages inherit the
	 * refusal to be completed from the one they share.
	 *
	 * ONE METHOD IS ASKED ABOUT AT A TIME. Walking the whole class answered every question at once, but the
	 * questions ever asked are the methods of the capabilities and the hooks of the operations - a closed set
	 * of two dozen names, against the sixty methods a provider inherits from its base class.
	 *
	 * @throws ArgumentException when the class is not a legacy provider.
	 */
	private static function hasOwnOverrideOf(string $legacyClassName, string $method): bool
	{
		self::assertLegacyProvider($legacyClassName);

		return self::$ownOverrides[$legacyClassName][$method] ??= method_exists($legacyClassName, $method)
			&& (new ReflectionMethod($legacyClassName, $method))->getDeclaringClass()->getName()
				!== Provider\Base::class
		;
	}

	/**
	 * @throws ArgumentException when the class is not a legacy provider.
	 */
	private static function assertLegacyProvider(string $legacyClassName): void
	{
		if (!is_subclass_of($legacyClassName, Provider\Base::class))
		{
			throw new ArgumentException(
				"'{$legacyClassName}' is not a legacy activity provider, so there is nothing to wrap"
			);
		}
	}

	//endregion
}
