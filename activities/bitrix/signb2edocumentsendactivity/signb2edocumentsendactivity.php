<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Bizproc\Activity\BaseActivity;
use Bitrix\Bizproc\Activity\Enum\ResumeWorkflowQueue;
use Bitrix\Bizproc\Activity\PropertiesDialog;
use Bitrix\Bizproc\FieldType;
use Bitrix\Main;
use Bitrix\Main\Localization\Loc;
use Bitrix\Sign\Access\ActionDictionary;
use Bitrix\Sign\Config\Feature;
use Bitrix\Sign\Contract\Operation;
use Bitrix\Sign\Item;
use Bitrix\Sign\Operation\ConfigureFillAndStart;
use Bitrix\Sign\Operation\Result\ConfigureResult;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Service\Sign\DocumentService;
use Bitrix\Sign\Type\DocumentScenario;
use Bitrix\Sign\Type\DocumentStatus;

/**
 * @property int $documentId
 * @property string $signingStatus
 * @property int $resultDocumentId
 * @property string $documentStatus
 * @property int $smartDocumentId
 */
class CBPSignB2EDocumentSendActivity extends BaseActivity implements
	IBPConfigurableActivity,
	IBPEventActivity,
	IBPActivityExternalEventListener
{
	public const PARAM_DOCUMENT_ID = 'documentId';

	public const RETURN_PARAM_SIGNING_STATUS = 'signingStatus';
	public const RETURN_PARAM_DOCUMENT_ID = 'resultDocumentId';
	public const RETURN_PARAM_DOCUMENT_STATUS = 'documentStatus';
	public const RETURN_PARAM_SMART_DOCUMENT_ID = 'smartDocumentId';

	/**
	 * The outcome of the run. `SUCCESS` means the document is being signed and not that this very
	 * run sent it: a document sent from the interface or by a previous run answers the same way.
	 */
	public const STATUS_SUCCESS = 'SUCCESS';
	public const STATUS_FAILED = 'FAILED';
	public const STATUS_TIMED_OUT = 'TIMED_OUT';

	/**
	 * Reasons the activity refuses a run with. The phrase of a refusal goes to the process log for
	 * a human to read, and the code names the reason for whoever tells the refusals apart; the
	 * phrase of a code is read from the lang file by the prefix below and the code itself.
	 */
	public const ERROR_MODULE_UNAVAILABLE = 'MODULE_UNAVAILABLE';
	public const ERROR_UNAVAILABLE = 'UNAVAILABLE';
	public const ERROR_EMPTY_DOCUMENT_ID = 'EMPTY_DOCUMENT_ID';
	public const ERROR_TEMPLATE_WITHOUT_AUTHOR = 'TEMPLATE_WITHOUT_AUTHOR';
	public const ERROR_DOCUMENT_UNAVAILABLE = 'DOCUMENT_UNAVAILABLE';
	public const ERROR_NOT_B2E_DOCUMENT = 'NOT_B2E_DOCUMENT';
	public const ERROR_INITIATED_BY_EMPLOYEE = 'INITIATED_BY_EMPLOYEE';
	public const ERROR_DOCUMENT_FINISHED = 'DOCUMENT_FINISHED';
	public const ERROR_DOCUMENT_NOT_UPLOADED = 'DOCUMENT_NOT_UPLOADED';
	public const ERROR_NO_MEMBERS = 'NO_MEMBERS';
	public const ERROR_START_FAILED = 'START_FAILED';
	public const ERROR_WAIT_EXHAUSTED = 'WAIT_EXHAUSTED';
	public const ERROR_WAIT_NOT_POSSIBLE = 'WAIT_NOT_POSSIBLE';

	private const ERROR_MESSAGE_PREFIX = 'SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_SEND_ERROR_';

	/** The refusal of the domain named beside the failure it caused */
	private const PHRASE_START_FAILED_WITH_REASON = self::ERROR_MESSAGE_PREFIX . 'START_FAILED_WITH_REASON';

	private const PHRASE_LOG_WAIT_STARTED = 'SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_SEND_LOG_WAIT_STARTED';

	private const DOMAIN_MESSAGE_PREFIX = 'SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_SEND_DOMAIN_';

	/**
	 * Refusals of the domain the activity tells in words of its own, by the code the domain refuses
	 * with. Every other refusal keeps the text it came with: the table covers the codes met on the
	 * path of this activity alone and swallows no refusal it does not know.
	 *
	 * The status refusal and the validations of the signing period are written for a developer and in
	 * English, and the tariff refusal is a sentence of its own addressed to the interface, while the
	 * log names the reason inside the sentence of the failure and so needs a lower-case clause.
	 *
	 * @see \Bitrix\Sign\Operation\ConfigureDocument the status refusal
	 * @see \Bitrix\Sign\Operation\Document\Validation\ValidateDateSignUntil the period refusals
	 * @see \Bitrix\Sign\Integration\Bitrix24\B2eTariff::getCommonAccessError() the tariff refusal
	 */
	private const DOMAIN_ERROR_PHRASES = [
		'SIGN_DOCUMENT_INCORRECT_STATUS' => self::DOMAIN_MESSAGE_PREFIX . 'DOCUMENT_STATUS_CHANGED',
		'DOCUMENT_IN_FINAL_STATUS' => self::DOMAIN_MESSAGE_PREFIX . 'DOCUMENT_FINISHED',
		'PERIOD_TOO_SHORT' => self::DOMAIN_MESSAGE_PREFIX . 'SIGNING_PERIOD_TOO_SHORT',
		'PERIOD_TOO_LONG' => self::DOMAIN_MESSAGE_PREFIX . 'SIGNING_PERIOD_TOO_LONG',
		'B2E_RESTRICTED_ON_TARIFF' => self::DOMAIN_MESSAGE_PREFIX . 'B2E_RESTRICTED_ON_TARIFF',
	];

	/**
	 * Why the document is unavailable, told apart inside the module alone. A document that does not
	 * exist and a document the subject may not touch are refused with one code and one phrase: two
	 * different refusals would let a run tell the identifiers that exist from the ones that do not by
	 * trying them one after another. The reason below travels beside the refusal and must never be
	 * shown to whoever reads it.
	 */
	public const REASON_DOCUMENT_NOT_FOUND = 'DOCUMENT_NOT_FOUND';
	public const REASON_ACCESS_DENIED = 'ACCESS_DENIED';

	/**
	 * The bounds of the wait for the document to become ready. The step is asked of the scheduler,
	 * which shortens or stretches it by the settings of the portal, so the two limits bound the wait
	 * from different sides: on a portal that keeps the asked step the count runs out first (twenty
	 * wakes of half a minute is ten minutes), and the total limit only comes into play where the
	 * portal has stretched the step by its own minimal delay.
	 *
	 * @see \CBPSchedulerService::calculateExpirationTime() the settings the step is clamped by
	 */
	private const WAIT_STEP = 30;
	private const WAIT_COUNT_LIMIT = 20;
	private const WAIT_TOTAL_LIMIT = 1800;

	/**
	 * By how much the moment of the wake may be missed for the wake to still be taken for one. The
	 * two ends of that comparison are read from two clocks: the moment the agent is due at is written
	 * from the clock of PHP and the agent is picked by the clock of the database, so on a portal that
	 * keeps them on different hosts an honest wake can arrive a few seconds before the moment it was
	 * asked for. An agent of a single run is deleted once it has run, so a wake turned away for that
	 * reason never comes again and the process waits forever - the very outcome the whole wait is
	 * built against.
	 *
	 * The tolerance costs the guard next to nothing: it holds an event of the outside off for all but
	 * the last seconds of the step, and whoever sends one can wait for the lawful moment anyway.
	 *
	 * @see \CAgent::ExecuteAgents() the agents are picked by the clock of the database
	 */
	private const WAKE_CLOCK_TOLERANCE = 5;

	/**
	 * Steps of the domain one hit makes while they keep moving the document. The normal run of the
	 * chain takes two of them (the settings are applied, then the fields are filled and the signing
	 * starts), and a document of a hundred signers takes five, because the fields of the members are
	 * filled in portions. The bound is what keeps one hit from waiting for the signing service over
	 * and over: a document that needs more steps than this gets the rest of them after a wake.
	 *
	 * @see \Bitrix\Sign\Config\Storage::getFieldsFillMembersLimit() the size of one portion
	 */
	private const IMMEDIATE_STEP_LIMIT = 5;

	/**
	 * How the scheduler names itself in the parameters of the event it wakes the activity with.
	 *
	 * @see \CBPSchedulerService::onAgent() the wake of the agent transport
	 */
	private const SCHEDULER_EVENT_PARAM = 'SchedulerService';
	private const SCHEDULER_EVENT_ON_AGENT = 'OnAgent';

	protected static $requiredModules = ['sign', 'crm'];

	/**
	 * The state of the wait, kept between the wakes. The whole activity is serialized into the state
	 * of the process, so these live through a wake; they hold numbers alone on purpose, because the
	 * scheduler unserializes the activity where the autoload of `sign` is not registered.
	 *
	 * @see \CBPActivity::save() the serialization of the instance
	 */
	private int $wakeCount = 0;
	private int $waitDeadline = 0;
	private bool $isWaitingForWake = false;

	/**
	 * The earliest moment an event may be taken for the wake the activity asked for, zero where it
	 * asked for none. The name of the event is known to whoever may see the process, and an event of
	 * it can be sent from the outside, while the moment of the wake cannot be brought closer: the
	 * scheduler only postpones the step it was asked for.
	 *
	 * @see \CBPSchedulerService::calculateExpirationTime() the step of the agent transport, clamped
	 * @see \CBPSchedulerService::calculateDelay() the same for the queue of the messenger
	 */
	private int $earliestWakeTime = 0;

	/** The subscription on time the agent transport is unsubscribed by, zero where there is none */
	private int $wakeSubscriptionId = 0;

	public function __construct($name)
	{
		parent::__construct($name);

		$this->setPropertiesTypes([
			self::PARAM_DOCUMENT_ID => [
				'Type' => FieldType::INT,
			],
			//return
			self::RETURN_PARAM_SIGNING_STATUS => [
				'Type' => FieldType::STRING,
			],
			self::RETURN_PARAM_DOCUMENT_ID => [
				'Type' => FieldType::INT,
			],
			self::RETURN_PARAM_DOCUMENT_STATUS => [
				'Type' => FieldType::STRING,
			],
			self::RETURN_PARAM_SMART_DOCUMENT_ID => [
				'Type' => FieldType::INT,
			],
		]);

		$this->arProperties = [
			'Title' => Loc::getMessage('SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_SEND_ROBOT_TITLE'),
			self::PARAM_DOCUMENT_ID => null,
			//return
			self::RETURN_PARAM_SIGNING_STATUS => null,
			self::RETURN_PARAM_DOCUMENT_ID => null,
			self::RETURN_PARAM_DOCUMENT_STATUS => null,
			self::RETURN_PARAM_SMART_DOCUMENT_ID => null,
		];
	}

	protected function reInitialize(): void
	{
		parent::reInitialize();

		$this->{self::RETURN_PARAM_SIGNING_STATUS} = null;
		$this->{self::RETURN_PARAM_DOCUMENT_ID} = null;
		$this->{self::RETURN_PARAM_DOCUMENT_STATUS} = null;
		$this->{self::RETURN_PARAM_SMART_DOCUMENT_ID} = null;

		$this->wakeCount = 0;
		$this->waitDeadline = 0;
		$this->isWaitingForWake = false;
		$this->earliestWakeTime = 0;
		$this->wakeSubscriptionId = 0;
	}

	/**
	 * Only gates the activity catalog for the current user: the platform never calls it on execution.
	 * The chain flag already covers B2E availability and the regional robot publicity.
	 */
	public static function isAvailable(): bool
	{
		return static::checkModules() && Feature::instance()->isB2eBizprocChainEnabled();
	}

	protected function isExecutionAvailable(): bool
	{
		return
			static::checkModules()
			&& Feature::instance()->isB2eBizprocChainEnabled(
				userId: (int)$this->getTemplateUserId(),
			)
		;
	}

	/**
	 * The halves of the base class are kept, its exit is not: a run that has to wait for the document
	 * leaves as executing and closes itself after a wake, while the base class closes every run of an
	 * activity right away.
	 *
	 * The refusal of the missing modules is told here as well: the base class closes such a run
	 * before the settings are read, without a word, and the process reads the returns whatever the
	 * outcome.
	 *
	 * @see \Bitrix\Bizproc\Activity\BaseActivity::execute() the same halves, always closing
	 */
	public function execute()
	{
		if (!static::checkModules())
		{
			$this->resetReturns();
			$this->logRefusal(self::makeError(self::ERROR_MODULE_UNAVAILABLE));

			return \CBPActivityExecutionStatus::Closed;
		}

		$this->prepareProperties();

		$errors = $this->checkProperties();
		if (!$errors->isEmpty())
		{
			foreach ($errors as $error)
			{
				$this->logRefusal($error);
			}

			return \CBPActivityExecutionStatus::Closed;
		}

		return $this->step(firstRun: true);
	}

	protected function checkProperties(): Main\ErrorCollection
	{
		$errors = parent::checkProperties();

		// isAvailable() is not consulted on execution, so a saved robot is stopped here
		if (!$this->isExecutionAvailable())
		{
			$errors->setError(self::makeError(self::ERROR_UNAVAILABLE));
		}
		elseif ($this->resolveDocumentId() < 1)
		{
			$errors->setError(self::makeError(self::ERROR_EMPTY_DOCUMENT_ID));
		}

		if (!$errors->isEmpty())
		{
			$this->resetReturns();
		}

		return $errors;
	}

	/**
	 * One turn of the activity: the first run of it and every wake alike. The admission and the
	 * rights are asked anew every time, because the document could have gone to signing or changed
	 * while the activity waited.
	 *
	 * @return int the execution status: closed where the turn is the last one, executing where the
	 *         activity has gone to wait for another wake
	 */
	protected function step(bool $firstRun): int
	{
		if ($firstRun)
		{
			$this->resetReturns();
		}

		// deny by default: the rights of the run are those of whoever saved the template, and a run
		// with nobody to ask them of reads no document at all
		if ($this->resolveTemplateAuthorId() < 1)
		{
			return $this->refuseAdmission(self::makeError(self::ERROR_TEMPLATE_WITHOUT_AUTHOR));
		}

		$documentService = Container::instance()->getDocumentService();
		$document = $documentService->getById($this->resolveDocumentId());
		if ($document === null || $document->uid === null)
		{
			return $this->refuseAdmission(self::makeDocumentUnavailableError(self::REASON_DOCUMENT_NOT_FOUND));
		}

		$refusal = $this->checkAdmission($document);
		if ($refusal !== null)
		{
			return $this->refuseAdmission($refusal);
		}

		// the document tells the returns about itself only once it is allowed to be sent
		$this->fillDocumentReturns($document);

		if (self::isAlreadySigning($document))
		{
			$this->setProperty(self::RETURN_PARAM_SIGNING_STATUS, self::STATUS_SUCCESS);

			return \CBPActivityExecutionStatus::Closed;
		}

		return $this->advanceOrWait($document, $documentService, $firstRun);
	}

	/**
	 * Steps of the domain one after another while they keep moving the document, and the wait from
	 * the step that moved nothing on: a document standing still waits for the external human
	 * resources system, and only such a wait is worth the sleep of the process.
	 *
	 * The answers of the operation cannot be compared instead of the document: it answers "not
	 * completed" both for a step that moved the document and for a step that waits, without telling
	 * the two apart.
	 */
	private function advanceOrWait(
		Item\Document $document,
		DocumentService $documentService,
		bool $firstRun
	): int
	{
		$documentUid = (string)$document->uid;
		$immediateSteps = 0;
		// the mark is read once per step: the one taken after a step is the one the next step is
		// compared against, and nothing moves the document in between
		$markBeforeStep = $this->readProgressMark($document);

		while (true)
		{
			$result = $this->createStartOperation($documentUid)->launch();
			$document = $this->readDocumentAfterStep($documentUid, $documentService, $document);

			if (!$result->isSuccess())
			{
				// a step that refused is not repeated: the wait would wait for what already failed
				$this->setProperty(self::RETURN_PARAM_SIGNING_STATUS, self::STATUS_FAILED);
				$this->logRefusal(self::makeStartFailedError($result));

				return \CBPActivityExecutionStatus::Closed;
			}

			if ($result instanceof ConfigureResult && $result->completed)
			{
				$this->setProperty(self::RETURN_PARAM_SIGNING_STATUS, self::STATUS_SUCCESS);

				return \CBPActivityExecutionStatus::Closed;
			}

			$immediateSteps++;

			// the bound is asked first: a hit that has made its steps needs no mark to stop
			if ($immediateSteps >= self::IMMEDIATE_STEP_LIMIT)
			{
				break;
			}

			$markAfterStep = $this->readProgressMark($document);
			if ($markAfterStep === $markBeforeStep)
			{
				break;
			}

			$markBeforeStep = $markAfterStep;
		}

		return $this->wait($firstRun);
	}

	/**
	 * Where the document stands right now: its status together with the number of members the
	 * signing service has not taken the fields of yet. The mark is read and never written, and it
	 * costs one count query: the document itself has just been read for the returns.
	 *
	 * @return array{0: ?string, 1: int}
	 */
	private function readProgressMark(Item\Document $document): array
	{
		return [
			$document->status,
			Container::instance()->getMemberRepository()->countNotConfiguredByDocumentId((int)$document->id),
		];
	}

	/**
	 * The document as the step of the domain has left it, with the returns filled from it. A document
	 * that is gone leaves both alone: the process keeps reading the state the run was admitted with.
	 */
	private function readDocumentAfterStep(
		string $documentUid,
		DocumentService $documentService,
		Item\Document $documentBeforeStep
	): Item\Document
	{
		$document = $documentService->getByUid($documentUid);
		if ($document === null)
		{
			return $documentBeforeStep;
		}

		$this->fillDocumentReturns($document);

		return $document;
	}

	/**
	 * The wait for the document to become ready: the first run sets the bounds of the whole wait and
	 * tells the process log about it, and every turn after that spends one wake of it.
	 */
	private function wait(bool $firstRun): int
	{
		if ($firstRun)
		{
			$this->waitDeadline = $this->now() + self::WAIT_TOTAL_LIMIT;
			$this->wakeCount = 0;
			$this->log(Loc::getMessage(self::PHRASE_LOG_WAIT_STARTED) ?? '');
		}

		if (
			$this->wakeCount >= self::WAIT_COUNT_LIMIT
			|| $this->now() + self::WAIT_STEP >= $this->waitDeadline
		)
		{
			return $this->timeOut(self::ERROR_WAIT_EXHAUSTED);
		}

		$this->wakeCount++;

		// a wake nothing will bring is no wait at all: the run closes instead of standing forever
		return $this->subscribeOnWake($this, self::WAIT_STEP)
			? \CBPActivityExecutionStatus::Executing
			: $this->timeOut(self::ERROR_WAIT_NOT_POSSIBLE)
		;
	}

	/**
	 * The document is still not being signed and no more waiting will be done. Nothing about the
	 * document was wrong, so the outcome is its own and not a refusal; the code tells the two
	 * reasons apart, because they are answered by different people: a wait that ran out of its
	 * wakes or of its time is about the external service, and a wait that could not be stood in
	 * at all is about the portal.
	 */
	private function timeOut(string $code): int
	{
		$this->setProperty(self::RETURN_PARAM_SIGNING_STATUS, self::STATUS_TIMED_OUT);
		$this->logRefusal(self::makeError($code));

		return \CBPActivityExecutionStatus::Closed;
	}

	/**
	 * The document may not be sent, and the returns say nothing about it: the refusal is the same on
	 * the first run and on a wake, so a document that changed into an unacceptable one while the
	 * activity waited tells the process no more than one refused from the start.
	 */
	private function refuseAdmission(Main\Error $error): int
	{
		$this->resetReturns();
		$this->logRefusal($error);

		return \CBPActivityExecutionStatus::Closed;
	}

	/**
	 * The reason the run was refused, written to the process log. The base class writes the errors of
	 * its own exit itself; this activity leaves by status instead, so its refusals are written here.
	 */
	protected function logRefusal(Main\Error $error): void
	{
		$this->logError((string)$error->getMessage());
	}

	/**
	 * The wake of the timer, and nothing else: the activity subscribes to no events of its own, so
	 * an event that came while it was not waiting and one another source named itself in are left
	 * alone. An event that came too early makes no turn either, but the wake is asked for anew: the
	 * one that was asked for may have been spent on that very event.
	 *
	 * The modules are included here as well, and not by whoever brought the wake: the agent belongs
	 * to `bizproc` and looks the file of the activity up alone, and the receiver of the queue
	 * includes nothing at all, so on this path the autoload of `sign` is registered by nobody else.
	 * A wake without them ends the run the way the first run of it ends: neither the process nor the
	 * wake may be lost to a module that is not installed.
	 *
	 * @see \Bitrix\Bizproc\Runtime\ActivitySearcher\Searcher::includeActivityFile() the agent path
	 * @see \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Receiver\WorkflowResumeReceiver
	 *      the path of the queue
	 */
	public function onExternalEvent($arEventParameters = [])
	{
		if ($this->executionStatus === \CBPActivityExecutionStatus::Closed)
		{
			return;
		}

		if (!$this->isWaitingForWake || !self::isSourceOfTheTimer((array)$arEventParameters))
		{
			return;
		}

		if ($this->isBeforeTheMomentOfTheWake())
		{
			$this->renewTheWake();

			return;
		}

		$this->unsubscribe($this);

		if (!static::checkModules())
		{
			$this->resetReturns();
			$this->logRefusal(self::makeError(self::ERROR_MODULE_UNAVAILABLE));
			$this->closeAfterWake();

			return;
		}

		if ($this->step(firstRun: false) === \CBPActivityExecutionStatus::Closed)
		{
			$this->closeAfterWake();
		}
	}

	/**
	 * Whether the event came from the timer as far as its parameters tell. The two transports of the
	 * scheduler announce a wake differently: the agent one names itself in the parameters of the
	 * event, while the messenger one sends no parameters at all. So the wait of the activity itself
	 * is what tells a wake, and the parameters are only asked whether another source has named
	 * itself in them.
	 *
	 * @see \CBPSchedulerService::onAgent() the parameters of the agent transport
	 * @see \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Receiver\WorkflowResumeReceiver
	 *      the messenger transport, which sends none
	 */
	private static function isSourceOfTheTimer(array $eventParameters): bool
	{
		$source = $eventParameters[self::SCHEDULER_EVENT_PARAM] ?? null;

		return $source === null || $source === self::SCHEDULER_EVENT_ON_AGENT;
	}

	/**
	 * Whether the event came before the moment the run asked to be woken at. The parameters alone
	 * tell no wake: an event of the messenger transport looks like an event with nothing in it, and
	 * such an event can be sent from the outside by whoever may see the process. The moment of the
	 * wake is what cannot be forged, so an event that came before it makes no turn of the wait.
	 *
	 * The moment is missed by the tolerance and not by a second: the clocks the two ends of the
	 * comparison are read from are not the same one. The tolerance is not to be tightened back into
	 * an exact comparison.
	 *
	 * @see self::WAKE_CLOCK_TOLERANCE the clocks and what they cost
	 */
	private function isBeforeTheMomentOfTheWake(): bool
	{
		return $this->now() + self::WAKE_CLOCK_TOLERANCE < $this->earliestWakeTime;
	}

	/**
	 * The wake asked for anew, for what is left of the step, after an event that came too early. Such
	 * an event moves the wait nowhere: no turn is made, no wake of the count is spent and the moment
	 * of the wake stays the one the run asked for, so an event of the outside buys nothing by being
	 * sent and the whole wait stays bounded by its deadline.
	 *
	 * The subscription is renewed and not left alone, because the wake that was asked for may be
	 * gone: where the clocks of the portal disagree by more than the tolerance, the early event is
	 * the honest wake itself, and both transports bring a wake once - the agent of a single run is
	 * deleted as it runs, and the message of the queue is taken out of it as it is read. A wait
	 * whose only wake was turned away this way would never end.
	 */
	private function renewTheWake(): void
	{
		$momentOfTheWake = $this->earliestWakeTime;

		$this->unsubscribe($this);

		if ($this->subscribeOnWake($this, $momentOfTheWake - $this->now()))
		{
			return;
		}

		// the subscription is already taken back, so a wake nobody will bring leaves nothing to wait for
		$this->timeOut(self::ERROR_WAIT_NOT_POSSIBLE);
		$this->closeAfterWake();
	}

	/**
	 * The activity closes itself after a wake: the status it returns is the answer to the platform
	 * that started it, and the turn after a wake has no such caller.
	 */
	protected function closeAfterWake(): void
	{
		$this->workflow->closeActivity($this);
	}

	public function cancel()
	{
		$this->unsubscribe($this);

		return \CBPActivityExecutionStatus::Closed;
	}

	public function subscribe(IBPActivityExternalEventListener $eventHandler)
	{
		$this->subscribeOnWake($eventHandler, self::WAIT_STEP);
	}

	public function unsubscribe(IBPActivityExternalEventListener $eventHandler)
	{
		$this->unsubscribeFromWake($eventHandler);
	}

	/**
	 * Asks for a wake in the given number of seconds. Which transport brings it is the business of
	 * the portal: the queue of the messenger where it is switched on, the agents of the scheduler
	 * where it is not. The step itself is not bounded here: the scheduler shortens and stretches it
	 * by the settings of the portal on both transports.
	 *
	 * The refusal of the wake is seen on the transport of the agents alone: the messenger one hands
	 * the message to the queue through a send declared `void`, so nothing of what became of it comes
	 * back and that branch answers true always. The reference `delayactivity` of the platform is
	 * built the same way; it is the limit of the platform and not a decision of this activity.
	 *
	 * @see \CBPSchedulerService::calculateDelay() the bounds of the messenger transport
	 * @see \CBPSchedulerService::calculateExpirationTime() the same bounds for the agents
	 * @see \Bitrix\Main\Messenger\Entity\AbstractMessage::send() the send of the queue that tells nothing
	 *
	 * @return bool whether the wake was asked for, as far as the transport lets it be known: false
	 *         where the scheduler refused the subscription of the agent transport, true on the queue
	 *         of the messenger, where the delivery is never confirmed. A run that could not ask for
	 *         a wake waits no more.
	 */
	protected function subscribeOnWake(IBPActivityExternalEventListener $eventHandler, int $stepSeconds): bool
	{
		$schedulerService = $this->getSchedulerService();
		if ($schedulerService === null)
		{
			return false;
		}

		// the moment is read once and given to both the transport and the wait: two reads of the clock
		// could fall on different seconds, and a wake asked for earlier than it is waited for is lost
		$wakeTime = $this->now() + $stepSeconds;

		if ($schedulerService->useMessengerTransport())
		{
			$schedulerService->sendResumeWorkflowMessage(
				$this->getWorkflowInstanceId(),
				$this->name,
				$stepSeconds,
				ResumeWorkflowQueue::RobotDelay,
			);
		}
		else
		{
			// the identifier is what the unsubscribe of the agent transport is made by
			$this->wakeSubscriptionId = (int)$schedulerService->subscribeOnTime(
				$this->getWorkflowInstanceId(),
				$this->name,
				$wakeTime,
			);

			if ($this->wakeSubscriptionId <= 0)
			{
				return false;
			}
		}

		$this->addWakeEventHandler($eventHandler);
		$this->isWaitingForWake = true;
		$this->earliestWakeTime = $wakeTime;

		return true;
	}

	protected function unsubscribeFromWake(IBPActivityExternalEventListener $eventHandler): void
	{
		$this->isWaitingForWake = false;
		$this->earliestWakeTime = 0;

		$schedulerService = $this->getSchedulerService();
		if ($schedulerService !== null && $this->wakeSubscriptionId > 0)
		{
			$schedulerService->unSubscribeOnTime($this->wakeSubscriptionId);
		}
		$this->wakeSubscriptionId = 0;

		$this->removeWakeEventHandler($eventHandler);
	}

	protected function getSchedulerService(): ?\CBPSchedulerService
	{
		$schedulerService = $this->workflow->getService('SchedulerService');

		return $schedulerService instanceof \CBPSchedulerService ? $schedulerService : null;
	}

	protected function addWakeEventHandler(IBPActivityExternalEventListener $eventHandler): void
	{
		$this->workflow->addEventHandler($this->name, $eventHandler);
	}

	protected function removeWakeEventHandler(IBPActivityExternalEventListener $eventHandler): void
	{
		$this->workflow->removeEventHandler($this->name, $eventHandler);
	}

	/**
	 * Now, as the activity reads it. A method of its own so that the tests reach the border of the
	 * whole wait without waiting for it.
	 */
	protected function now(): int
	{
		return time();
	}

	/**
	 * The process reads the returns whatever the outcome, so no branch of the exit may leave one
	 * unset.
	 */
	private function resetReturns(): void
	{
		$this->setProperty(self::RETURN_PARAM_SIGNING_STATUS, self::STATUS_FAILED);
		$this->setProperty(self::RETURN_PARAM_DOCUMENT_ID, $this->resolveDocumentId());
		$this->setProperty(self::RETURN_PARAM_DOCUMENT_STATUS, '');
		$this->setProperty(self::RETURN_PARAM_SMART_DOCUMENT_ID, 0);
	}

	/**
	 * Whether the document may be brought to signing at all, and the refusal where it may not. The
	 * rights come first, so a document the subject may not touch tells nothing about itself. The
	 * chain sends the documents a company sends to an employee; a document the employee sends of
	 * their own goes by another flow and lays its parties out the other way round.
	 *
	 * A document already being signed is no refusal: the run reports the outcome the process asked
	 * for and sends nothing anew.
	 *
	 * @return ?Main\Error null where the document is admitted or already being signed
	 */
	private function checkAdmission(Item\Document $document): ?Main\Error
	{
		if (!self::canEditB2eDocuments($this->resolveTemplateAuthorId(), $document))
		{
			return self::makeDocumentUnavailableError(self::REASON_ACCESS_DENIED);
		}

		if (!DocumentScenario::isB2EScenario($document->scenario))
		{
			return self::makeError(self::ERROR_NOT_B2E_DOCUMENT);
		}

		if ($document->isInitiatedByEmployee())
		{
			return self::makeError(self::ERROR_INITIATED_BY_EMPLOYEE);
		}

		if (self::isAlreadySigning($document))
		{
			return null;
		}

		if (DocumentStatus::isFinalByDocument($document))
		{
			return self::makeError(self::ERROR_DOCUMENT_FINISHED);
		}

		// `new` is a document whose file has not reached the signing service yet, and a status
		// outside the dictionary is no better a ground to send it
		if (!in_array($document->status, self::getSendableStatuses(), true))
		{
			return self::makeError(self::ERROR_DOCUMENT_NOT_UPLOADED);
		}

		// a document without a single member would leave for the signing service empty: the domain
		// checks the parties of the blocks against the composition, a blank without placeholders has
		// no blocks to check, and the start itself asks nothing about the members
		// @see \Bitrix\Sign\Operation\SigningStart
		return self::hasMembers($document) ? null : self::makeError(self::ERROR_NO_MEMBERS);
	}

	/**
	 * Statuses of a document that may still be brought to signing. A method and not a constant on
	 * purpose: constants are evaluated where the activity is unserialized, and the scheduler does
	 * that where the autoload of `sign` is not registered.
	 *
	 * @return list<string>
	 */
	private static function getSendableStatuses(): array
	{
		return [
			DocumentStatus::UPLOADED,
			DocumentStatus::READY,
		];
	}

	private static function isAlreadySigning(Item\Document $document): bool
	{
		return $document->status === DocumentStatus::SIGNING;
	}

	private static function hasMembers(Item\Document $document): bool
	{
		return Container::instance()->getMemberRepository()->countByDocumentId((int)$document->id) > 0;
	}

	/**
	 * The subject the document is checked as an object for: the process itself runs with system
	 * rights, so the rights asked are those of whoever saved the template. Zero means the template
	 * carries no author of its own (an import or a system creation), and there is nobody to ask.
	 */
	private function resolveTemplateAuthorId(): int
	{
		return (int)$this->getTemplateUserId();
	}

	private function fillDocumentReturns(Item\Document $document): void
	{
		$this->setProperty(self::RETURN_PARAM_DOCUMENT_ID, (int)$document->id);
		$this->setProperty(self::RETURN_PARAM_DOCUMENT_STATUS, (string)$document->status);
		$this->setProperty(self::RETURN_PARAM_SMART_DOCUMENT_ID, (int)$document->entityId);
	}

	/**
	 * The document of the settings, or zero where they name none. The value is taken the way the
	 * platform takes it: an expression of the settings is only known on execution, so the raw
	 * parameter is read through the same substitution.
	 */
	private function resolveDocumentId(): int
	{
		$value = self::normalizeToString($this->parseValue($this->getRawProperty(self::PARAM_DOCUMENT_ID)));

		return self::isDigitsOnly($value) ? (int)$value : 0;
	}

	/**
	 * The operation that brings the document to signing, created by the uid alone: the activity
	 * configures nothing of the document itself and holds no lock of its own, the operation keeps
	 * the critical section of the start. A method of its own so that the tests substitute the step
	 * instead of reaching the signing service.
	 *
	 * @see \Bitrix\Sign\Operation\ConfigureFillAndStart::launch() the step and what it answers with
	 */
	protected function createStartOperation(string $documentUid): Operation
	{
		return new ConfigureFillAndStart($documentUid);
	}

	/**
	 * The map must stay free of CRM and external service calls: the same map serves
	 * rendering, settings validation and execution.
	 */
	protected static function getPropertiesMap(array $documentType, array $context = []): array
	{
		return [
			self::PARAM_DOCUMENT_ID => [
				'Name' => Loc::getMessage('SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_SEND_DOCUMENT_ID'),
				'Hint' => Loc::getMessage('SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_SEND_DOCUMENT_ID_HINT'),
				'FieldName' => self::PARAM_DOCUMENT_ID,
				'Type' => FieldType::INT,
				'Required' => true,
			],
		];
	}

	public static function getPropertiesDialogMap(?PropertiesDialog $dialog = null): array
	{
		return static::getPropertiesMap($dialog?->getDocumentType() ?? []);
	}

	protected static function getFileName(): string
	{
		return __FILE__;
	}

	/**
	 * Right of the subject to the given B2E document: the permission of the scope is only honoured
	 * against an object.
	 */
	private static function canEditB2eDocuments(int $userId, Item\Document $document): bool
	{
		// createForCurrentUser() may fall back to an all-allowing controller, the subject is explicit here
		$accessController = Container::instance()->getAccessControllerFactory()->createByUserId($userId);

		return $accessController !== null
			&& $accessController->checkByItem(ActionDictionary::ACTION_B2E_DOCUMENT_EDIT, $document)
		;
	}

	/**
	 * The refusal of the given reason: the code names the reason, and the phrase of that very code
	 * is what the process log shows.
	 *
	 * @param ?array $replace values substituted into the phrase
	 * @param ?array $customData what the reason alone does not tell the reader of the refusal
	 */
	private static function makeError(string $code, ?array $replace = null, ?array $customData = null): Main\Error
	{
		return new Main\Error(
			Loc::getMessage(self::ERROR_MESSAGE_PREFIX . $code, $replace) ?? '',
			$code,
			$customData,
		);
	}

	/**
	 * The failure of the step named together with the reasons the domain gave for it. The reasons
	 * are the one text of this activity that comes from elsewhere, and the process log is printed
	 * as raw HTML, so they are escaped here, where they enter a phrase for the first time.
	 */
	private static function makeStartFailedError(Main\Result $result): Main\Error
	{
		$reasons = [];
		foreach ($result->getErrors() as $error)
		{
			$reason = self::describeDomainError($error);
			if ($reason !== '')
			{
				$reasons[$reason] = $reason;
			}
		}

		if ($reasons === [])
		{
			return self::makeError(self::ERROR_START_FAILED);
		}

		return new Main\Error(
			Loc::getMessage(self::PHRASE_START_FAILED_WITH_REASON, ['#ERRORS#' => implode('; ', $reasons)]) ?? '',
			self::ERROR_START_FAILED,
		);
	}

	/**
	 * What one refusal of the domain says to whoever reads the process log: a code the activity has
	 * a phrase for is told with that phrase, and every other refusal keeps the text of the domain,
	 * escaped. A phrase that came back empty falls back to that text as well: a refusal must not be
	 * lost to a missing phrase.
	 */
	private static function describeDomainError(Main\Error $error): string
	{
		$phraseCode = self::DOMAIN_ERROR_PHRASES[(string)$error->getCode()] ?? null;
		$phrase = $phraseCode === null ? '' : (string)Loc::getMessage($phraseCode);

		return $phrase === '' ? htmlspecialcharsbx(trim((string)$error->getMessage())) : $phrase;
	}

	/**
	 * The document cannot be worked with, and the refusal says no more than that: a document that
	 * does not exist and a document the subject may not touch carry one code and one phrase, so that
	 * the outcome of the run tells nothing about which identifiers name a document. The reason is
	 * kept beside the refusal for the module itself.
	 *
	 * @see self::REASON_DOCUMENT_NOT_FOUND the reasons and why they stay inside
	 */
	private static function makeDocumentUnavailableError(string $reason): Main\Error
	{
		return self::makeError(self::ERROR_DOCUMENT_UNAVAILABLE, null, ['reason' => $reason]);
	}

	/**
	 * Whether the value is a number and nothing else. The `ctype` extension is not installed on the
	 * portals and a call of it fails with a fatal error, so the check is a pattern: the `D` modifier
	 * keeps a trailing line break out of the value, and the strict comparison reads an internal
	 * failure of the engine as "not a number".
	 */
	private static function isDigitsOnly(string $value): bool
	{
		return preg_match('/^[0-9]+$/D', $value) === 1;
	}

	private static function normalizeToString(mixed $value): string
	{
		if (is_array($value))
		{
			$value = reset($value);
		}

		return is_scalar($value) ? trim((string)$value) : '';
	}
}
