<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity;

use Bitrix\Crm\V2\Internal\Entity\Activity\ProviderPayload;
use Bitrix\Crm\V2\Internal\Entity\Activity\StorageFieldTransit;
use Bitrix\Crm\V2\Public\Command\Item\Scope;
use Bitrix\Main\Analytics\AnalyticsEvent;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Type\DateTime;

/**
 * Everything about an operation over an activity, besides the activity itself, that the capabilities of its
 * provider are allowed to read. The typed replacement of the option array legacy carried from one entry point
 * down to every hook.
 *
 * THE REPRESENTATION IT CARRIES IS THE GIVEN ONE, not a snapshot re-read from storage. That distinction is
 * load-bearing on an update: legacy handed the input fields to the predicate that decides whether the calendar
 * is skipped, while the hook of an associated entity got the re-read row, and the two differ by exactly the
 * change being written. The columns and the serialized fields of the input are here because the typed model
 * holds neither - the identifier of a bound calendar event is a column, the section of a calendar lives in the
 * settings - so a capability given the model alone could not answer at all.
 *
 * TWO VALUES FOR ONE USER, told apart the way the validation context of the provider layer already tells them
 * apart rather than by a second mechanism of its own. The effective identifier is the one to act as, after the
 * fallbacks of the operation; the raw one is what the caller actually named, zero when it named nobody -
 * legacy stored that very value, cast to an integer, alongside the effective one. Collapsing them breaks the
 * chain that picks the executor of a task on the other side of the integration with the module of tasks: it
 * takes the named executor, then the raw value, then the person responsible, and a raw value silently replaced
 * by the effective one makes the second link always match.
 *
 * THE DEFAULTS ARE THE LEGACY ONES AND THEY ARE NOT NEUTRAL. Skipping the current calendar event defaults to
 * true because an absent option meant exactly that to the only provider reading it, and the one caller that
 * wants an event created passes false. A default chosen for looking harmless would silently stop creating
 * calendar events.
 *
 * THE PROJECTION IS LITERAL AND THE COMPOSITE RULES STAY WITH THE STEPS. Every value here is what the caller
 * asked for and nothing more. Skipping the files is forced by a transfer to the recycle bin, and enabling the
 * recycle bin is combined with the setting of the module - a projection applying either rule would apply it
 * twice, once here and once in the step that owns it, and the step is the one that owns it.
 *
 * THE SOURCE OF THE CALL IS TOLD, NEVER GUESSED. The caller names where the operation came from, and
 * nothing here derives it from the shape of the input or from the stack of calls. Saying nothing means
 * the legacy facade, which is the conservative answer rather than the neutral one: an unknown entry
 * treated as the native one would produce the notifications about the person responsible and the record
 * of the activity stream twice over, while an entry mistaken for the facade produces them the way the
 * portal already produces them today. The question to ask of the value is "is this the facade"
 * ({@see self::isFacadeEntry()}) and never "is this the native one": the reserved third value belongs to
 * the native side, so a check written the other way round would silence both sides of that pair at once.
 *
 * THE LIST IS CLOSED (DTO-02). The dead keys of legacy are not on it and nothing accepts them: their
 * appearance here would be a regression. Mapper\LegacyOptionsMapper is the single projection of the legacy
 * option array into this class, and it is the place where a row is added when legacy grows one.
 *
 * ONE ROW IS NOT PART OF THAT CLOSED LIST AND IS NO DECISION OF THE DOMAIN EITHER: the option array of the caller
 * as it was given ({@see self::getLegacyOptions()}). It exists because a legacy provider reads keys of its own out
 * of that array and legacy hands it the whole of it; its only reader is the wrapper of a legacy provider, and
 * nothing of V2 may ask it anything.
 *
 * NOT EVERYTHING HERE COMES OUT OF THE OPTION ARRAY. Three values are named by the caller directly and have no
 * legacy key behind them: the source of the call, which legacy does not have at all, and two that legacy takes
 * as positional arguments of each of its three write entry points - whether a record of the history of the CRM
 * is written, and whether the operation checks what it checks of the rights. The facade is what names all three;
 * the projection of the options forwards them and derives none of them from a key.
 *
 * WIDE ON PURPOSE. An operation over an activity really does have this many named values, and a data carrier
 * of one value per row is the honest form of that: the alternatives were a bag of untyped keys, which is the
 * legacy array this class replaces, and a carrier per operation, which the entry points do not have, because
 * legacy hands one option array to every operation and lets each read what it needs. Every caller names its
 * arguments. If the list grows again, split it by operation instead of continuing the list.
 *
 * @internal
 */
final class ActivityOperationContext
{
	/**
	 * The legacy facade of an activity: `CCrmActivity` itself, the legacy REST methods behind it and the
	 * mass operations. The value assumed when the caller names none.
	 */
	public const ENTRY_SOURCE_FACADE = 'facade';

	/**
	 * A direct call of the internal service, the way the pilot of a to-do calls it.
	 */
	public const ENTRY_SOURCE_NATIVE = 'native';

	/**
	 * A call through the public command, reserved until the public layer is published. It belongs to the
	 * native side of the pair, not to a third one.
	 */
	public const ENTRY_SOURCE_PUBLIC_COMMAND = 'publicCommand';

	private const ENTRY_SOURCES = [
		self::ENTRY_SOURCE_FACADE,
		self::ENTRY_SOURCE_NATIVE,
		self::ENTRY_SOURCE_PUBLIC_COMMAND,
	];

	/**
	 * @throws ArgumentException on a source of the call outside the closed list above. A value nobody
	 *         recognizes is a mistake of the caller, and guessing which side of the pair it meant is
	 *         exactly what this field exists to stop.
	 */
	public function __construct(
		private readonly ProviderPayload $inputPayload,
		private readonly StorageFieldTransit $inputTransit,
		private readonly int $currentUserId = 0,
		private readonly int $rawCurrentUser = 0,
		private readonly int $executorId = 0,
		private readonly bool $isRestoration = false,
		private readonly bool $skipCurrentCalendarEvent = true,
		private readonly ?Scope $scope = null,
		private readonly bool $initiatedByCalendar = false,
		private readonly bool $preserveCreationTime = false,
		private readonly ?DateTime $customCreationTime = null,
		private readonly bool $skipCalendarEvent = false,
		private readonly bool $registerSonetEvent = false,
		private readonly array $moveBindingsMap = [],
		private readonly bool $skipAssociatedEntity = false,
		private readonly bool $skipDeadlineChange = false,
		private readonly ?AnalyticsEvent $analyticsEvent = null,
		private readonly ?array $actualItem = null,
		private readonly ?array $actualBindings = null,
		private readonly bool $movedToRecycleBin = false,
		private readonly ?bool $enableRecycleBin = null,
		private readonly ?int $recycleBinForceUserId = null,
		private readonly bool $skipStatistics = false,
		private readonly bool $skipBindings = false,
		private readonly bool $skipCommunications = false,
		private readonly bool $skipFiles = false,
		private readonly bool $skipUserActivitySync = false,
		private readonly bool $skipTasks = false,
		private readonly bool $skipBeforeHandler = false,
		private readonly bool $checkBindingsBeforeAutoComplete = false,
		private readonly string $entrySource = self::ENTRY_SOURCE_FACADE,
		private readonly bool $registerHistoryEvent = true,
		private readonly bool $checkPermissions = true,
		private readonly array $userFieldValues = [],
		private readonly array $calendarInput = [],
		private readonly array $incorrectMomentColumns = [],
		private readonly array $legacyOptions = [],
		private readonly bool $calledWithNoField = false,
	)
	{
		if (!in_array($entrySource, self::ENTRY_SOURCES, true))
		{
			throw new ArgumentException("'{$entrySource}' is not a source an operation over an activity comes from");
		}
	}

	/**
	 * The serialized fields of the input: the settings, the provider params, the provider data.
	 */
	public function getInputPayload(): ProviderPayload
	{
		return $this->inputPayload;
	}

	/**
	 * The storage columns of the input that the typed model does not hold.
	 */
	public function getInputTransit(): StorageFieldTransit
	{
		return $this->inputTransit;
	}

	/**
	 * The user to act as. Zero is a system caller, and a delete treats it as a reason to skip the restriction
	 * on deleting calls rather than as a user without rights.
	 */
	public function getCurrentUserId(): int
	{
		return $this->currentUserId;
	}

	/**
	 * The user the caller named, zero when it named nobody. Not interchangeable with
	 * {@see self::getCurrentUserId()}.
	 */
	public function getRawCurrentUser(): int
	{
		return $this->rawCurrentUser;
	}

	/**
	 * Who performs the operation when that is somebody other than the acting user, zero when unnamed. It is
	 * the first link of the chain choosing the executor of an associated task.
	 */
	public function getExecutorId(): int
	{
		return $this->executorId;
	}

	/**
	 * Whether the activity is being recreated out of the recycle bin rather than created anew.
	 */
	public function isRestoration(): bool
	{
		return $this->isRestoration;
	}

	/**
	 * Whether this operation refrains from creating a calendar event of its own. True unless the caller says
	 * otherwise - see the note on the defaults above.
	 */
	public function shouldSkipCurrentCalendarEvent(): bool
	{
		return $this->skipCurrentCalendarEvent;
	}

	/**
	 * Where the operation came from, or null when the caller named nothing. A provider synchronized with a
	 * calendar treats an automated origin differently from a person acting by hand.
	 */
	public function getScope(): ?Scope
	{
		return $this->scope;
	}

	/**
	 * Whether the calendar started this operation. Part of the validation context of a provider, and the
	 * reason the provider of a to-do stops overwriting the description, the colour and the attendees.
	 */
	public function isInitiatedByCalendar(): bool
	{
		return $this->initiatedByCalendar;
	}

	/**
	 * Whether the time of creation given by the caller survives the normalization of an add. Legacy compares
	 * this one strictly with true, both where it keeps the field and where it hands it to the timeline.
	 */
	public function shouldPreserveCreationTime(): bool
	{
		return $this->preserveCreationTime;
	}

	/**
	 * The moment the history record of the timeline is dated by instead of now, or null when the caller named
	 * none. Its only reader is the timeline, and it exists to sort a call that closes itself.
	 */
	public function getCustomCreationTime(): ?DateTime
	{
		return $this->customCreationTime;
	}

	/**
	 * Whether the whole calendar block of the operation is off. Wider than
	 * {@see self::shouldSkipCurrentCalendarEvent()}: this one silences creating, saving and deleting an event
	 * alike, and on a delete it is the only thing between the activity and its calendar event.
	 */
	public function shouldSkipCalendarEvent(): bool
	{
		return $this->skipCalendarEvent;
	}

	/**
	 * Whether the operation writes a record of the live feed. The gate of the transitional layer: legacy
	 * compares strictly with true, so an option of "Y" or 1 means no record, and a provider refusing the live
	 * feed switches this off after the fact rather than here.
	 */
	public function shouldRegisterSonetEvent(): bool
	{
		return $this->registerSonetEvent;
	}

	/**
	 * Where the bindings of the activity are being moved from and to, as the pair of item identifiers under
	 * the keys "source" and "target", or nothing. Both readers - the badges of a change of bindings and the
	 * record of the timeline about the move - check the type of what they find and pass over anything else,
	 * so the pair travels exactly as it was given.
	 *
	 * @return array<string, mixed>
	 */
	public function getMoveBindingsMap(): array
	{
		return $this->moveBindingsMap;
	}

	/**
	 * Whether the entity of a provider bound to this activity is left alone: no task is updated on an update
	 * and none is deleted on a delete. The most fragile of the options - the synchronization it silences
	 * works in both directions.
	 */
	public function shouldSkipAssociatedEntity(): bool
	{
		return $this->skipAssociatedEntity;
	}

	/**
	 * Whether the deadline of the activity is kept out of the update of an associated task. Read by the
	 * legacy provider of tasks alone, and strictly compared with true there.
	 */
	public function shouldSkipDeadlineChange(): bool
	{
		return $this->skipDeadlineChange;
	}

	/**
	 * The event of the analytics the caller wants an associated task to be closed under, or null when it
	 * named none and the provider derives one of its own. The one option of an update that is not a flag of
	 * the core at all: its only producer is the automation closing a task by a stage, and its only reader is
	 * the provider of tasks.
	 */
	public function getAnalyticsEvent(): ?AnalyticsEvent
	{
		return $this->analyticsEvent;
	}

	/**
	 * The row of the activity the caller has already read, in the field shape of legacy, or null when the
	 * operation reads it itself. Substituting the read is what also bypasses the check of the right to read
	 * it, which is why the callers passing it are the mass operations of the system.
	 *
	 * The shape stays the legacy one on purpose: the row carries the columns of the storage and the
	 * serialized fields the typed model does not hold, and a delete reads exactly those - the identifier of
	 * an associated entity, the identifier of a calendar event, the settings. See the note of the class.
	 *
	 * @return array<string, mixed>|null
	 */
	public function getActualItem(): ?array
	{
		return $this->actualItem;
	}

	/**
	 * The bindings the caller has already read, in the legacy shape of a list of owner type and owner
	 * identifier, or null when the operation reads them itself.
	 *
	 * @return array<int, array<string, mixed>>|null
	 */
	public function getActualBindings(): ?array
	{
		return $this->actualBindings;
	}

	/**
	 * Whether the activity has already been moved to the recycle bin, which makes this delete the erasing of
	 * a row rather than a deletion of an activity: the transfer is not attempted again, the files are kept,
	 * the live feed is not cleaned and the timeline reports a suspended activity.
	 */
	public function isMovedToRecycleBin(): bool
	{
		return $this->movedToRecycleBin;
	}

	/**
	 * Whether the caller demands the recycle bin, null when it said nothing and the setting of the module
	 * decides alone. Today the setting answers true unconditionally, so nothing observable depends on this
	 * value (see the registry of the deviations, RECYCLE-ALWAYS-ON) - it is carried because the demand of the
	 * caller and the silence of the caller are two different statements.
	 */
	public function shouldEnableRecycleBin(): ?bool
	{
		return $this->enableRecycleBin;
	}

	/**
	 * Who owns the record of the recycle bin instead of the acting user, or null when the caller named
	 * nobody. Only a positive value is used.
	 */
	public function getRecycleBinForceUserId(): ?int
	{
		return $this->recycleBinForceUserId;
	}

	/**
	 * Whether the statistics of a deal or a lead and the statistics of communications are left alone by a
	 * delete. It does not silence unregistering the statistic entry of the activity itself, nor unbinding its
	 * channels: both stay outside this option, exactly as in legacy.
	 */
	public function shouldSkipStatistics(): bool
	{
		return $this->skipStatistics;
	}

	/**
	 * Whether the rows of the bindings survive a delete. Set by the mass operations that have already deleted
	 * them themselves.
	 */
	public function shouldSkipBindings(): bool
	{
		return $this->skipBindings;
	}

	/**
	 * Whether the rows of the communications survive a delete.
	 */
	public function shouldSkipCommunications(): bool
	{
		return $this->skipCommunications;
	}

	/**
	 * Whether the files of the activity survive a delete, as the caller asked. A transfer to the recycle bin
	 * forces the same thing, and that force belongs to the step: it is not applied here, or it would be
	 * applied twice.
	 */
	public function shouldSkipFiles(): bool
	{
		return $this->skipFiles;
	}

	/**
	 * Whether the synchronization of the table of the activities of a user is skipped. Accepted for the sake
	 * of the mass operations that go on passing it; the effect itself is gone from V2 (see the registry of
	 * the deviations, C8-USR-ACT), so nothing reads this and passing it is not an error.
	 */
	public function shouldSkipUserActivitySync(): bool
	{
		return $this->skipUserActivitySync;
	}

	/**
	 * Whether an associated task survives the deletion of its activity. Not read by the core at all: it is
	 * handed to the deletion of an associated entity, and the core itself sets it when the portal keeps the
	 * tasks of unbound activities. Strictly compared with true by both providers reading it.
	 */
	public function shouldSkipTasks(): bool
	{
		return $this->skipTasks;
	}

	/**
	 * Whether the hook a provider gets before a completion is skipped. The hook has no veto, so this changes
	 * what the provider learns, not whether the activity is completed.
	 */
	public function shouldSkipBeforeHandler(): bool
	{
		return $this->skipBeforeHandler;
	}

	/**
	 * Whether the mass completion by an owner passes over an activity that is still bound to something open.
	 * In legacy this switch is a setting of the module rather than a key of the option array, and the mass
	 * operation is its only reader: the projection carries the decision instead of reading the setting again
	 * deep inside the operation.
	 */
	public function shouldCheckBindingsBeforeAutoComplete(): bool
	{
		return $this->checkBindingsBeforeAutoComplete;
	}

	/**
	 * Where the operation came from, as the caller named it - one of the three `ENTRY_SOURCE_*` values.
	 * Carried for the sake of diagnostics and of the predicate below; the decisions of the pipeline are
	 * taken through {@see self::isFacadeEntry()} rather than by comparing this value.
	 */
	public function getEntrySource(): string
	{
		return $this->entrySource;
	}

	/**
	 * Whether the operation came through the legacy facade.
	 *
	 * THE ONLY SHAPE OF THE QUESTION. Exactly two effects depend on the source of the call - the
	 * extracted step notifying the person responsible over the messenger, and the transitional layer of
	 * the activity stream - and they are mutually exclusive because both are defined as "is this the
	 * facade": one runs when it is, the other when it is not. Widening that list means adding a row to
	 * the registry of the deviations first.
	 */
	public function isFacadeEntry(): bool
	{
		return $this->entrySource === self::ENTRY_SOURCE_FACADE;
	}

	/**
	 * Whether the operation writes a record into the history of the CRM. It is no key of the option array:
	 * legacy takes it as a positional argument of every one of its three write entry points, and it governs
	 * that one effect and nothing else (item A4 of LBS-1, section 2.3). Saying nothing means it does, which
	 * is the default of legacy; the callers that pass false are the ones restoring or migrating rows.
	 */
	public function shouldRegisterHistoryEvent(): bool
	{
		return $this->registerHistoryEvent;
	}

	/**
	 * Whether the operation performs the checks of the rights it has. It is no key of the option array either:
	 * legacy takes it as a positional argument of every one of its three write entry points and compares it
	 * strictly with true, and there is exactly one such check in the write paths - the restriction on deleting the
	 * record of a call (row 3 of the owner table of a delete). Saying nothing means the checks happen, which is
	 * the default of legacy; the callers passing false are the providers, the cleanups of volume and the mass
	 * operations, and they pass it while acting as a real user - so this is not the same statement as acting as
	 * nobody. The hidden check of the right to READ an activity that legacy performs with the same switch belongs
	 * to the facade over the legacy entry point.
	 */
	public function shouldCheckPermissions(): bool
	{
		return $this->checkPermissions;
	}

	/**
	 * The values of the user fields of the activity the caller named, by the name of each (`UF_*`), and an empty
	 * set where it named none.
	 *
	 * THEY ARE HERE BECAUSE THEY ARE VALUES OF THE OPERATION AND OF NOTHING ELSE. No column of the row holds one,
	 * the manager of the user fields of the portal stores them in tables of its own, and legacy carries them in the
	 * very field array this class replaces - so the carrier of them is the representation the caller gave, and
	 * their one reader is the step writing them. The set is not filtered against the fields the portal declares:
	 * the manager ignores what it does not know, exactly as it does for legacy.
	 *
	 * @return array<string, mixed>
	 */
	public function getUserFieldValues(): array
	{
		return $this->userFieldValues;
	}

	/**
	 * What the caller said about the calendar event of the activity and about nothing else, under the names legacy
	 * gives those keys: the section, the access codes of the attendees, the flag of a meeting and the user field of
	 * the bound objects. An empty set where the caller named none.
	 *
	 * FOUR KEYS THAT ARE NO COLUMNS OF AN ACTIVITY. They are arguments of the call to the calendar - the closed
	 * `INPUT_*` list of the calendar layer - so neither the model nor the payload nor the transit may hold them,
	 * and without a carrier a meeting created through V2 reached the calendar with no attendees at all. They stay
	 * under their legacy names here, because this class carries the representation the caller gave; the step
	 * synchronizing the event is what names them the way the layer names them.
	 *
	 * @return array<string, mixed>
	 */
	public function getCalendarInput(): array
	{
		return $this->calendarInput;
	}

	/**
	 * The datetime columns the caller filled with something that names no moment, and an empty set where every
	 * value it named is one. The one reader is the check of the fields, which refuses the operation over them the
	 * way legacy refuses it.
	 *
	 * IT IS HERE FOR THE SAME REASON THE VALUES OF THE USER FIELDS ARE: no column of the row and no field of the
	 * model can hold it. The typed moments of the model answer "no moment" for a text that names none and for a
	 * text nobody gave alike, and legacy tells those two apart - it refuses the first and accepts the second - so
	 * the statement has to travel beside them, and what travels beside the activity is the operation. It is a
	 * value of the OPERATION and no decision of the domain: which columns are named here is a property of the
	 * legacy values the caller handed over, and what follows from a non-empty set is decided by the one step that
	 * refuses ({@see \Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\ValidateAndNormalize}).
	 *
	 * @return string[]
	 */
	public function getIncorrectMomentColumns(): array
	{
		return $this->incorrectMomentColumns;
	}

	/**
	 * Whether the caller named no field at all - an empty field array, which legacy refuses both an add and an
	 * update over at the top of its check of the fields (crm_activity.php:2227-2233, `Fields is not specified.`).
	 * The one reader is that same check ({@see Pipeline\Step\Shared\ValidateAndNormalize}).
	 *
	 * IT IS HERE FOR THE SAME REASON THE INCORRECT MOMENTS ARE: the fact is lost below. An empty field array and
	 * an array of keys that carry no value of the model both produce a model with nothing assigned, and legacy
	 * tells the two apart - it refuses the first with one message and lets the second reach the per-field checks.
	 * Which of them it was is a property of the legacy input, so the projection states it and the one step that
	 * refuses decides what follows.
	 *
	 * A DELETE AND A COMPLETION ANSWER TRUE HERE and nothing comes of it: their entry points hand over no field
	 * array at all, and neither pipeline has a check of the fields to read this.
	 */
	public function wasCalledWithNoField(): bool
	{
		return $this->calledWithNoField;
	}

	/**
	 * The option array as the caller of a legacy entry point gave it, and an empty set for an operation that came
	 * from anywhere else. THE ONE READER OF IT IS THE WRAPPER OF A LEGACY PROVIDER - nothing of V2 may take a
	 * decision by it, and everything of V2 asks the typed rows above instead.
	 *
	 * IT IS AN OPEN SET AND THAT IS WHY IT TRAVELS WHOLE, the same reason the fields a legacy provider invents
	 * travel whole ({@see \Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext
	 * ::RUN_VALUE_UNCARRIED_PROVIDER_FIELDS}). Legacy hands its providers the very array it was given
	 * (crm_activity.php:1018, :1496), and the keys they read out of it are their own business: the provider of
	 * tasks decides by `SKIP_SUBJECT`, `SKIP_RESPONSIBLE_CHANGE` and `SKIP_RENEW`, the one of generated documents
	 * by `IS_ERASING_FROM_RECYCLE_BIN` - none of which is a key of the closed projection above, because no rule of
	 * the domain reads any of them. A projection narrowed to the keys V2 knows silently changed what those
	 * providers do, which is what this row exists to stop; inventing a typed row per key of a provider would be
	 * inventing a contract no provider agreed to.
	 *
	 * @return array<string, mixed>
	 */
	public function getLegacyOptions(): array
	{
		return $this->legacyOptions;
	}
}
