<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline;

use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Crm\V2\Internal\Entity\Activity\ActivityChangeSet;
use Bitrix\Crm\V2\Internal\Entity\Activity\ProviderPayload;
use Bitrix\Crm\V2\Internal\Entity\Activity\StorageFieldTransit;
use Bitrix\Crm\V2\Internal\Service\Activity\ActivityOperationContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Provider\ActivityProviderInterface;
use Bitrix\Main\ArgumentException;

/**
 * The state one run of a pipeline shares between its steps: everything a step reads and everything it leaves
 * behind for the steps below it.
 *
 * THE MODEL IS REPLACED, NOT COPIED AROUND. A step that reads the stored state - the one loading the previous
 * state of an update, the one reading it back after the write, the one loading the activity being deleted - puts
 * what it read here, and from that point on the whole pipeline sees it. This is the only place holding the
 * activity of the operation, so no step may keep a model of its own between steps.
 *
 * TWO REPRESENTATIONS OF ONE OPERATION, AND THEY ARE NOT INTERCHANGEABLE. The operation context carries the
 * representation the caller gave, which stays as it was given: one predicate of the calendar synchronization is
 * defined over the input and would answer differently over anything re-read. What travels here beside it is the
 * working representation - the serialized fields and the storage columns being written - which starts as a copy
 * of the given one precisely so that a step normalizing a value cannot reach back and change the input. The two
 * differ by exactly the work the pipeline has done so far. The typed model has the same two halves: the state of
 * the run is replaced by the row an update re-reads, while the model the caller named stays reachable through
 * {@see self::getInputActivity()} - and that is the half the predicate above is asked about.
 *
 * THE PROVIDER IS RESOLVED BY A STEP, NOT BY THE ASSEMBLY. An add resolves it from the input, an update from the
 * previous state, and a delete from the row it loaded, so the pipeline receives a context without a provider and
 * the step that knows which state to resolve from sets it. An activity whose provider is unknown has none, and
 * every step deals with that on its own terms.
 *
 * THE PREVIOUS STATE OF AN UPDATE IS A TRIPLE, AND A STEP PUTS IT HERE. "What was it before" is a question a
 * stored row answers in three parts, and an update needs all three: the typed model of the fields that have a
 * meaning, the payload of the serialized ones, and the transit of the columns neither of them owns. Nothing
 * narrower would do - the provider of an update is resolved from the legacy type of the previous row, which is a
 * column of the transit; {@see \Bitrix\Crm\V2\Internal\Service\Activity\Provider\ValidationContext::forUpdate()}
 * takes the three as one; and the wrapper of a legacy provider projects the three back into the field array it
 * hands over as PREVIOUS_FIELDS. They therefore arrive together ({@see self::setPreviousState()}): a state
 * assembled out of halves is a state no row ever had. They also arrive from a step and not from the assembly of
 * the run - the step reading them is row 1 of the owner table of an update - because a previous state handed
 * over by whoever built the context would be one no step of the run ever read.
 *
 * THE BINDINGS OF THE PREVIOUS STATE ARE THE BINDINGS OF ITS MODEL. They live in a table of their own, so the
 * row of the activity does not carry them and the step taking their snapshot is row 4 of that table; it writes
 * them into the model of the triple, and that is where every step below asks for them. A set nobody has
 * snapshotted yet is null there, an activity that had none is an empty set, and the two are different answers.
 *
 * A RUN CARRIES VALUES THAT ARE NO COLUMNS OF AN ACTIVITY, and they need a carrier of their own. The check of
 * the fields of a to-do item derives the text its calendar event is to be described with, and the step
 * synchronizing that event is the only reader of it; neither the model nor the payload nor the transit may hold
 * such a value - the transit is a closed list of storage columns and this is not one of them - and the result of
 * a step is thrown away by the engine. So they travel here, under {@see self::RUN_VALUES}: a closed list, named
 * after what the value means rather than after the legacy key it arrived in, and extended by the step that first
 * needs a new one. The write cluster of an add added three of them, and all three exist for the same reason: a
 * text the columns hold in one shape while the steps below need another - the full body of a letter whose column
 * keeps a preview, and the two texts whose column keeps the escaped emoji. The cluster of the counters added two
 * more of a second kind: a value legacy keeps in a local variable of its pipeline and hands from one effect to
 * the next - the resolved reminder offsets and the moment the counter of the activity turns light at. There is a
 * third kind, and so far one of it: a fact about a column of the transit that the column itself cannot state -
 * that nobody gave the operation a deadline, which legacy says by not having the key at all while V2 has to keep
 * the sentinel in the column for the write. The pipeline of an update brought a fourth kind: a decision one step
 * takes and a step thirty rows below acts on - that the queue of the reminders is out of step, and that the
 * aggregate of the uncompleted activities has to be recomputed - which legacy keeps in two local booleans of its
 * own pipeline. Both are raised and never lowered, so the absence of one is its false, and the third flag of that
 * kind - that the body of a letter has to be bound anew - follows the same rule, and so does the fourth, which the
 * tail of an update adds: that a record of the activity stream was really written for the activity. The exclusive
 * block of an update brought a fifth kind: what the operation itself said about a field, kept because the step
 * above the block overwrites that very field of the model with what the storage says - so the question "did the
 * operation name it", which legacy asks of its field array, would have no answer left below. The pipeline of a
 * delete brought a sixth kind, and two of it: the outcome of an effect that only the effect itself can report -
 * whether the activity went to the recycle bin and which row of the bin it became - facts no storage the rows below
 * can reach still holds, because the row of the activity is erased between the two.
 *
 * THE ENTRY MARKER IS TOLD, NEVER GUESSED, AND IT HAS ONE CARRIER. It says whether the operation came through
 * the legacy facade or not, and the conservative default is the facade: an unknown entry is treated as the
 * legacy one so that neither the notifications nor the records of the activity stream are produced twice. It is
 * asked as "is this not the facade" ({@see self::isNativeEntry()}) rather than "is this the native entry",
 * because a third value of the source of a call - the future public command - belongs to the native side. The
 * value itself lives in the source of the call of the operation context and nowhere else: this accessor derives
 * it, so no assembly of a run can hand the steps an entry the caller did not name.
 *
 * @internal
 */
final class PipelineContext
{
	/**
	 * The text the provider of the activity enriched its description with for the event of a calendar: produced
	 * by the step checking the fields, read by the step synchronizing the event.
	 */
	public const RUN_VALUE_ENRICHED_DESCRIPTION = 'enrichedDescription';

	/**
	 * The description the calendar event of the activity had before this operation: the marker that keeps the
	 * synchronization from replacing a text somebody else wrote.
	 */
	public const RUN_VALUE_PREVIOUS_ENRICHED_DESCRIPTION = 'previousEnrichedDescription';

	/**
	 * The full text of the body of a letter, whose column keeps a preview of it: produced by the step
	 * compressing the description of an email, read by the step binding that body to the activity.
	 */
	public const RUN_VALUE_UNCOMPRESSED_DESCRIPTION = 'uncompressedDescription';

	/**
	 * That the full text of the letter differs from the body stored for it, which is the one thing making an
	 * update bind that body anew. Produced by the step compressing the description of an email in the mode of an
	 * update, read by the step rebinding the body.
	 *
	 * IT IS NOT THE SAME QUESTION AS "DOES THE RUN CARRY A FULL TEXT". An update compresses the description of
	 * every letter it is given, and binds a body only where the text really changed (crm_activity.php:631-636):
	 * a letter saved again with the body it already has keeps the stored row of that body, and everything else
	 * pointing at it. It is a flag by the rule below - raised, never lowered, its absence being its false.
	 */
	public const RUN_VALUE_MAIL_BODY_CHANGED = 'mailBodyChanged';

	/**
	 * The subject of the activity as it was before the emoji of it were escaped: produced by the step escaping
	 * them, read by the step that has written the row and puts the text of the caller back.
	 */
	public const RUN_VALUE_UNENCODED_SUBJECT = 'unencodedSubject';

	/**
	 * The description of the activity as it was before the emoji of it were escaped - which for a letter is
	 * already the preview, the compression happening above. Same producer and same reader as the subject.
	 */
	public const RUN_VALUE_UNENCODED_DESCRIPTION = 'unencodedDescription';

	/**
	 * The reminder offsets the operation really works with, in minutes before the deadline: the ones the caller
	 * named, or the defaults of the provider where the caller named none. Produced by the step resolving them,
	 * read by the step filling the light counter and by the step registering the queue of reminders.
	 *
	 * The offsets of the model are NOT the same value: they are what the caller said, they may be absent, and
	 * legacy keeps the resolved ones in a local variable it never writes back into the field array - so the
	 * subscribers of the event of an added activity see the offsets of the caller and not these.
	 */
	public const RUN_VALUE_PING_OFFSETS = 'pingOffsets';

	/**
	 * The moment the counter of an activity turns light at, or null for an activity that has none - which is
	 * every completed one. Produced by the step synchronizing the light counter, read by the aggregate of the
	 * uncompleted activities and by the monitor of the counters. No column of an activity holds it: its own
	 * table does, and legacy carries it in the field array between the same steps.
	 */
	public const RUN_VALUE_LIGHT_COUNTER_AT = 'lightCounterAt';

	/**
	 * The moment the counter of the activity turned light at BEFORE this operation recomputed it, or null where it
	 * had no such moment. Produced by the step of an update synchronizing the light counter, which reads it off the
	 * row it is about to overwrite; read by the aggregate of the uncompleted activities and by the monitor of the
	 * counters, both of which compare it with the value above.
	 *
	 * IT IS A SECOND VALUE AND NOT A SECOND READING. Legacy keeps the two in two locals and the order of them is
	 * an invariant (item 5 of section 13 of LBS-1): the previous moment has to be read before the recomputation
	 * replaces the row. Whoever asked the storage again below would get the recomputed one.
	 */
	public const RUN_VALUE_PREVIOUS_LIGHT_COUNTER_AT = 'previousLightCounterAt';

	/**
	 * That nobody gave this operation a deadline, the sentinel standing in the column of the transit only because
	 * the storage boundary has no other way to write "none". Produced by the step forcing it - the validation of an
	 * activity of an incoming channel - and read by the outer gates of the statistics through
	 * {@see \Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\DeadlineSentinelGuard::isGivenToTheRun()}.
	 *
	 * IT IS NOT THE SAME AS THE SENTINEL BEING IN THE COLUMN. An update reads the deadline off the re-read row,
	 * where an activity that has none carries the sentinel, and legacy registers the statistics under it; an add
	 * that was forced to have none reaches those gates with no key at all. The column cannot tell the two apart,
	 * so the fact of the forcing travels here.
	 */
	public const RUN_VALUE_DEADLINE_NOT_GIVEN = 'deadlineNotGiven';

	/**
	 * That the queue of the reminders of the activity no longer matches its offsets and has to be rebuilt. Raised
	 * by the step of an update resolving those offsets and by the exclusive block of the changes below it, read by
	 * the step synchronizing the queue.
	 *
	 * A FLAG OF A RUN IS RAISED AND NEVER LOWERED. It is there only once some step has raised it and its value is
	 * always true, so a reader asks {@see self::hasRunValue()} instead of comparing anything. Legacy keeps the
	 * same fact in a local boolean initialized to false, and "nobody raised it" is the only other state it has.
	 */
	public const RUN_VALUE_PING_QUEUE_NEEDS_SYNC = 'pingQueueNeedsSync';

	/**
	 * That the aggregate of the uncompleted activities of the bound objects has to be recomputed. The same
	 * producers and the same "raised, never lowered" rule as the flag above; its reader is the step of the
	 * aggregate, which runs after the counter of the activity has been recomputed.
	 */
	public const RUN_VALUE_UNCOMPLETED_AGGREGATE_NEEDS_REFRESH = 'uncompletedAggregateNeedsRefresh';

	/**
	 * The incoming-channel marker of an update as the OPERATION named it, carried only by a run whose operation
	 * named one at all. Produced by the step enriching the two snapshots with the marker, read by the three
	 * mutually exclusive steps below it.
	 *
	 * IT EXISTS BECAUSE THE STEP ABOVE OVERWRITES ITS OWN SOURCE. The marker is a field of the model and legacy
	 * reads it off the fields it was given (`isset($arFields['IS_INCOMING_CHANNEL'])`, :760), which selects the
	 * first of the three branches. The enrichment writes the marker into both snapshots unconditionally, so past
	 * it the model no longer tells "the operation named it" from "the registry says so" - and the branch of an
	 * update is exactly that question. Whether it is carried answers "was one named", its value is the one named.
	 */
	public const RUN_VALUE_GIVEN_INCOMING_CHANNEL = 'givenIncomingChannel';

	/**
	 * That a record of the activity stream was really written for this activity, which is what keeps the
	 * subscription of its responsible from being replaced afterwards. Raised by the transitional layer of the
	 * activity stream where its fallback registration answered with a record of its own, read by the step moving
	 * that subscription (row 43 of the owner table of an update).
	 *
	 * IT IS HALF OF THE ANSWER AND NOT THE WHOLE OF IT. Legacy keeps the same fact in a local boolean and raises it
	 * in two places: there, and where the provider of the activity refuses the activity stream altogether
	 * (crm_activity.php:1101-1104) - and that second half happens even where the layer never ran at all, so the
	 * reader adds it itself. The rule of a flag holds: raised, never lowered, and its absence is its false.
	 */
	public const RUN_VALUE_SONET_RECORD_REGISTERED = 'sonetRecordRegistered';

	/**
	 * That the activity went to the recycle bin instead of being erased. Raised by the step transferring it where the
	 * transfer succeeded (row 5 of the owner table of a delete), read by the step telling the timeline about the
	 * removal (row 16) - which is what decides whether the records of the timeline are erased with the activity or
	 * suspended against the row of the bin.
	 *
	 * IT IS HALF OF THE ANSWER AND NOT THE WHOLE OF IT. Legacy keeps the same fact in a local it initializes from the
	 * demand of the caller - which is how a delete of an activity being erased FROM the bin arrives, and the very
	 * state in which the transfer never runs - so the reader adds that half itself. The rule of a flag holds: raised,
	 * never lowered, and its absence is its false.
	 */
	public const RUN_VALUE_MOVED_TO_RECYCLE_BIN = 'movedToRecycleBin';

	/**
	 * The row of the recycle bin the activity is kept as, or null where the transfer named none. Produced by the step
	 * transferring it, read by the step telling the timeline about the removal - the same pair as the flag above.
	 *
	 * WHETHER IT IS CARRIED ANSWERS A DIFFERENT QUESTION FROM ITS VALUE. It is carried whenever a transfer was
	 * ATTEMPTED, a failed one included, and legacy asks the bin itself only where no transfer ran at all
	 * (`isset($recycleBinResult)`, crm_activity.php:1481-1488). A failed transfer therefore says "no row" about an
	 * activity that has been in the bin since an earlier operation, while asking the bin would have found it - so the
	 * carrier has to be present with a null in it rather than absent.
	 */
	public const RUN_VALUE_RECYCLING_ENTITY_ID = 'recyclingEntityId';

	/**
	 * That the calendar block of the operation consumed the inputs that belong to it alone, and thereby took them
	 * out of the field array of the caller: the section, the codes of the attendees, the flag of a meeting, the user
	 * field of the bound objects and the location (item G3, section 13 item 7 of LBS-1). Raised by the step
	 * synchronizing the event of a new activity, read by the reverse projection of the legacy facade, which is the
	 * only place there is a field array of a caller to take a key out of.
	 *
	 * IT IS THE FACT OF THE CONSUMPTION AND NOT THE VALUES. Four of the five keys are no columns of an activity and
	 * travel in the operation context; the fifth is a column of the transit the step removes there. What the
	 * projection needs is which names to take out of the array of its caller, and that set is the same one every
	 * time. It is a flag by the rule above: raised, never lowered, its absence being its false.
	 */
	public const RUN_VALUE_CALENDAR_INPUTS_CONSUMED = 'calendarInputsConsumed';

	/**
	 * The refusal the registration of a record of the activity stream reported, where it reported one. Produced by
	 * the transitional layer of that stream, read by the reverse projection of the legacy facade: legacy hands the
	 * same text back to the caller of `CCrmActivity::Add()` under the key ERROR of the field array it was given
	 * (section 11 of LBS-1), and that array is the one thing the layer has no access to.
	 */
	public const RUN_VALUE_LIVEFEED_ERROR = 'livefeedError';

	/**
	 * Everything the check of the fields of the provider added to the field array of legacy that no carrier of V2
	 * holds, by the name the provider gave each. Produced by the step checking the fields - which is where the wrapper
	 * of a legacy provider answers with them - and read by the reverse projection of the legacy facade.
	 *
	 * IT IS AN OPEN SET AND THAT IS WHY IT TRAVELS WHOLE. A legacy provider is free to put anything into the array it
	 * is given, and legacy leaves all of it there for the caller of the entry point to read; two of those keys have a
	 * reader inside the pipeline and get a carrier of their own, but the rest have none, and inventing one per key
	 * would be inventing a contract no provider agreed to.
	 */
	public const RUN_VALUE_UNCARRIED_PROVIDER_FIELDS = 'uncarriedProviderFields';

	/**
	 * That the check of the fields of the operation really ran. Raised by the step checking them - before it
	 * refuses anything, because legacy empties its accumulator of errors at the top of that check and registers its
	 * refusals afterwards (item A5 of LBS-1) - and read by the legacy facade, which is where an accumulator of
	 * errors exists at all.
	 *
	 * IT IS THE ONE MOMENT LEGACY EMPTIES THAT ACCUMULATOR AT, WHICH IS WHY THE FACT TRAVELS. An operation refused
	 * above the check leaves the messages of an earlier failure where they were - an update over an activity that
	 * is not there never reaches its check, and neither does a completion of an activity that is already in the
	 * state asked for - while every operation that got as far as the check answers its caller with its own messages
	 * and nothing else. The facade cannot tell the two apart from the answer of an operation, and asking storage
	 * again would be a second read of the row the run has already read.
	 *
	 * It is a flag by the rule of the flags above: raised, never lowered, and its absence is its false.
	 */
	public const RUN_VALUE_FIELDS_CHECKED = 'fieldsChecked';

	/** The closed list of the values of a run that are no columns of an activity. */
	private const RUN_VALUES = [
		self::RUN_VALUE_ENRICHED_DESCRIPTION,
		self::RUN_VALUE_PREVIOUS_ENRICHED_DESCRIPTION,
		self::RUN_VALUE_UNCOMPRESSED_DESCRIPTION,
		self::RUN_VALUE_MAIL_BODY_CHANGED,
		self::RUN_VALUE_UNENCODED_SUBJECT,
		self::RUN_VALUE_UNENCODED_DESCRIPTION,
		self::RUN_VALUE_PING_OFFSETS,
		self::RUN_VALUE_LIGHT_COUNTER_AT,
		self::RUN_VALUE_PREVIOUS_LIGHT_COUNTER_AT,
		self::RUN_VALUE_DEADLINE_NOT_GIVEN,
		self::RUN_VALUE_PING_QUEUE_NEEDS_SYNC,
		self::RUN_VALUE_UNCOMPLETED_AGGREGATE_NEEDS_REFRESH,
		self::RUN_VALUE_GIVEN_INCOMING_CHANNEL,
		self::RUN_VALUE_SONET_RECORD_REGISTERED,
		self::RUN_VALUE_MOVED_TO_RECYCLE_BIN,
		self::RUN_VALUE_RECYCLING_ENTITY_ID,
		self::RUN_VALUE_CALENDAR_INPUTS_CONSUMED,
		self::RUN_VALUE_LIVEFEED_ERROR,
		self::RUN_VALUE_UNCARRIED_PROVIDER_FIELDS,
		self::RUN_VALUE_FIELDS_CHECKED,
	];

	private Activity $activity;

	private readonly Activity $inputActivity;

	private readonly ActivityOperationContext $operationContext;

	private readonly ActivityChangeSet $changes;

	private readonly ProviderPayload $payload;

	private readonly StorageFieldTransit $transit;

	private ?ActivityProviderInterface $provider = null;

	private ?Activity $previousActivity = null;

	private ?ProviderPayload $previousPayload = null;

	private ?StorageFieldTransit $previousTransit = null;

	/** @var array<string, mixed> */
	private array $runValues = [];

	/**
	 * @param ActivityChangeSet|null $changes the set of changes the steps of the operation fill in and read the
	 *        conditions of the effects from; an empty one by default, as an add has nothing to compare against.
	 * @param ProviderPayload|null $payload the serialized fields being written; a copy of the given ones by
	 *        default.
	 * @param StorageFieldTransit|null $transit the storage columns being written that the typed model does not
	 *        hold; a copy of the given ones by default.
	 */
	public function __construct(
		Activity $activity,
		ActivityOperationContext $operationContext,
		?ActivityChangeSet $changes = null,
		?ProviderPayload $payload = null,
		?StorageFieldTransit $transit = null,
	)
	{
		$this->activity = $activity;
		$this->inputActivity = $activity;
		$this->operationContext = $operationContext;
		$this->changes = $changes ?? new ActivityChangeSet();
		$this->payload = $payload ?? self::copyOfPayload($operationContext->getInputPayload());
		$this->transit = $transit ?? self::copyOfTransit($operationContext->getInputTransit());
	}

	/**
	 * The activity of the operation as it stands after the steps that have run.
	 */
	public function getActivity(): Activity
	{
		return $this->activity;
	}

	/**
	 * Hands over the state a step has read from storage. Everything below it reads that state.
	 */
	public function setActivity(Activity $activity): void
	{
		$this->activity = $activity;
	}

	/**
	 * The id of the activity, or zero while it has none - which is the case for an add up to its insert.
	 */
	public function getActivityId(): int
	{
		return $this->activity->getId() ?? 0;
	}

	/**
	 * The activity of the operation as the caller named it, worked by the steps standing above the re-read - which
	 * is exactly the field array legacy carries down to its calendar block.
	 *
	 * IT IS NEITHER A PRISTINE SNAPSHOT NOR THE STATE OF THE RUN. For an add it is the state of the run, the same
	 * object all the way down; an update replaces that state with the row it re-read ({@see self::setActivity()}),
	 * and past row 17 this is the only thing left answering "what did the operation itself say".
	 *
	 * ITS READERS ARE THE ROWS LEGACY DEFINES OVER THE FIELD ARRAY RATHER THAN OVER THE RE-READ SNAPSHOT, and each
	 * of them says so in its own note: the predicate of the calendar synchronization, the single condition of an
	 * update defined over the input (item G1.2, row D10 of the registry) - asked about the re-read row it would be
	 * answering about a state no caller named, because the row carries the identifier and the completion that the
	 * field array of a partial update does not - the question about the queue of the reminders, the array the
	 * subscribers of row 47 are handed and the fields being written that row 46 hands to the provider. A row past
	 * the re-read that has to answer "what did the update WRITE" has no other source: the re-read model marks no
	 * field as changed.
	 */
	public function getInputActivity(): Activity
	{
		return $this->inputActivity;
	}

	/**
	 * Everything about the operation besides the activity itself, in the representation the caller gave.
	 */
	public function getOperationContext(): ActivityOperationContext
	{
		return $this->operationContext;
	}

	/**
	 * The changes of the operation, "before" against "after". The conditions of the effects are computed from
	 * this set and from nothing else.
	 */
	public function getChanges(): ActivityChangeSet
	{
		return $this->changes;
	}

	/**
	 * The serialized fields being written. Not the given ones: see the note on the two representations above.
	 */
	public function getPayload(): ProviderPayload
	{
		return $this->payload;
	}

	/**
	 * The storage columns being written that the typed model does not hold.
	 */
	public function getTransit(): StorageFieldTransit
	{
		return $this->transit;
	}

	/**
	 * The provider of the activity, or null while no step has resolved one - or when the activity has none.
	 */
	public function getProvider(): ?ActivityProviderInterface
	{
		return $this->provider;
	}

	public function setProvider(?ActivityProviderInterface $provider): void
	{
		$this->provider = $provider;
	}

	/**
	 * Hands over the state the activity had before this operation, as the step reading the stored row read it.
	 * The three parts are one state and are given at once - see the note on the triple above.
	 */
	public function setPreviousState(
		Activity $activity,
		ProviderPayload $payload,
		StorageFieldTransit $transit,
	): void
	{
		$this->previousActivity = $activity;
		$this->previousPayload = $payload;
		$this->previousTransit = $transit;
	}

	/**
	 * Whether a step of this run has read the state the activity had before it. False for every operation that
	 * has no previous state to read - an add - and for an update up to its first step.
	 */
	public function hasPreviousState(): bool
	{
		return $this->previousActivity !== null;
	}

	/**
	 * The fields the activity had before this operation.
	 *
	 * @throws ArgumentException when no step of this run has read a previous state.
	 */
	public function getPreviousActivity(): Activity
	{
		$this->assertPreviousState();

		return $this->previousActivity;
	}

	/**
	 * The serialized fields the activity had before this operation.
	 *
	 * @throws ArgumentException when no step of this run has read a previous state.
	 */
	public function getPreviousPayload(): ProviderPayload
	{
		$this->assertPreviousState();

		return $this->previousPayload;
	}

	/**
	 * The storage columns the activity had before this operation that its model does not hold.
	 *
	 * @throws ArgumentException when no step of this run has read a previous state.
	 */
	public function getPreviousTransit(): StorageFieldTransit
	{
		$this->assertPreviousState();

		return $this->previousTransit;
	}

	/**
	 * Whether the operation came from anywhere other than the legacy facade. Derived from the source of the
	 * call the caller named, which is the only carrier of it.
	 */
	public function isNativeEntry(): bool
	{
		return !$this->operationContext->isFacadeEntry();
	}

	/**
	 * Whether the activity of this run went to the recycle bin instead of being erased - what the caller said, or what
	 * row 5 of a delete did. THE FACT HAS TWO HALVES AND NEITHER OF THEM IS THE WHOLE ANSWER: legacy keeps one local
	 * for both, starting it from the demand of the caller - which is how a delete of an activity being erased FROM the
	 * bin arrives, and the very state in which the transfer never runs - and raising it where the transfer succeeded.
	 * The run carries the second half only ({@see self::RUN_VALUE_MOVED_TO_RECYCLE_BIN}), so the two are joined here
	 * rather than in each of the three rows asking: what the timeline does with the records, whether the files are
	 * erased and whether the activity stream is cleaned all turn on this one question.
	 */
	public function hasGoneToTheRecycleBin(): bool
	{
		return $this->operationContext->isMovedToRecycleBin()
			|| $this->hasRunValue(self::RUN_VALUE_MOVED_TO_RECYCLE_BIN)
		;
	}

	/**
	 * Whether a step of this run has left that value behind. Tells "nobody produced it" from "it is empty".
	 *
	 * @throws ArgumentException on a value that is not one of {@see self::RUN_VALUES}.
	 */
	public function hasRunValue(string $name): bool
	{
		self::assertRunValue($name);

		return array_key_exists($name, $this->runValues);
	}

	/**
	 * @throws ArgumentException on an unknown value, and on one no step of this run produced.
	 */
	public function getRunValue(string $name): mixed
	{
		if (!$this->hasRunValue($name))
		{
			throw new ArgumentException("The run does not carry '{$name}'");
		}

		return $this->runValues[$name];
	}

	/**
	 * @throws ArgumentException on a value that is not one of {@see self::RUN_VALUES}.
	 */
	public function setRunValue(string $name, mixed $value): void
	{
		self::assertRunValue($name);
		$this->runValues[$name] = $value;
	}

	/**
	 * @throws ArgumentException when no step of this run has read a previous state.
	 */
	private function assertPreviousState(): void
	{
		if (!$this->hasPreviousState())
		{
			throw new ArgumentException('The run carries no previous state of the activity');
		}
	}

	private static function assertRunValue(string $name): void
	{
		if (!in_array($name, self::RUN_VALUES, true))
		{
			throw new ArgumentException("'{$name}' is not a value a run of a pipeline carries");
		}
	}

	private static function copyOfPayload(ProviderPayload $payload): ProviderPayload
	{
		$copy = new ProviderPayload();
		foreach ($payload->getValues() as $field => $value)
		{
			$copy->set($field, $value);
		}

		return $copy;
	}

	private static function copyOfTransit(StorageFieldTransit $transit): StorageFieldTransit
	{
		$copy = new StorageFieldTransit();
		foreach ($transit->getValues() as $field => $value)
		{
			$copy->set($field, $value);
		}

		return $copy;
	}
}
