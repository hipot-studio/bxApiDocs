<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared;

use Bitrix\Crm\Activity\IncomingChannel;
use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Crm\V2\Internal\Entity\Activity\Enum\CompletionStatus;
use Bitrix\Crm\V2\Internal\Entity\Activity\Enum\DescriptionType;
use Bitrix\Crm\V2\Internal\Entity\Activity\StorageFieldTransit;
use Bitrix\Crm\V2\Internal\Service\Activity\ActivityLifecycleService;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Crm\V2\Internal\Service\Activity\Provider\ActivityProviderInterface;
use Bitrix\Crm\V2\Internal\Service\Activity\Provider\ProviderResolver;
use Bitrix\Crm\V2\Internal\Service\Activity\Provider\ValidationContext;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use CCrmActivity;
use CCrmActivityNotifyType;
use CCrmActivityType;
use CUserTypeManager;

/**
 * Checks that the activity may be stored at all and completes what the caller left out: step 3 of the owner
 * table of an add and step 3 of the owner table of an update (`rebuildFieldsForDataBase`,
 * crm_activity.php:1852-2206), the one step of a pipeline that refuses the whole operation.
 *
 * THE STEP THAT REFUSES. Legacy interrupts an add on exactly two things - this check and the insert - and the
 * rest of its thirty-seven effects go on whatever happens. So this is one of the two steps declaring itself
 * aborting, and a failure of it means nothing was written: it runs above every writer of the pipeline.
 *
 * THE ERRORS ACCUMULATE, and that is the contract rather than an accident. Legacy registers every missing field
 * it finds and only then answers, so a caller learns about all of them at once; it also hands the fields to the
 * provider even when the core has already refused them, and the provider's own errors join the same collection.
 * Both properties are kept here: whoever asked gets one answer listing everything that is wrong.
 *
 * THE MOMENTS OF THE INPUT ARE ASKED ABOUT ABOVE THE MODE, because legacy asks about them above the branch of its
 * action (:2220-2238): a datetime column filled with something that is no moment refuses the operation in either
 * mode, and its message comes first in the accumulated answer. What travels here is the statement of which columns
 * those are, not the values - see {@see self::checkTheMomentsOfTheInput()}.
 *
 * WHAT IT NORMALIZES AND WHAT IT DELIBERATELY DOES NOT. The values legacy derives before the insert live in
 * three places in V2 - the typed model, the storage columns of the run, and the columns the repository fills
 * itself - and this step writes only the first two. It completes the author and the editor, the flag and the
 * status of completion, the markup of the description, the two ends of the time interval, the type of the
 * activity and the notification pair, the identifier of the associated entity; it does NOT touch the priority,
 * the direction, the handleable flag, the parent or the type of the storage, because the repository derives
 * exactly what legacy derives for those, and a second author of one value is a second answer about it. The
 * default of the type of the storage belongs to the step above it (:85-88).
 *
 * AN UPDATE HAS ONE DEMAND, AND IT ANSWERS ALONE: a caller that named no field at all. Legacy refuses such an
 * update with a single message at the top of its check, before it looks at anything else (:2227-2233), so the
 * refusal is the first thing here and it returns - an answer listing what else is missing would be a different
 * answer. An ADD never reaches that refusal in legacy either: its entry point writes the default of the storage
 * type into the array of its caller before the check runs (:106-109), so an add of an empty array arrives with
 * one key and goes through the per-field demands like any other. Whether the field array was empty is a property
 * of the legacy input and travels with the operation
 * ({@see \Bitrix\Crm\V2\Internal\Service\Activity\ActivityOperationContext::wasCalledWithNoField()}); by the
 * time a model is built, an array of nothing and an array of keys the model cannot hold look alike.
 *
 * AN UPDATE ASKS ALMOST NOTHING ELSE AND TAKES ALMOST EVERYTHING AWAY, and that asymmetry is the whole
 * difference between the two modes (:2040-2129 against :1892-2039). Beyond the demand above, nothing is
 * required of the fields of an update: an activity that exists already has an owner, a type, a subject and
 * somebody responsible, so none of the four refusals of an add is asked here and none of its defaults is
 * filled in. What the mode does instead is take
 * away what an update never changes - the author, the legacy type of the activity, the identity of the provider
 * and the deadline - and derive the two values legacy derives there: the status that follows the completion
 * flag, by the transitions of section 12 of LBS-1 and therefore from the previous state, and the editor, whom a
 * letter never gets ({@see EmailBranch::suppressesEditorAutofill()}). The identity of the provider comes back
 * right after the check of the fields, with the value the previous state carried - legacy strips the column and
 * puts it back from the provider it resolved (:2123-2151), so the row keeps the provider it had while every
 * step below reads that same provider off the model.
 *
 * A DEADLINE OF THE CALLER IS DROPPED, AND AN ADD DERIVES THE ONE THAT STANDS IN ITS PLACE. Legacy takes the key
 * out before it calls the check of the fields (:2007 on an add, :2126 on an update), so a deadline is never a
 * value of the caller. What it is instead differs by mode. An add takes the one its provider derived out of the
 * start or the end of the activity and, where no provider derived any, the start time of the activity itself -
 * the general rule legacy applies right after it has asked the provider (:2158-2173,
 * {@see self::applyDeadlineOfTheStartTime()}). It is derived HERE and not at the storage boundary because the two
 * gates of the statistics of an add ask the run for the deadline: a value that appears only in the row is a value
 * they never see. An activity that has no start time either carries no deadline in the run at all - legacy puts
 * the sentinel of "none" into its raw channel alone (:2170-2178), where no reader of its field array looks - and
 * the row of such an activity is filled with that sentinel by the storage boundary. An update derives nothing:
 * legacy strips the column there and puts none back (:2126), so on an update only a provider moves a deadline.
 *
 * An incoming channel is forced to have none here, because legacy forces it too (:2181-2186, checklist item B6) -
 * and forcing it means two things, the sentinel for the write and the fact for the gates that ask whether a
 * deadline was given at all ({@see DeadlineSentinelGuard::forceToNoneIn()}).
 *
 * An update knows a second way of having no deadline, and it is not the same one (:2188-2195). An activity that
 * is registered as an incoming channel in storage, and whose operation says nothing about that flag, loses
 * whatever deadline the provider has just derived - the column leaves the transit and the stored value stays as
 * it was. No sentinel is written and no fact of the run is recorded: legacy only drops its key, and the outer
 * gates of an update read the deadline off the re-read row rather than off the run.
 *
 * THE PROVIDER OF THE ACTIVITY IS RESOLVED HERE, and this is the step the rest of the pipeline gets it from
 * ({@see PipelineContext::setProvider()}). An add resolves it from the input - the identifier of the provider
 * first and the legacy type of the activity after it, which is the order of legacy (:2130) - and an activity
 * declaring itself a provider one without a provider to be found is refused, as it is refused there (:1916).
 * The order of the two questions that follow is a contract of its own: the check of the fields runs first and
 * only then are the identifier and the type of the provider completed, so a value the check derived is never
 * overwritten (:2140-2151).
 *
 * AN UPDATE RESOLVES ITS PROVIDER FROM THE PREVIOUS STATE AND NEVER FROM THE INPUT (item B8, :2131). The
 * activity being written may name another provider, or none at all, and legacy still asks the one the stored row
 * belongs to: a provider is a property of an activity and not of an operation over it. Resolving it from the
 * input instead would hand the fields of a letter to the provider of a to-do item, take the deadline from the
 * wrong end of the interval and silence the email branch below. Both halves of the previous state take part in
 * the answer - its identifier of a provider first, then the legacy type of its row, which is a column and
 * therefore travels in the transit of the state. And an update refuses nothing here: the refusal of an
 * unresolvable provider belongs to the add branch of legacy alone, so an activity of an unknown provider is
 * updated with no provider taking part, exactly as it is there.
 *
 * AN ACTIVITY WITHOUT A BINDING IS NOT A STATE OF THE DOMAIN (row BINDINGS-EMPTY of the registry of intentional
 * deviations, invariant of `[P5.T8]`). An operation that would leave one bound to nothing is refused here, by an
 * ordinary error of the result and before anything is written - legacy fails such an update with an unhandled
 * exception after it has already rewritten the row. On an add the set of bindings is what the caller gave plus
 * the owner of the activity, which the step appending it turns into a binding of its own (:239-266), so naming
 * an owner is enough and naming neither is the refusal. On an update the question is narrower and the answer is
 * the same: an operation naming an empty set is refused, because the step resolving the owner from that set
 * clears the owner instead of appending anything (:571-576), so nothing would be left to bind the activity to.
 * An operation naming no set at all keeps the bindings the activity has and is no such attempt.
 *
 * WHAT THE CHECK OF A PROVIDER PRODUCES BESIDE THE COLUMNS. The provider of to-do items enriches the text its
 * calendar event is described with, and that text is no column of an activity: it travels as a value of the run
 * ({@see PipelineContext::RUN_VALUE_ENRICHED_DESCRIPTION}) from here to the step synchronizing the event, which
 * is its only reader. Everything else a legacy provider adds to its field array has no reader in the pipeline
 * and dies with the array, exactly as it does now.
 *
 * THE USER FIELDS ARE THE LAST QUESTION OF BOTH MODES, exactly as they are the last question legacy asks
 * (:2495-2501). The manager of the user fields of the portal is what answers it, and the two keys legacy consults
 * before asking are dead (item B10 of LBS-1): no caller of an activity passes them, so the check is made on every
 * operation and the demand of the mandatory ones is never lifted. An activity that is refused there is refused the
 * way everything else of this step is refused - by an ordinary error of the result, before anything is written -
 * and the values asked about are the ones of the operation
 * ({@see \Bitrix\Crm\V2\Internal\Service\Activity\ActivityOperationContext::getUserFieldValues()}), because the
 * manager picks its keys out of the field array legacy hands it and those keys are the ones the caller named. The
 * identifier the check is made against is the one of the activity, which is none on an add and the stored one on
 * an update - the same pair legacy passes (:116 against :524) and the reason a mandatory field left out entirely
 * refuses a creation while an update is asked only about what it names.
 *
 * ONE CLASS, TWO MODES, NAMED AND NEVER GUESSED. The mode is named by whoever declares the pipeline and is never
 * derived from the state of the run: the two branches of legacy are one method taking its action as a string, and
 * a mode read off the run - "there is a previous state, so this must be an update" - would turn a run whose first
 * step failed into an add and check the fields of an update against the demands of a creation. The previous state
 * is what the mode of an update works on throughout, so a run of that mode without one is refused: the step is
 * one of the two that interrupt, and going on would mean resolving the provider from the input, which is the one
 * thing an update may not do.
 *
 * @internal
 */
final class ValidateAndNormalize implements PipelineStepInterface
{
	public const ERROR_OWNER_NOT_GIVEN = 'CRM_ACTIVITY_OWNER_NOT_GIVEN';

	public const ERROR_OWNER_TYPE_NOT_GIVEN = 'CRM_ACTIVITY_OWNER_TYPE_NOT_GIVEN';

	public const ERROR_ACTIVITY_TYPE_NOT_GIVEN = 'CRM_ACTIVITY_TYPE_NOT_GIVEN';

	public const ERROR_ACTIVITY_TYPE_NOT_SUPPORTED = 'CRM_ACTIVITY_TYPE_NOT_SUPPORTED';

	public const ERROR_PROVIDER_NOT_FOUND = 'CRM_ACTIVITY_PROVIDER_NOT_FOUND';

	public const ERROR_SUBJECT_NOT_GIVEN = 'CRM_ACTIVITY_SUBJECT_NOT_GIVEN';

	public const ERROR_RESPONSIBLE_NOT_GIVEN = 'CRM_ACTIVITY_RESPONSIBLE_NOT_GIVEN';

	public const ERROR_NOTIFY_VALUE_NOT_GIVEN = 'CRM_ACTIVITY_NOTIFY_VALUE_NOT_GIVEN';

	/**
	 * A datetime column of the input carries something that names no moment. Legacy has no code of its own for it -
	 * it registers a text - so the code is the one of V2 and the text is the legacy one.
	 */
	public const ERROR_MOMENT_INCORRECT = 'CRM_ACTIVITY_MOMENT_INCORRECT';

	public const ERROR_WITHOUT_BINDINGS = 'CRM_ACTIVITY_WITHOUT_BINDINGS';

	/**
	 * The caller named no field at all. Legacy has no code of its own for it either - the text is the legacy one
	 * ({@see self::MESSAGE_NO_FIELD_GIVEN}).
	 */
	public const ERROR_NO_FIELD_GIVEN = 'CRM_ACTIVITY_NO_FIELD_GIVEN';

	/** The message legacy registers for an operation whose caller named no field (crm_activity.php:2230). */
	private const MESSAGE_NO_FIELD_GIVEN = 'Fields is not specified.';

	/**
	 * The manager of the user fields refused the values of the operation: a mandatory one was left out, or a value
	 * of one is not a value of its type. The message of the manager travels with the error.
	 */
	public const ERROR_USER_FIELDS_REFUSED = 'CRM_ACTIVITY_USER_FIELDS_REFUSED';

	/**
	 * The run of an update reached this step without the previous state of the activity, which only happens
	 * when the step reading it is missing from the declaration or has failed.
	 */
	public const ERROR_PREVIOUS_STATE_NOT_LOADED = 'CRM_ACTIVITY_PREVIOUS_STATE_NOT_LOADED';

	private const COLUMN_OWNER_ID = 'OWNER_ID';
	private const COLUMN_OWNER_TYPE_ID = 'OWNER_TYPE_ID';
	private const COLUMN_ACTIVITY_TYPE = 'TYPE_ID';
	private const COLUMN_NOTIFY_TYPE = 'NOTIFY_TYPE';
	private const COLUMN_NOTIFY_VALUE = 'NOTIFY_VALUE';
	private const COLUMN_ASSOCIATED_ENTITY = 'ASSOCIATED_ENTITY_ID';
	private const COLUMN_HANDLEABLE = 'IS_HANDLEABLE';

	/**
	 * The values of a run the check of a legacy provider produces, by the key it writes them under. Every one
	 * of them is a value no column of an activity carries; a key that is not here has no reader.
	 */
	private const RUN_VALUES_BY_LEGACY_KEY = [
		'ENRICHED_DESCRIPTION' => PipelineContext::RUN_VALUE_ENRICHED_DESCRIPTION,
		'PREV_ENRICHED_DESCRIPTION' => PipelineContext::RUN_VALUE_PREVIOUS_ENRICHED_DESCRIPTION,
	];

	private const OPERATIONS = [
		ActivityLifecycleService::OPERATION_ADD,
		ActivityLifecycleService::OPERATION_UPDATE,
	];

	private readonly ProviderResolver $resolver;

	private ?CUserTypeManager $userFieldManager;

	/**
	 * @param string $operation which of the pipelines this instance belongs to, named by whoever declares it.
	 * @param ProviderResolver|null $resolver the registry of the providers of the module by default; one of the
	 *        two seams of the step.
	 * @param CUserTypeManager|null $userFieldManager the manager of the user fields of the portal; the global
	 *        instance by default, resolved when the step runs rather than when it is declared.
	 * @throws ArgumentException on an operation this step has no check for yet.
	 */
	public function __construct(
		private readonly string $operation,
		?ProviderResolver $resolver = null,
		?CUserTypeManager $userFieldManager = null,
	)
	{
		if (!in_array($operation, self::OPERATIONS, true))
		{
			throw new ArgumentException(
				"The '{$operation}' operation over an activity has no check of the fields of its own yet",
				'operation',
			);
		}

		$this->resolver = $resolver ?? new ProviderResolver();
		$this->userFieldManager = $userFieldManager;
	}

	public function getName(): string
	{
		return 'ValidateAndNormalize';
	}

	/**
	 * Every operation is checked. Legacy has no path around this.
	 */
	public function isApplicable(PipelineContext $context): bool
	{
		return true;
	}

	/**
	 * THE FACT OF THE CHECK IS RECORDED BEFORE THE CHECK ITSELF, and the order is the contract: legacy empties its
	 * accumulator of errors at the top of the very method this step stands for and registers whatever it refuses
	 * afterwards (item A5 of LBS-1), so an operation that was refused HERE still emptied it while an operation
	 * refused above never did. The one reader of the fact is the legacy facade
	 * ({@see PipelineContext::RUN_VALUE_FIELDS_CHECKED}).
	 */
	public function execute(PipelineContext $context): Result
	{
		$context->setRunValue(PipelineContext::RUN_VALUE_FIELDS_CHECKED, true);

		$result = new Result();

		// The first question of legacy and the only one that answers alone: an update whose caller named no field
		// is refused with this one message and nothing else is asked (:2227-2233). It stands below the record of
		// the check, because legacy empties its accumulator before it (:2226), and above everything else. An ADD
		// never reaches it: its entry point fills the default of the storage type into the array of its caller
		// before the check runs (:106-109), so the array of an add is never the empty one.
		if ($this->isUpdate() && $context->getOperationContext()->wasCalledWithNoField())
		{
			return $result->addError(new Error(self::MESSAGE_NO_FIELD_GIVEN, self::ERROR_NO_FIELD_GIVEN));
		}

		self::checkTheMomentsOfTheInput($context, $result);

		$ofTheMode = $this->isUpdate()
			? $this->checkFieldsOfAnUpdate($context)
			: $this->checkFieldsOfAnAdd($context)
		;
		$result->addErrors($ofTheMode->getErrors());

		return $result;
	}

	/**
	 * A failure of the check ends the operation: nothing of an activity whose fields were refused is written.
	 */
	public function isAbortingOnFailure(): bool
	{
		return true;
	}

	private function isUpdate(): bool
	{
		return $this->operation === ActivityLifecycleService::OPERATION_UPDATE;
	}

	//region the two modes

	private function checkFieldsOfAnAdd(PipelineContext $context): Result
	{
		$result = new Result();
		$activity = $context->getActivity();
		$transit = $context->getTransit();

		$this->checkOwner($transit, $result);
		$this->checkBindings($activity, $transit, $result);
		$this->applyTypeOfProviderActivity($activity, $transit);
		$this->checkTypeOfActivity($transit, $result);

		$provider = $this->resolveProvider($context, $result);

		$this->checkGivenFields($activity, $result);
		$this->applyNotify($transit, $result);
		$this->applyCompletion($activity);
		self::applyHandleableFlag($activity, $transit);
		$this->applyCreationTime($context);
		$this->applyUsers($activity, $context);
		$this->applyTimeInterval($activity);
		$this->applyDefaults($activity, $transit);

		if ($provider !== null)
		{
			$this->validateWithProvider($provider, $context, $result);
			$this->completeProviderOfActivity($activity, $provider);
		}

		$this->applyDeadlineOfTheStartTime($context);
		$this->applyDeadlineOfIncomingChannel($context);
		$this->checkUserFields($context, $result);

		return $result;
	}

	/**
	 * The order is the order of legacy (:2040-2129 and the tail :2131-2195): what an update derives, then what
	 * it takes away, then the provider of the previous state and its check of the fields, and only then the
	 * identity of that provider and the two rules about a deadline.
	 */
	private function checkFieldsOfAnUpdate(PipelineContext $context): Result
	{
		$result = new Result();
		if (!$context->hasPreviousState())
		{
			return $result->addError(new Error(
				'The previous state of the activity was not loaded.',
				self::ERROR_PREVIOUS_STATE_NOT_LOADED,
			));
		}

		$activity = $context->getActivity();
		$previous = $context->getPreviousActivity();

		$this->checkBindingsOfAnUpdate($activity, $result);
		$this->applyCompletionOfAnUpdate($activity, $previous);
		if ($activity->isFieldChanged(Activity::isCompleted))
		{
			self::applyHandleableFlag($activity, $context->getTransit());
		}
		$this->applyEditorOfAnUpdate($activity, $previous, $context);
		self::takeAwayWhatAnUpdateNeverWrites($activity, $context->getTransit());

		$provider = $this->resolveProviderOfAnUpdate($context);
		if ($provider !== null)
		{
			$this->validateWithProvider($provider, $context, $result);
			$this->completeProviderOfAnUpdate($activity, $provider, $previous);
		}

		$this->applyDeadlineOfIncomingChannel($context);
		$this->dropTheDeadlineOfAStoredIncomingChannel($context);
		$this->checkUserFields($context, $result);

		return $result;
	}

	//endregion

	//region what is checked

	/**
	 * The owner pair has to be there, and a zero of it is a value legacy accepts (:1894-1903): an activity of
	 * no owner exists, and the step above this one fills the pair from the bindings when it can.
	 */
	private function checkOwner(StorageFieldTransit $transit, Result $result): void
	{
		if (!self::isGivenIn($transit, self::COLUMN_OWNER_ID))
		{
			$result->addError(new Error('OWNER_ID is not assigned.', self::ERROR_OWNER_NOT_GIVEN));
		}

		if (!self::isGivenIn($transit, self::COLUMN_OWNER_TYPE_ID))
		{
			$result->addError(new Error('OWNER_TYPE_ID is not assigned.', self::ERROR_OWNER_TYPE_NOT_GIVEN));
		}
	}

	/**
	 * Whether the activity ends up bound to anything at all: the bindings the caller gave, or the owner the
	 * step appending it turns into one.
	 */
	private function checkBindings(Activity $activity, StorageFieldTransit $transit, Result $result): void
	{
		if (($activity->getBindings() ?? []) !== [] || self::isRealOwner($transit))
		{
			return;
		}

		$result->addError(new Error(
			'An activity cannot be stored without a binding.',
			self::ERROR_WITHOUT_BINDINGS,
		));
	}

	/**
	 * Whether the update leaves the activity bound to anything: an operation that named a set of bindings and
	 * named it empty leaves it bound to nothing, and that is no state of the domain. An operation that named no
	 * set keeps the bindings of the activity and is not asked about.
	 */
	private function checkBindingsOfAnUpdate(Activity $activity, Result $result): void
	{
		if (!$activity->isFieldChanged(Activity::bindings) || ($activity->getBindings() ?? []) !== [])
		{
			return;
		}

		$result->addError(new Error(
			'An activity cannot be left without a binding.',
			self::ERROR_WITHOUT_BINDINGS,
		));
	}

	/**
	 * The legacy type of the activity has to be there and has to be one of the defined ones (:1907-1915).
	 */
	private function checkTypeOfActivity(StorageFieldTransit $transit, Result $result): void
	{
		if (!self::isGivenIn($transit, self::COLUMN_ACTIVITY_TYPE))
		{
			$result->addError(new Error('TYPE_ID is not assigned.', self::ERROR_ACTIVITY_TYPE_NOT_GIVEN));

			return;
		}

		if (!CCrmActivityType::IsDefined(self::typeOfActivity($transit)))
		{
			$result->addError(new Error('TYPE_ID is not supported.', self::ERROR_ACTIVITY_TYPE_NOT_SUPPORTED));
		}
	}

	/**
	 * The moments of the input that are no moments (:2220-2238). It is the FIRST question of both modes, exactly as
	 * it is the first thing legacy asks - above the branch of the action and above every demand of an add - so the
	 * order of the accumulated messages is the order a caller has always seen.
	 *
	 * WHICH COLUMNS ARE WRONG IS STATED BY THE PROJECTION OF THE INPUT and asked of the operation here
	 * ({@see \Bitrix\Crm\V2\Internal\Service\Activity\ActivityOperationContext::getIncorrectMomentColumns()}). The
	 * typed model cannot be asked: it holds a moment or nothing, and "nothing" is the answer both to a text that
	 * names no moment and to a column the caller left out - the difference legacy refuses over. The message is
	 * produced by the file that owns its phrases, so the two paths cannot drift apart.
	 *
	 * A DEADLINE IS AMONG THEM even though no caller of either path ever writes one: legacy checks the moments
	 * before it takes the column away, so an unparsable deadline refuses the operation there too.
	 */
	private static function checkTheMomentsOfTheInput(PipelineContext $context, Result $result): void
	{
		foreach ($context->getOperationContext()->getIncorrectMomentColumns() as $column)
		{
			$result->addError(new Error(
				CCrmActivity::messageOfAnIncorrectDateTimeField($column),
				self::ERROR_MOMENT_INCORRECT,
			));
		}
	}

	/**
	 * The fields no default of the core can invent (:1921-1930).
	 */
	private function checkGivenFields(Activity $activity, Result $result): void
	{
		if ($activity->getSubject() === null)
		{
			$result->addError(new Error('SUBJECT is not assigned.', self::ERROR_SUBJECT_NOT_GIVEN));
		}

		if ($activity->getResponsibleId() === null)
		{
			$result->addError(new Error('RESPONSIBLE_ID is not assigned.', self::ERROR_RESPONSIBLE_NOT_GIVEN));
		}
	}

	/**
	 * The user fields of the activity, asked of the manager of the portal the way legacy asks it and where legacy
	 * asks it - last of everything the check does (:2495-2501), so a refusal of them joins the errors already
	 * collected instead of replacing them.
	 *
	 * THE DEMAND OF THE MANDATORY ONES IS NEVER LIFTED. The two keys legacy consults are dead for an activity
	 * (item B10 of LBS-1) and are not carried into V2, so both of its questions have one answer here: the check is
	 * made, and the mandatory fields are part of it.
	 *
	 * THE MANAGER STATES ITS REFUSAL THROUGH THE EXCEPTION OF THE APPLICATION and its answer is a flag, which is
	 * why the message is taken from there and not from a returned collection. Legacy reads the same channel, and
	 * the operation ends the way every other refusal of this step ends - by an error of the result.
	 *
	 * THE CHANNEL IS ONE SLOT OF THE WHOLE HIT, so it is cleared on both ends of the question. Before it, because
	 * an exception somebody left there earlier is not a statement about these values and would otherwise be read
	 * out as the reason they were refused; after it, because the one raised here belongs to this error alone and
	 * would otherwise outlive the step and reach whoever reads the slot next.
	 */
	private function checkUserFields(PipelineContext $context, Result $result): void
	{
		self::forgetTheExceptionOfTheApplication();

		$isAccepted = $this->userFieldManager()->CheckFields(
			CCrmActivity::UF_ENTITY_TYPE,
			$context->getActivityId(),
			$context->getOperationContext()->getUserFieldValues(),
			false,
			true,
		);

		$message = self::messageOfTheRefusedUserFields();
		self::forgetTheExceptionOfTheApplication();

		if ($isAccepted)
		{
			return;
		}

		$result->addError(new Error($message, self::ERROR_USER_FIELDS_REFUSED));
	}

	/**
	 * What the manager said about the values it refused, or a statement of the refusal itself where the channel it
	 * speaks through carries nothing: an application holding no exception answers `false` rather than an object.
	 */
	private static function messageOfTheRefusedUserFields(): string
	{
		$exception = ($GLOBALS['APPLICATION'] ?? null)?->GetException();
		$message = is_object($exception) ? (string)$exception->GetString() : '';

		return $message === '' ? 'User fields of the activity are not valid.' : $message;
	}

	private static function forgetTheExceptionOfTheApplication(): void
	{
		($GLOBALS['APPLICATION'] ?? null)?->ResetException();
	}

	private function userFieldManager(): CUserTypeManager
	{
		$this->userFieldManager ??= $GLOBALS['USER_FIELD_MANAGER'];

		return $this->userFieldManager;
	}

	//endregion

	//region what is normalized

	/**
	 * An activity that names a provider is a provider one, whatever type it was given (:1904-1906).
	 */
	private function applyTypeOfProviderActivity(Activity $activity, StorageFieldTransit $transit): void
	{
		$hasProvider = ($activity->getProviderId() ?? '') !== '';
		if ($hasProvider && self::typeOfActivity($transit) <= 0)
		{
			$transit->set(self::COLUMN_ACTIVITY_TYPE, CCrmActivityType::Provider);
		}
	}

	/**
	 * The notification of the activity: no type means no notification, and a notification of no value is
	 * refused (:1931-1944).
	 */
	private function applyNotify(StorageFieldTransit $transit, Result $result): void
	{
		if (!self::isGivenIn($transit, self::COLUMN_NOTIFY_TYPE))
		{
			$transit->set(self::COLUMN_NOTIFY_TYPE, CCrmActivityNotifyType::None);
		}

		if ((int)$transit->get(self::COLUMN_NOTIFY_TYPE) === CCrmActivityNotifyType::None)
		{
			$transit->set(self::COLUMN_NOTIFY_VALUE, 0);

			return;
		}

		if (!self::isGivenIn($transit, self::COLUMN_NOTIFY_VALUE))
		{
			$result->addError(new Error('NOTIFY_VALUE is not assigned.', self::ERROR_NOTIFY_VALUE_NOT_GIVEN));
		}
	}

	/**
	 * An activity nobody said anything about is not complete, and its status follows the flag (:1945-1965).
	 * The status is written even though the repository derives the same value: the steps below read it off the
	 * model, and legacy has it in its field array from here on.
	 */
	private function applyCompletion(Activity $activity): void
	{
		if ($activity->isCompleted() === null)
		{
			$activity->setCompleted(false);
		}

		if ($activity->getCompletionStatus() === null)
		{
			$activity->setCompletionStatus(
				$activity->isCompleted() === true ? CompletionStatus::Completed : CompletionStatus::Waiting,
			);
		}
	}

	/**
	 * The flag the filter of the "wait" provider reads, derived from the completion exactly where legacy derives it
	 * (:2115-2118 on an add, :2229-2232 on an update) and only where nobody named one.
	 *
	 * IT IS WRITTEN INTO THE TRANSIT EVEN THOUGH THE STORAGE DERIVES THE SAME VALUE. The repository computes the
	 * flag from the completion on its own, so nothing about the row changes; what changes is that legacy has the
	 * flag in its field array from the validation on, and the reverse projection of the facade owes its caller
	 * exactly that (section 11 of LBS-1). A value the caller named is left alone here and keeps beating the
	 * derivation of the storage.
	 */
	private static function applyHandleableFlag(Activity $activity, StorageFieldTransit $transit): void
	{
		if (!$transit->has(self::COLUMN_HANDLEABLE))
		{
			$transit->set(self::COLUMN_HANDLEABLE, $activity->isCompleted() === true ? 'N' : 'Y');
		}
	}

	/**
	 * The status of an activity whose completion flag is being written, by the transitions of section 12 of
	 * LBS-1 (:2050-2084). Three rules and all three need the state before the write: an activity that is
	 * being reopened waits, whatever status the caller asked for; one that is being completed keeps an
	 * earlier automatic completion; and a caller asking for the waiting status together with a completion
	 * asked for something that does not exist.
	 *
	 * An operation saying nothing about the flag says nothing about the status either - legacy touches
	 * neither, and a status the caller named alone travels as it was named.
	 */
	private function applyCompletionOfAnUpdate(Activity $activity, Activity $previous): void
	{
		if (!$activity->isFieldChanged(Activity::isCompleted))
		{
			return;
		}

		if ($activity->isCompleted() !== true)
		{
			$activity->setCompletionStatus(CompletionStatus::Waiting);

			return;
		}

		$givenStatus = $activity->isFieldChanged(Activity::completionStatus)
			? $activity->getCompletionStatus()
			: null
		;

		if ($givenStatus === null)
		{
			$activity->setCompletionStatus(
				$previous->getCompletionStatus() === CompletionStatus::AutoCompleted
					? CompletionStatus::AutoCompleted
					: CompletionStatus::Completed,
			);

			return;
		}

		if ($givenStatus === CompletionStatus::Waiting)
		{
			$activity->setCompletionStatus(CompletionStatus::Completed);
		}
	}

	/**
	 * The editor of an update is whoever the operation acts as, and the chain legacy walks is the author of
	 * the input, the acting user, and the person responsible (:2108-2113). A letter is left out of it
	 * entirely: the data of a message is completed on the hits of different users, so the editor of an email
	 * stays exactly as the caller gave it, absent included
	 * ({@see EmailBranch::suppressesEditorAutofill()}).
	 */
	private function applyEditorOfAnUpdate(
		Activity $activity,
		Activity $previous,
		PipelineContext $context,
	): void
	{
		if ($activity->getEditorId() !== null)
		{
			return;
		}

		if (EmailBranch::suppressesEditorAutofill($activity->getProviderId(), $previous->getProviderId()))
		{
			return;
		}

		$userId = (int)($activity->getAuthorId() ?? 0);
		if ($userId <= 0)
		{
			$userId = $context->getOperationContext()->getCurrentUserId();
		}

		$activity->setEditorId($userId > 0 ? $userId : (int)$activity->getResponsibleId());
	}

	/**
	 * The values an update never writes, taken out of the run the way legacy takes them out of its field
	 * array: the author of an activity (:2115), its legacy type (:2118-2121), the identity of its provider
	 * (:2123-2126) and a deadline of the caller (:2128).
	 *
	 * THE WRITE PATH DROPS THEM AS WELL, and that is not a reason to keep them here. The steps between this
	 * one and the re-read of the row see the activity as this step leaves it - the event before the write
	 * publishes exactly these fields - so a value legacy has taken away has to be away here too. They are
	 * taken away through the hydration path: nobody asked for a change of them, and marking them as changed
	 * would say the caller did.
	 */
	private static function takeAwayWhatAnUpdateNeverWrites(Activity $activity, StorageFieldTransit $transit): void
	{
		$activity->internalSet(Activity::authorId, null);
		$activity->internalSet(Activity::providerId, null);
		$transit->remove(self::COLUMN_ACTIVITY_TYPE);
		$transit->remove(DeadlineSentinelGuard::COLUMN);
	}

	/**
	 * The creation time is the storage's own unless the caller asked for the one it carries (:1972-1977): a
	 * time nobody may preserve is taken away here, and the repository stamps the row with the current one.
	 */
	private function applyCreationTime(PipelineContext $context): void
	{
		if ($context->getOperationContext()->shouldPreserveCreationTime())
		{
			return;
		}

		$context->getActivity()->internalSet(Activity::createdTime, null);
	}

	/**
	 * The author of the activity is whoever the operation acts as, and its responsible when it acts as
	 * nobody; the editor of a new activity is its author (:1988-1997).
	 */
	private function applyUsers(Activity $activity, PipelineContext $context): void
	{
		if ($activity->getAuthorId() === null)
		{
			$actingUserId = $context->getOperationContext()->getCurrentUserId();
			$activity->setAuthorId(
				$actingUserId > 0 ? $actingUserId : (int)$activity->getResponsibleId(),
			);
		}

		if ($activity->getEditorId() === null)
		{
			$activity->setEditorId((int)$activity->getAuthorId());
		}
	}

	/**
	 * One end of the interval stands for both when only one of them is given (:1998-2006).
	 */
	private function applyTimeInterval(Activity $activity): void
	{
		if ($activity->getEndTime() === null && $activity->getStartTime() !== null)
		{
			$activity->setEndTime($activity->getStartTime());

			return;
		}

		if ($activity->getStartTime() === null && $activity->getEndTime() !== null)
		{
			$activity->setStartTime($activity->getEndTime());
		}
	}

	/**
	 * The values legacy fills in before the provider sees them: the markup of a description that says nothing
	 * about itself, the associated entity of an activity that has none, and a deadline taken away from the
	 * caller (:2007-2028).
	 */
	private function applyDefaults(Activity $activity, StorageFieldTransit $transit): void
	{
		$transit->remove(DeadlineSentinelGuard::COLUMN);

		if ($activity->getDescriptionType() === null)
		{
			$activity->setDescriptionType(DescriptionType::Plain);
		}

		if (!self::isGivenIn($transit, self::COLUMN_ASSOCIATED_ENTITY))
		{
			$transit->set(self::COLUMN_ASSOCIATED_ENTITY, 0);
		}
	}

	/**
	 * The deadline of a new activity no provider derived one for: its start time (:2158-2173). Legacy derives it
	 * here, right after the provider has been asked, and into the same field array both of its readers of a
	 * deadline hold - the statistics of the bound objects and the write of the row.
	 *
	 * THE RUN IS WHERE IT HAS TO LAND. The two gates of the statistics of an add ask the run whether a deadline
	 * was given ({@see DeadlineSentinelGuard::isGivenToTheRun()}), so a value derived past this step - at the
	 * storage boundary, for instance - would reach the row and none of them.
	 *
	 * AN ACTIVITY WITHOUT A START TIME KEEPS NO DEADLINE IN THE RUN, and that is legacy exactly: it writes the
	 * sentinel of "none" into its raw channel and takes the key of the field array away (:2170-2178), so its own
	 * readers of the array see no deadline at all while the row receives the sentinel. The storage boundary of V2
	 * fills the column of a run that carries none the same way, which leaves the two channels of legacy with the
	 * same two answers. The second half of that rule is the one below it: a value which is there and empty is no
	 * deadline either (:2175-2178).
	 */
	private function applyDeadlineOfTheStartTime(PipelineContext $context): void
	{
		$transit = $context->getTransit();

		if (!$transit->has(DeadlineSentinelGuard::COLUMN))
		{
			$startTime = $context->getActivity()->getStartTime();
			if ($startTime !== null)
			{
				$transit->set(DeadlineSentinelGuard::COLUMN, $startTime);
			}

			return;
		}

		if (!DeadlineSentinelGuard::isGiven($transit->get(DeadlineSentinelGuard::COLUMN)))
		{
			$transit->remove(DeadlineSentinelGuard::COLUMN);
		}
	}

	/**
	 * An activity registered as an incoming channel has no deadline, whoever derived one (:2181-2186). Legacy
	 * says that by dropping the key and writing the sentinel into the raw channel alone, so its outer gates of
	 * the statistics answer "no deadline was given"; the guard records both halves of that
	 * ({@see DeadlineSentinelGuard::forceToNoneIn()}).
	 */
	private function applyDeadlineOfIncomingChannel(PipelineContext $context): void
	{
		if ($context->getActivity()->isIncomingChannel() === true)
		{
			DeadlineSentinelGuard::forceToNoneIn($context);
		}
	}

	/**
	 * An activity that is an incoming channel in storage keeps no deadline a provider has just derived, unless
	 * the operation names the flag itself (:2188-2195). Legacy drops its key and nothing else: no sentinel is
	 * written, so the stored deadline stays as it is, and no fact of the run is recorded, because the gates of
	 * an update read the deadline off the re-read row.
	 *
	 * The flag is no column of the activity - it lives in a table of its own - so the question is asked of that
	 * table, exactly where legacy asks it, and not of the previous state.
	 */
	private function dropTheDeadlineOfAStoredIncomingChannel(PipelineContext $context): void
	{
		if ($context->getActivity()->isIncomingChannel() !== null)
		{
			return;
		}

		if (!IncomingChannel::getInstance()->isIncomingChannel($context->getActivityId()))
		{
			return;
		}

		$context->getTransit()->remove(DeadlineSentinelGuard::COLUMN);
	}

	//endregion

	//region the provider

	/**
	 * The provider of the activity, put into the context for every step below this one. An activity that
	 * declares itself a provider one and resolves to nobody is refused (:1916-1920).
	 */
	private function resolveProvider(PipelineContext $context, Result $result): ?ActivityProviderInterface
	{
		$transit = $context->getTransit();
		$provider = $this->resolver->resolve(
			$context->getActivity()->getProviderId(),
			self::typeOfActivity($transit),
		);
		$context->setProvider($provider);

		if ($provider === null && self::typeOfActivity($transit) === CCrmActivityType::Provider)
		{
			$result->addError(new Error(
				'Provider for custom activity is not found.',
				self::ERROR_PROVIDER_NOT_FOUND,
			));
		}

		return $provider;
	}

	/**
	 * The provider the previous state of the activity belongs to, put into the context for every step below
	 * this one (:2131). The legacy type of that state is a column and comes off the transit of it; a state
	 * that resolves to nobody leaves the update without a provider, which legacy accepts on an update.
	 */
	private function resolveProviderOfAnUpdate(PipelineContext $context): ?ActivityProviderInterface
	{
		$previousTransit = $context->getPreviousTransit();
		$provider = $this->resolver->resolve(
			$context->getPreviousActivity()->getProviderId(),
			self::typeOfActivity($previousTransit),
		);
		$context->setProvider($provider);

		return $provider;
	}

	/**
	 * Hands the fields to the provider, which checks and normalizes what belongs to it, and keeps the values
	 * of the run it produced beside the columns.
	 */
	private function validateWithProvider(
		ActivityProviderInterface $provider,
		PipelineContext $context,
		Result $result,
	): void
	{
		$validation = $provider->validate(
			$context->getActivity(),
			$context->getPayload(),
			$this->validationContextOf($context),
		);

		$result->addErrors($validation->getErrors());
		$this->carryValuesOfTheRun($context, $validation->getData());
	}

	/**
	 * Everything the provider is allowed to know about the operation. The structure of it says which operation
	 * this is - an update carries the state before it, an add has none to carry - and the flag of a preserved
	 * creation time belongs to an add alone, as legacy hands it over on an add alone (:1974 against :2042).
	 */
	private function validationContextOf(PipelineContext $context): ValidationContext
	{
		$operationContext = $context->getOperationContext();

		if ($this->isUpdate())
		{
			return ValidationContext::forUpdate(
				$context->getTransit(),
				$operationContext->getCurrentUserId(),
				$context->getPreviousActivity(),
				$context->getPreviousPayload(),
				$context->getPreviousTransit(),
				$operationContext->getRawCurrentUser(),
				$operationContext->isInitiatedByCalendar(),
			);
		}

		return ValidationContext::forAdd(
			$context->getTransit(),
			$operationContext->getCurrentUserId(),
			$operationContext->getRawCurrentUser(),
			$operationContext->isInitiatedByCalendar(),
			$operationContext->shouldPreserveCreationTime(),
		);
	}

	/**
	 * @param array<string, mixed> $validationData what the check produced that no column carries.
	 */
	private function carryValuesOfTheRun(PipelineContext $context, array $validationData): void
	{
		foreach (self::RUN_VALUES_BY_LEGACY_KEY as $legacyKey => $runValue)
		{
			if (array_key_exists($legacyKey, $validationData))
			{
				$context->setRunValue($runValue, $validationData[$legacyKey]);
			}
		}

		// The whole set is carried as well, and not only the two keys with a reader inside the pipeline: legacy
		// leaves EVERY key the check of the fields added in the field array of its caller, and the reverse
		// projection of the facade owes that caller the array legacy would have left (section 11 of LBS-1).
		if ($validationData !== [])
		{
			$context->setRunValue(PipelineContext::RUN_VALUE_UNCARRIED_PROVIDER_FIELDS, $validationData);
		}
	}

	/**
	 * The identity of the provider is written into the activity only where it is empty, and only after the
	 * check of the fields has had its say (:2143-2151).
	 */
	private function completeProviderOfActivity(Activity $activity, ActivityProviderInterface $provider): void
	{
		if (($activity->getProviderId() ?? '') === '')
		{
			$activity->setProviderId($provider->getId());
		}

		if (($activity->getProviderTypeId() ?? '') === '')
		{
			$defaultTypeId = $provider->getDefaultTypeId();
			if ($defaultTypeId !== null)
			{
				$activity->setProviderTypeId($defaultTypeId);
			}
		}
	}

	/**
	 * The identity of the provider of an update, put back where the operation left it empty (:2144-2151).
	 *
	 * THE IDENTIFIER IS PUT BACK THROUGH THE HYDRATION PATH, because putting it back is not a change of the
	 * activity: legacy strips the column and writes the identifier of the provider it resolved into its field
	 * array, which is the identifier the row already carries, so the write is a write of the same value. The
	 * steps below read it off the model, and the email branch of an update is the reason it has to be there.
	 *
	 * THE TYPE OF THE PROVIDER COMES FROM THE PREVIOUS STATE AND ONLY THEN FROM THE PROVIDER, because that is
	 * what legacy asks for: it hands the fields of the previous state to the provider, and every provider whose
	 * answer depends on them returns the type those fields carry (`getTypeId($prevFields)`, e.g. call.php:66-74).
	 * Asking the provider for its default instead would turn an activity of a narrower type into one of the
	 * default type on any update that does not name the type again - and none of them does.
	 */
	private function completeProviderOfAnUpdate(
		Activity $activity,
		ActivityProviderInterface $provider,
		Activity $previous,
	): void
	{
		$activity->internalSet(Activity::providerId, $provider->getId());

		if (($activity->getProviderTypeId() ?? '') !== '')
		{
			return;
		}

		$typeId = $previous->getProviderTypeId();
		if (($typeId ?? '') === '')
		{
			$typeId = $provider->getDefaultTypeId();
		}

		if (($typeId ?? '') !== '')
		{
			$activity->setProviderTypeId($typeId);
		}
	}

	//endregion

	/**
	 * Whether the operation carries the column at all, in the sense legacy asks it: a column carried as null
	 * is a column nobody assigned.
	 */
	private static function isGivenIn(StorageFieldTransit $transit, string $column): bool
	{
		return $transit->has($column) && $transit->get($column) !== null;
	}

	/**
	 * Whether the owner pair of the operation names a real object of the CRM. A zero of either half is the
	 * owner of nobody, which is the state legacy stores and the step appending an owner binding filters out.
	 */
	private static function isRealOwner(StorageFieldTransit $transit): bool
	{
		if (!self::isGivenIn($transit, self::COLUMN_OWNER_ID))
		{
			return false;
		}

		if (!self::isGivenIn($transit, self::COLUMN_OWNER_TYPE_ID))
		{
			return false;
		}

		return (int)$transit->get(self::COLUMN_OWNER_ID) > 0
			&& (int)$transit->get(self::COLUMN_OWNER_TYPE_ID) > 0
		;
	}

	private static function typeOfActivity(StorageFieldTransit $transit): int
	{
		return self::isGivenIn($transit, self::COLUMN_ACTIVITY_TYPE)
			? (int)$transit->get(self::COLUMN_ACTIVITY_TYPE)
			: 0
		;
	}
}
