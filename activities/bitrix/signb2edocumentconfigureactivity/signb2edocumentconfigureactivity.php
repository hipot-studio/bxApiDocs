<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Bizproc\Activity\BaseActivity;
use Bitrix\Bizproc\Activity\PropertiesDialog;
use Bitrix\Bizproc\FieldType;
use Bitrix\Main;
use Bitrix\Main\Localization\Loc;
use Bitrix\Sign;
use Bitrix\Sign\Access\ActionDictionary;
use Bitrix\Sign\Integration\Bitrix24\B2eTariff;
use Bitrix\Sign\Item;
use Bitrix\Sign\Item\Document\Configure\Journal;
use Bitrix\Sign\Item\Document\Configure\Plan;
use Bitrix\Sign\Item\Document\Configure\Step;
use Bitrix\Sign\Operation\Document\Validation\ValidateDateSignUntil;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Service\Sign\DocumentService;
use Bitrix\Sign\Type\Document\ExternalIdSourceType;
use Bitrix\Sign\Type\DocumentScenario;
use Bitrix\Sign\Type\DocumentStatus;
use Bitrix\Sign\Type\Member\EntityType;
use Bitrix\Sign\Type\Member\Notification\ReminderType;
use Bitrix\Sign\Type\Member\Role;
use Bitrix\Sign\Type\ProviderCode;

/**
 * @property int $documentId
 * @property string $company
 * @property string $providerCode
 * @property string[] $representative
 * @property string[] $signers
 * @property bool $replaceSigners
 * @property string $documentCode
 * @property string $externalId
 * @property string $reminderType
 * @property mixed $dateSignUntil
 * @property string $configurationStatus
 * @property int $resultDocumentId
 * @property string $documentStatus
 * @property int $smartDocumentId
 */
class CBPSignB2EDocumentConfigureActivity extends BaseActivity implements IBPConfigurableActivity
{
	public const PARAM_DOCUMENT_ID = 'documentId';
	public const PARAM_COMPANY = 'company';
	public const PARAM_PROVIDER_CODE = 'providerCode';
	public const PARAM_REPRESENTATIVE = 'representative';
	public const PARAM_SIGNERS = 'signers';
	public const PARAM_REPLACE_SIGNERS = 'replaceSigners';
	public const PARAM_DOCUMENT_CODE = 'documentCode';
	public const PARAM_EXTERNAL_ID = 'externalId';
	public const PARAM_REMINDER_TYPE = 'reminderType';
	public const PARAM_DATE_SIGN_UNTIL = 'dateSignUntil';

	public const RETURN_PARAM_CONFIGURATION_STATUS = 'configurationStatus';
	public const RETURN_PARAM_DOCUMENT_ID = 'resultDocumentId';
	public const RETURN_PARAM_DOCUMENT_STATUS = 'documentStatus';
	public const RETURN_PARAM_SMART_DOCUMENT_ID = 'smartDocumentId';

	public const STATUS_SUCCESS = 'SUCCESS';
	public const STATUS_FAILED = 'FAILED';

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
	public const ERROR_DOCUMENT_READY = 'DOCUMENT_READY';
	public const ERROR_DOCUMENT_LOCKED = 'DOCUMENT_LOCKED';
	public const ERROR_DOCUMENT_BUSY = 'DOCUMENT_BUSY';
	public const ERROR_EMPTY_SETTING = 'EMPTY_SETTING';
	public const ERROR_INVALID_COMPANY = 'INVALID_COMPANY';
	public const ERROR_COMPANY_NOT_FOUND = 'COMPANY_NOT_FOUND';
	public const ERROR_COMPANY_WITHOUT_TAX_ID = 'COMPANY_WITHOUT_TAX_ID';
	public const ERROR_COMPANY_WITHOUT_PROVIDER = 'COMPANY_WITHOUT_PROVIDER';
	public const ERROR_COMPANY_CHANGED = 'COMPANY_CHANGED';
	public const ERROR_INVALID_PROVIDER = 'INVALID_PROVIDER';
	public const ERROR_TECHNICAL_PROVIDER = 'TECHNICAL_PROVIDER';
	public const ERROR_PROVIDER_NOT_IN_COMPANY = 'PROVIDER_NOT_IN_COMPANY';
	public const ERROR_INVALID_REPRESENTATIVE = 'INVALID_REPRESENTATIVE';
	public const ERROR_REPRESENTATIVE_OF_ROLE = 'REPRESENTATIVE_OF_ROLE';
	public const ERROR_INVALID_REMINDER = 'INVALID_REMINDER';
	public const ERROR_REMINDER_WITHOUT_SIGNERS = 'REMINDER_WITHOUT_SIGNERS';
	public const ERROR_REPLACE_WITHOUT_SIGNERS = 'REPLACE_WITHOUT_SIGNERS';
	public const ERROR_SIGNERS_NOT_RESOLVED = 'SIGNERS_NOT_RESOLVED';
	public const ERROR_TOO_MANY_SIGNERS = 'TOO_MANY_SIGNERS';
	public const ERROR_NO_ELIGIBLE_SIGNERS = 'NO_ELIGIBLE_SIGNERS';
	public const ERROR_MEMBERS_WITHOUT_COMPANY = 'MEMBERS_WITHOUT_COMPANY';
	public const ERROR_INVALID_DATE_SIGN_UNTIL = 'INVALID_DATE_SIGN_UNTIL';

	private const ERROR_MESSAGE_PREFIX = 'SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_CONFIGURE_ERROR_';

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
	 * Own lock name: the signing start lock guards another critical section, and its holder
	 * refuses without a single error, so a document busy with the start would look configured.
	 * The price of the own name is that the start is not excluded from running alongside: the
	 * admission asked again under the lock narrows that window, it does not close it.
	 */
	private const LOCK_NAME_PREFIX = 'sign_document_configure_';

	/**
	 * Signers of one run where the tariff bounds nothing: the expanded list becomes the member
	 * composition of a single document and is written under its lock, so it needs a bound anyway.
	 */
	private const MAX_SIGNERS_WITHOUT_TARIFF_LIMIT = 1000;

	/** Settings of the document the activity applies, in the order the settings form shows them */
	private const SETTINGS = [
		self::PARAM_COMPANY,
		self::PARAM_PROVIDER_CODE,
		self::PARAM_REPRESENTATIVE,
		self::PARAM_SIGNERS,
		self::PARAM_DOCUMENT_CODE,
		self::PARAM_EXTERNAL_ID,
		self::PARAM_REMINDER_TYPE,
		self::PARAM_DATE_SIGN_UNTIL,
	];

	protected static $requiredModules = ['sign', 'crm'];

	/**
	 * What the signing service has already answered about the registrations of a company in this hit:
	 * the registrations it named, or the refusal it came back with. A refusal is remembered the way an
	 * answer is, because the wait for the service is thirty seconds long and a hit that asked about
	 * one company would otherwise pay that wait again for the same nothing.
	 *
	 * The key is the company and the signing order the question was asked about, and the answer
	 * describes that very pair: a document moved to another company asks under a key of its own and
	 * is answered about the company it moved to.
	 *
	 * @var array<string, array{providers: array<array<string, mixed>>, errors: Main\Error[]}>
	 */
	private static array $companyProviders = [];

	/** Titles of the settings the way the settings form names them */
	private static array $settingTitles = [];

	/**
	 * The provider code the step of the company moves the document to, null while the run moves the
	 * document to none. The settings named no provider, so the pair was taken from the registration
	 * the company made last, and the line of the applied settings names the company alone.
	 *
	 * @see self::logProviderMovedByTheCompany() the line the process log gets for it
	 */
	private ?string $providerCodeMovedByTheCompany = null;

	public function __construct($name)
	{
		parent::__construct($name);

		$this->setPropertiesTypes([
			self::PARAM_DOCUMENT_ID => [
				'Type' => FieldType::INT,
			],
			self::PARAM_COMPANY => [
				'Type' => FieldType::STRING,
			],
			self::PARAM_PROVIDER_CODE => [
				'Type' => FieldType::STRING,
			],
			self::PARAM_REPRESENTATIVE => [
				'Type' => FieldType::USER,
			],
			self::PARAM_SIGNERS => [
				'Type' => FieldType::USER,
				'Multiple' => true,
			],
			self::PARAM_REPLACE_SIGNERS => [
				'Type' => FieldType::BOOL,
			],
			self::PARAM_DOCUMENT_CODE => [
				'Type' => FieldType::STRING,
			],
			self::PARAM_EXTERNAL_ID => [
				'Type' => FieldType::STRING,
			],
			self::PARAM_REMINDER_TYPE => [
				'Type' => FieldType::SELECT,
			],
			self::PARAM_DATE_SIGN_UNTIL => [
				'Type' => FieldType::DATETIME,
			],
			//return
			self::RETURN_PARAM_CONFIGURATION_STATUS => [
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
			'Title' => Loc::getMessage('SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_CONFIGURE_ROBOT_TITLE'),
			self::PARAM_DOCUMENT_ID => null,
			self::PARAM_COMPANY => null,
			self::PARAM_PROVIDER_CODE => null,
			self::PARAM_REPRESENTATIVE => null,
			self::PARAM_SIGNERS => null,
			self::PARAM_REPLACE_SIGNERS => 'N',
			self::PARAM_DOCUMENT_CODE => null,
			self::PARAM_EXTERNAL_ID => null,
			self::PARAM_REMINDER_TYPE => null,
			self::PARAM_DATE_SIGN_UNTIL => null,
			//return
			self::RETURN_PARAM_CONFIGURATION_STATUS => null,
			self::RETURN_PARAM_DOCUMENT_ID => null,
			self::RETURN_PARAM_DOCUMENT_STATUS => null,
			self::RETURN_PARAM_SMART_DOCUMENT_ID => null,
		];
	}

	protected function reInitialize(): void
	{
		parent::reInitialize();

		$this->{self::RETURN_PARAM_CONFIGURATION_STATUS} = null;
		$this->{self::RETURN_PARAM_DOCUMENT_ID} = null;
		$this->{self::RETURN_PARAM_DOCUMENT_STATUS} = null;
		$this->{self::RETURN_PARAM_SMART_DOCUMENT_ID} = null;
	}

	/**
	 * Only gates the activity catalog for the current user: the platform never calls it on execution.
	 * The chain flag already covers B2E availability and the regional robot publicity.
	 */
	public static function isAvailable(): bool
	{
		return static::checkModules() && Sign\Config\Feature::instance()->isB2eBizprocChainEnabled();
	}

	protected function isExecutionAvailable(): bool
	{
		return
			static::checkModules()
			&& Sign\Config\Feature::instance()->isB2eBizprocChainEnabled(
				userId: (int)$this->getTemplateUserId(),
			)
		;
	}

	/**
	 * The modules the activity needs are checked by the base class, and a run without one of them is
	 * closed there before the settings are read: the returns stay unset and the process log gets no
	 * line at all. The next link of the chain reads the returns whatever the outcome, so the refusal
	 * is told here instead.
	 *
	 * @see \Bitrix\Bizproc\Activity\BaseActivity::execute() the same guard, silent
	 */
	public function execute()
	{
		if (!static::checkModules())
		{
			$this->resetReturns();
			$this->logError(self::makeError(self::ERROR_MODULE_UNAVAILABLE)->getMessage());

			return \CBPActivityExecutionStatus::Closed;
		}

		return parent::execute();
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

	protected function internalExecute(): Main\ErrorCollection
	{
		$errors = new Main\ErrorCollection();
		$this->resetReturns();

		// deny by default: the rights of the run are those of whoever saved the template, and a run
		// with nobody to ask them of reads no document at all
		if ($this->resolveTemplateAuthorId() < 1)
		{
			$errors->setError(self::makeError(self::ERROR_TEMPLATE_WITHOUT_AUTHOR));

			return $errors;
		}

		$documentService = Container::instance()->getDocumentService();
		$document = $documentService->getById($this->resolveDocumentId());
		if ($document === null || $document->uid === null)
		{
			$errors->setError(self::makeDocumentUnavailableError(self::REASON_DOCUMENT_NOT_FOUND));

			return $errors;
		}

		$admissionErrors = $this->checkAdmission($document);
		if (!$admissionErrors->isEmpty())
		{
			return $admissionErrors;
		}

		// the document tells the returns about itself only once it is allowed to be configured
		$this->fillDocumentReturns($document);

		$plan = $this->buildPlan($document);
		if ($plan->hasErrors())
		{
			$errors->add($plan->getErrors()->toArray());

			return $errors;
		}

		if ($plan->isEmpty())
		{
			$this->logNothingToApply();
			$this->setProperty(self::RETURN_PARAM_CONFIGURATION_STATUS, self::STATUS_SUCCESS);

			return $errors;
		}

		$errors->add($this->applyPlan($plan, $document, $documentService)->toArray());

		$this->setProperty(
			self::RETURN_PARAM_CONFIGURATION_STATUS,
			$errors->isEmpty() ? self::STATUS_SUCCESS : self::STATUS_FAILED,
		);

		return $errors;
	}

	/**
	 * The next link of the chain reads the returns whatever the outcome, so no branch of the exit
	 * may leave one unset.
	 */
	private function resetReturns(): void
	{
		$this->setProperty(self::RETURN_PARAM_CONFIGURATION_STATUS, self::STATUS_FAILED);
		$this->setProperty(self::RETURN_PARAM_DOCUMENT_ID, $this->resolveDocumentId());
		$this->setProperty(self::RETURN_PARAM_DOCUMENT_STATUS, '');
		$this->setProperty(self::RETURN_PARAM_SMART_DOCUMENT_ID, 0);
	}

	/**
	 * Whether the document may be configured at all: the settings are not touched until it is.
	 * The rights come first, so a document the subject may not touch tells nothing about itself.
	 * The chain configures the documents a company sends to an employee; a document the employee
	 * sends of their own goes by another flow and lays its parties out the other way round.
	 *
	 * Only a document nothing has been sent for yet is configured. A document brought to `ready`
	 * already has its member composition in the signing service, and the launch link of the chain
	 * sends such a document as it is instead of configuring it again: the members created anew
	 * would be shown on the portal while the invitations stayed with the previous ones.
	 */
	private function checkAdmission(Item\Document $document): Main\ErrorCollection
	{
		$errors = new Main\ErrorCollection();

		if (!self::canEditB2eDocuments($this->resolveTemplateAuthorId(), $document))
		{
			$errors->setError(self::makeDocumentUnavailableError(self::REASON_ACCESS_DENIED));
		}
		elseif (!DocumentScenario::isB2EScenario($document->scenario))
		{
			$errors->setError(self::makeError(self::ERROR_NOT_B2E_DOCUMENT));
		}
		elseif ($document->isInitiatedByEmployee())
		{
			$errors->setError(self::makeError(self::ERROR_INITIATED_BY_EMPLOYEE));
		}
		elseif ($document->status === DocumentStatus::READY)
		{
			$errors->setError(self::makeError(self::ERROR_DOCUMENT_READY));
		}
		elseif (!DocumentStatus::isEditableByDocument($document))
		{
			$errors->setError(self::makeError(self::ERROR_DOCUMENT_LOCKED));
		}

		return $errors;
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
	 * Collects what has to change without changing anything: every filled parameter is checked,
	 * the dictionaries it needs are resolved, and the steps come back in their apply order.
	 */
	protected function buildPlan(Item\Document $document): Plan
	{
		$errors = new Main\ErrorCollection();
		$documentService = Container::instance()->getDocumentService();

		// the member step is the composition writer of the run where the settings ask for one, and
		// the representative step takes that over where they do not
		$membersStep = $this->buildMembersStep($document, $errors);

		$steps = array_filter([
			$this->buildRepresentativeStep($document, $documentService, $errors, $membersStep === null),
			...$this->buildCompanyAndProviderSteps($document, $documentService, $errors),
			$membersStep,
			$this->buildDocumentCodeStep($documentService, $errors),
			$this->buildExternalIdStep($documentService, $errors),
			$this->buildDateSignUntilStep($document, $documentService, $errors),
		]);

		return $errors->isEmpty()
			? Plan::createWithSteps(...$steps)
			: Plan::createWithErrors($errors)
		;
	}

	/**
	 * Nothing is written until the lock is taken, and the lock is released in any outcome.
	 * A document someone else is changing is refused by an error of its own, so a busy document
	 * is not mistaken for a document the settings cannot be applied to.
	 *
	 * The irreversible tail of the plan is applied after the lock is released: such a step writes
	 * into the signing service, the answer of which is waited for thirty seconds, and a lock held
	 * over that wait refuses every neighbour of the run for the whole of it. Nothing local follows
	 * the tail, so the lock has no invariant left to keep over it. What the lock cannot keep is
	 * asked instead: the document is admitted once more before the tail is applied.
	 *
	 * @see self::applyIrreversibleTail() the admission asked with the lock already released
	 * @see \Bitrix\Sign\Service\ApiService the timeout of the calls to the service
	 */
	private function applyPlan(
		Plan $plan,
		Item\Document $document,
		DocumentService $documentService
	): Main\ErrorCollection
	{
		$errors = new Main\ErrorCollection();
		$journal = new Journal();
		[$stepsUnderLock, $tail] = self::splitIrreversibleTail($plan);

		$lockName = self::LOCK_NAME_PREFIX . $document->uid;
		if (!$this->acquireDocumentLock($lockName))
		{
			$errors->setError(self::makeError(self::ERROR_DOCUMENT_BUSY));

			return $errors;
		}

		try
		{
			$snapshot = $this->applyPlanToLockedDocument(
				$plan,
				$stepsUnderLock,
				$journal,
				$document,
				$documentService,
				$errors,
			);
		}
		finally
		{
			$this->releaseDocumentLock($lockName);
		}

		if ($snapshot === null)
		{
			return $errors;
		}

		$this->applyIrreversibleTail($tail, $plan, $journal, $snapshot, $documentService, $errors);
		if (!$errors->isEmpty())
		{
			return $errors;
		}

		$this->logApplied($journal);
		$this->logChangedNothing($journal);
		$this->logSettingsNotSet();
		$this->refreshDocumentReturns((string)$document->uid, $documentService);

		return $errors;
	}

	/**
	 * The plan split where its irreversible tail begins. The tail is read off the steps themselves
	 * and not off their names: an irreversible step that is not the last of the plan stays under
	 * the lock, because the steps behind it still have to be able to fail.
	 *
	 * @see Plan the apply order, the irreversible step last of it
	 *
	 * @return array{0: Step[], 1: Step[]} the steps of the critical section and the tail behind it
	 */
	private static function splitIrreversibleTail(Plan $plan): array
	{
		$steps = $plan->getSteps();
		$tail = [];

		while ($steps !== [] && !end($steps)->reversible)
		{
			array_unshift($tail, array_pop($steps));
		}

		return [$steps, $tail];
	}

	/**
	 * Applies the steps of the critical section one by one, keeping the journal of what it has
	 * changed. The first step that fails returns the document to the snapshot taken under the
	 * lock; what the snapshot cannot take back goes to the process log.
	 *
	 * @param Step[] $steps
	 *
	 * @return ?Item\Document\State the snapshot the tail of the plan is taken back to, null where
	 *         the document was refused under the lock or a step of the section failed
	 */
	private function applyPlanToLockedDocument(
		Plan $plan,
		array $steps,
		Journal $journal,
		Item\Document $document,
		DocumentService $documentService,
		Main\ErrorCollection $errors
	): ?Item\Document\State
	{
		$documentUid = (string)$document->uid;

		// the document could have gone to signing between the plan and the lock
		$lockedDocument = $documentService->getByUid($documentUid);
		if ($lockedDocument === null)
		{
			$errors->setError(self::makeDocumentUnavailableError(self::REASON_DOCUMENT_NOT_FOUND));

			return null;
		}

		$admissionErrors = $this->checkAdmission($lockedDocument);
		if (!$admissionErrors->isEmpty())
		{
			$errors->add($admissionErrors->toArray());

			return null;
		}

		$snapshot = $documentService->captureState($lockedDocument, $plan);

		foreach ($steps as $step)
		{
			$result = $this->applyStep($step, $documentUid, $documentService);
			if ($result->isSuccess())
			{
				self::noteAppliedStep($step, $result, $journal);

				continue;
			}

			$this->noteFailedStep($step, $result, $plan, $journal);
			$this->rollback($snapshot, $journal, $documentService);
			$errors->add($result->getErrors());

			return null;
		}

		return $snapshot;
	}

	/**
	 * The steps the lock does not cover: they are the last of the plan and cannot be taken back,
	 * so no local invariant waits on them. A step of the tail that fails takes the document back
	 * under the lock taken anew. A change the signing service has accepted stays named as applied
	 * for good: the mark of it comes from the domain method, wherever the step was applied.
	 *
	 * The admission of the document is asked again before the first step of the tail: the steps of
	 * the critical section wait for the signing service up to thirty seconds each, and the tail is
	 * applied with the lock released, so a neighbour of the run has room to bring the document to
	 * `ready` or to signing in between.
	 *
	 * @param Step[] $tail
	 */
	private function applyIrreversibleTail(
		array $tail,
		Plan $plan,
		Journal $journal,
		Item\Document\State $snapshot,
		DocumentService $documentService,
		Main\ErrorCollection $errors
	): void
	{
		$firstStep = reset($tail);
		if ($firstStep === false)
		{
			return;
		}

		$admittedDocument = $documentService->getByUid($snapshot->documentUid);
		$admissionErrors = $this->checkAdmissionOfTail($admittedDocument);
		if (!$admissionErrors->isEmpty())
		{
			// the only refusal of the run made on a document it has re-read: the status the returns
			// carry is the one that refused the tail, and not the one the run was admitted with
			if ($admittedDocument !== null)
			{
				$this->fillDocumentReturns($admittedDocument);
			}

			$this->refuseTail($firstStep, $plan, $journal);
			$errors->add($admissionErrors->toArray());

			return;
		}

		foreach ($tail as $step)
		{
			$result = $this->applyStep($step, $snapshot->documentUid, $documentService);
			if ($result->isSuccess())
			{
				self::noteAppliedStep($step, $result, $journal);

				continue;
			}

			$this->noteFailedStep($step, $result, $plan, $journal);
			$this->rollbackTail($snapshot, $journal, $documentService);
			$errors->add($result->getErrors());

			return;
		}
	}

	/**
	 * Whether the document may still be configured, asked of the document as it is now. Nothing
	 * below the activity asks this of the tail: the domain method it writes with takes a document
	 * brought to `ready` or to signing as readily as a draft, and the validation behind it refuses
	 * in the final statuses alone. The check belongs to the activity for that very reason, because
	 * the interface path of the same domain method is meant to move the signing period of a
	 * document already being signed.
	 *
	 * The window is narrowed and not closed: nothing holds the document between this check and the
	 * write of the step, the way nothing holds it between the plan and the lock.
	 *
	 * The document is read by the caller: a refusal made here is the one refusal of the run that
	 * knows a newer state of the document than the returns do, and the caller fills them with it.
	 *
	 * @param ?Item\Document $document the document as it is now, null where it is no longer there
	 *
	 * @see self::applyPlanToLockedDocument() the same check, under the lock
	 * @see \Bitrix\Sign\Controllers\V1\Document::modifyDateSignUntilAction() the path the domain
	 *      method stays open for
	 */
	private function checkAdmissionOfTail(?Item\Document $document): Main\ErrorCollection
	{
		if ($document === null)
		{
			$errors = new Main\ErrorCollection();
			$errors->setError(self::makeDocumentUnavailableError(self::REASON_DOCUMENT_NOT_FOUND));

			return $errors;
		}

		return $this->checkAdmission($document);
	}

	/**
	 * The tail of a document that may no longer be configured: not one step of it is applied, and
	 * the changes the critical section has made stay in the document. Those were applied under the
	 * lock while the document was still admitted, so there is nothing wrong with them to take back,
	 * and the compensation would cost the document its member composition written anew. The process
	 * log names both halves: the setting the run stopped at and the settings that stay applied.
	 *
	 * @param Step $firstStep the step of the tail the run stopped at
	 */
	private function refuseTail(Step $firstStep, Plan $plan, Journal $journal): void
	{
		$this->logStepFailed($firstStep);
		$this->logStepsNotReached($plan, $firstStep);
		$this->logApplied($journal);
	}

	/**
	 * A step that succeeded without changing anything is journaled apart: the compensation must
	 * not take back a state nobody has moved, and the process log names such a setting neither
	 * among the applied ones nor among the ones nothing came for.
	 */
	private static function noteAppliedStep(Step $step, Main\Result $result, Journal $journal): void
	{
		if (Step::isUnchanged($result))
		{
			$journal->noteUnchanged($step);

			return;
		}

		$journal->noteApplied($step);
	}

	/**
	 * What a step that failed leaves behind: the failure itself, a change the signing service has
	 * taken anyway and the settings the run never reached.
	 */
	private function noteFailedStep(Step $step, Main\Result $result, Plan $plan, Journal $journal): void
	{
		$this->logStepFailed($step);

		if (Step::isExternalApplied($result))
		{
			$journal->noteExternallyApplied($step);
			$this->logExternallyApplied($step);
		}

		$this->logStepsNotReached($plan, $step);
	}

	/**
	 * The document is re-read right before the step: the steps write over the state the previous
	 * ones have left, and a step given a stale document would put the outdated fields back.
	 */
	private function applyStep(Step $step, string $documentUid, DocumentService $documentService): Main\Result
	{
		$document = $documentService->getByUid($documentUid);
		if ($document === null)
		{
			return (new Main\Result())->addError(
				self::makeDocumentUnavailableError(self::REASON_DOCUMENT_NOT_FOUND),
			);
		}

		return $step->apply($document);
	}

	/**
	 * Returns the document to the snapshot and reports what stays applied anyway: a failed
	 * restore and a change the signing service has taken are both worth a line of the process log.
	 */
	private function rollback(
		Item\Document\State $snapshot,
		Journal $journal,
		DocumentService $documentService
	): void
	{
		$this->restoreSnapshot($snapshot, $journal, $documentService);
		$this->logIrreversiblyApplied($journal);
	}

	/**
	 * The same compensation for a step that failed outside the lock: it writes the member
	 * composition and the fields of the document, so it goes under the lock of the document like
	 * every other write of them.
	 */
	private function rollbackTail(
		Item\Document\State $snapshot,
		Journal $journal,
		DocumentService $documentService
	): void
	{
		$this->restoreSnapshotUnderLock($snapshot, $journal, $documentService);
		$this->logIrreversiblyApplied($journal);
	}

	/**
	 * A document another writer holds is left as it is: that writer is changing the same document
	 * right now, and the snapshot written over their work would take their changes with it. The
	 * process log is told as much, so a document left with the settings of a failed run is not
	 * left silently.
	 */
	private function restoreSnapshotUnderLock(
		Item\Document\State $snapshot,
		Journal $journal,
		DocumentService $documentService
	): void
	{
		// the run has applied nothing the snapshot could take back, the lock included
		if ($journal->listApplied() === [])
		{
			return;
		}

		$lockName = self::LOCK_NAME_PREFIX . $snapshot->documentUid;
		if (!$this->acquireDocumentLock($lockName))
		{
			$this->logRestoreFailed([self::makeError(self::ERROR_DOCUMENT_BUSY)]);

			return;
		}

		try
		{
			$this->restoreSnapshot($snapshot, $journal, $documentService);
		}
		finally
		{
			$this->releaseDocumentLock($lockName);
		}
	}

	/**
	 * Takes the document back to the snapshot: a restore that failed is an incident of its own,
	 * and a document nothing was applied to has nothing to be reported as reverted.
	 */
	private function restoreSnapshot(
		Item\Document\State $snapshot,
		Journal $journal,
		DocumentService $documentService
	): void
	{
		$restoreResult = $documentService->restoreState($snapshot, $journal);
		if (!$restoreResult->isSuccess())
		{
			$this->logRestoreFailed($restoreResult->getErrors());

			return;
		}

		if ($journal->listApplied() !== [])
		{
			$this->log(Loc::getMessage('SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_CONFIGURE_LOG_REVERTED') ?? '');
		}
	}

	private function logApplied(Journal $journal): void
	{
		$steps = $journal->listApplied();
		if ($steps === [])
		{
			return;
		}

		$this->log(
			Loc::getMessage(
				'SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_CONFIGURE_LOG_APPLIED',
				['#SETTINGS#' => self::listStepTitles($steps)],
			) ?? '',
		);
		$this->logProviderMovedByTheCompany($steps);
	}

	/**
	 * The provider the applied step of the company moved the document to. The settings named no
	 * provider, so the step wrote the code of the registration beside the company, and the line of
	 * the applied settings names the company alone: whoever reads it would not learn that the
	 * document is signed by the scheme of another provider now.
	 *
	 * @see self::makeRegistrationWriter() writes the company and the provider as one pair
	 *
	 * @param Step[] $appliedSteps the steps that changed the document
	 */
	private function logProviderMovedByTheCompany(array $appliedSteps): void
	{
		if ($this->providerCodeMovedByTheCompany === null)
		{
			return;
		}

		$appliedNames = array_map(static fn(Step $step): string => $step->name, $appliedSteps);
		if (!in_array(Step::COMPANY, $appliedNames, true))
		{
			return;
		}

		$this->log(
			Loc::getMessage(
				'SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_CONFIGURE_LOG_PROVIDER_MOVED_BY_COMPANY',
				['#PROVIDER#' => self::nameProvider($this->providerCodeMovedByTheCompany)],
			) ?? '',
		);
	}

	/**
	 * The provider named the way the settings form offers it: two providers of the portal carry one
	 * and the same name, so the code travels beside it. A code the module has no name for is named
	 * by itself.
	 *
	 * @see \Bitrix\Sign\Type\ProviderCode::getProviderName() the names of the providers
	 */
	private static function nameProvider(string $providerCode): string
	{
		$name = ProviderCode::getProviderName(ProviderCode::toRepresentativeString($providerCode));

		return $name === '' ? $providerCode : $name . ' (' . $providerCode . ')';
	}

	/**
	 * The settings left out of the plan: nothing came for them, so nothing changed.
	 */
	private function logSettingsNotSet(): void
	{
		$titles = [];
		foreach (self::SETTINGS as $property)
		{
			if (!$this->isSettingFilled($property))
			{
				$titles[] = self::getSettingTitle($property);
			}
		}

		if ($titles === [])
		{
			return;
		}

		$this->log(
			Loc::getMessage(
				'SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_CONFIGURE_LOG_SETTINGS_NOT_SET',
				['#SETTINGS#' => implode(', ', $titles)],
			) ?? '',
		);
	}

	/**
	 * Settings the run was given and found already standing in the document: their step wrote
	 * nothing, so they belong neither among the applied settings nor among the ones nothing came
	 * for.
	 */
	private function logChangedNothing(Journal $journal): void
	{
		$steps = $journal->listUnchanged();
		if ($steps === [])
		{
			return;
		}

		$this->log(
			Loc::getMessage(
				'SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_CONFIGURE_LOG_NOTHING_CHANGED',
				['#SETTINGS#' => self::listStepTitles($steps)],
			) ?? '',
		);
	}

	/**
	 * Employees the run does not take into the composition because they no longer work in the
	 * company. The reason keeps a line of its own: an employee who refused the electronic signing
	 * is left out for a reason the reader answers differently, and one line naming both together
	 * would tell neither.
	 *
	 * These employees are named: the portal shows an inactive employee to whoever may read the
	 * document anyway.
	 *
	 * @param list<int> $userIds
	 */
	private function logFiredLeftOut(array $userIds): void
	{
		if ($userIds === [])
		{
			return;
		}

		$this->log(
			Loc::getMessage(
				'SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_CONFIGURE_LOG_FIRED_LEFT_OUT',
				['#EMPLOYEES#' => self::listEmployeeNames($userIds)],
			) ?? '',
		);
	}

	/**
	 * The employees who refused the electronic signing are counted in the process log and named in
	 * the log of the module: the group of them is closed by a permission of its own, while the
	 * process log is open to whoever may read the document.
	 *
	 * The line is written all the same: a signer the run does not take may not be dropped without a
	 * word.
	 *
	 * @see \Bitrix\Sign\Service\Sign\SignersList\AccessService::resolve() the group is read under
	 *      ACTION_B2E_SIGNERS_LIST_REFUSED_EDIT, while the process log is gated by the read of the
	 *      document alone
	 *
	 * @param list<int> $userIds
	 */
	private function logRefusedLeftOut(Item\Document $document, array $userIds): void
	{
		if ($userIds === [])
		{
			return;
		}

		$count = count($userIds);
		$this->log(
			Loc::getMessagePlural(
				'SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_CONFIGURE_LOG_REFUSED_LEFT_OUT',
				$count,
				['#COUNT#' => $count],
			) ?? '',
		);

		Container::instance()->getLogger('Service')->info(
			'Employees who refused the electronic signing are left out of {documentUid}: {userIds}',
			[
				'documentUid' => $document->uid,
				'userIds' => implode(', ', $userIds),
			],
		);
	}

	/**
	 * Employees named the way the portal names them; an employee the portal knows no name of is
	 * named by the identifier the composition carried.
	 *
	 * The name is escaped here: the process log is printed as raw HTML, and a name is filled in by
	 * whoever fills in the profile of an employee.
	 *
	 * @see \Bitrix\Sign\Service\UserService::getUserName() answers with the name as it is
	 *
	 * @param list<int> $userIds
	 */
	private static function listEmployeeNames(array $userIds): string
	{
		$userService = Container::instance()->getUserService();
		$users = $userService->listByIds($userIds);

		$names = [];
		foreach ($userIds as $userId)
		{
			$user = $users->getByIdMap($userId);
			$name = $user === null ? '' : trim($userService->getUserName($user));
			$names[] = $name === '' ? (string)$userId : htmlspecialcharsbx($name);
		}

		return implode(', ', $names);
	}

	private function logNothingToApply(): void
	{
		$this->log(Loc::getMessage('SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_CONFIGURE_LOG_NOTHING_TO_APPLY') ?? '');
	}

	private function logStepFailed(Step $step): void
	{
		$this->logError(
			Loc::getMessage(
				'SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_CONFIGURE_LOG_STEP_FAILED',
				['#SETTING#' => self::getStepTitle($step)],
			) ?? '',
		);
	}

	/**
	 * The plan stops at the first step that fails, and the settings behind it are left for the
	 * next run of the activity.
	 */
	private function logStepsNotReached(Plan $plan, Step $failedStep): void
	{
		$steps = $plan->getSteps();
		$position = array_search($failedStep, $steps, true);
		$notReached = $position === false ? [] : array_slice($steps, $position + 1);
		if ($notReached === [])
		{
			return;
		}

		$this->log(
			Loc::getMessage(
				'SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_CONFIGURE_LOG_STEPS_NOT_REACHED',
				['#SETTINGS#' => self::listStepTitles($notReached)],
			) ?? '',
		);
	}

	private function logExternallyApplied(Step $step): void
	{
		$this->logError(
			Loc::getMessage(
				'SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_CONFIGURE_LOG_EXTERNAL_APPLIED',
				['#SETTING#' => self::getStepTitle($step)],
			) ?? '',
		);
	}

	/**
	 * The reasons the restore itself failed go to the process log: the errors returned to the
	 * process tell why the run failed, and the incident of the compensation would otherwise be
	 * left with nothing to look at.
	 *
	 * A reason is escaped the way the names of the employees are: the process log is printed as raw
	 * HTML, and the reasons reaching here are not only the phrases of the module. The domain
	 * translates its composition refusals only; a document gone missing, the validations of the
	 * composition and the refusals of the smart process card reach here as the technical strings
	 * they are.
	 *
	 * @param Main\Error[] $errors
	 */
	private function logRestoreFailed(array $errors): void
	{
		$reasons = [];
		foreach ($errors as $error)
		{
			$reason = trim((string)$error->getMessage());
			if ($reason !== '')
			{
				$reasons[$reason] = htmlspecialcharsbx($reason);
			}
		}

		if ($reasons === [])
		{
			$this->logError(
				Loc::getMessage('SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_CONFIGURE_LOG_RESTORE_FAILED') ?? '',
			);

			return;
		}

		$this->logError(
			Loc::getMessage(
				'SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_CONFIGURE_LOG_RESTORE_FAILED_WITH_REASON',
				['#ERRORS#' => implode('; ', $reasons)],
			) ?? '',
		);
	}

	private function logIrreversiblyApplied(Journal $journal): void
	{
		$steps = $journal->listIrreversiblyApplied();
		if ($steps === [])
		{
			return;
		}

		$this->logError(
			Loc::getMessage(
				'SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_CONFIGURE_LOG_APPLIED_IRREVERSIBLY',
				['#SETTINGS#' => self::listStepTitles($steps)],
			) ?? '',
		);
	}

	/**
	 * @param Step[] $steps
	 */
	private static function listStepTitles(array $steps): string
	{
		return implode(', ', array_map(static fn(Step $step): string => self::getStepTitle($step), $steps));
	}

	protected function acquireDocumentLock(string $lockName): bool
	{
		// zero timeout: a document already being changed is refused right away
		return Main\Application::getConnection()->lock($lockName, 0);
	}

	protected function releaseDocumentLock(string $lockName): bool
	{
		return Main\Application::getConnection()->unlock($lockName);
	}

	private function refreshDocumentReturns(string $documentUid, DocumentService $documentService): void
	{
		$document = $documentService->getByUid($documentUid);
		if ($document !== null)
		{
			$this->fillDocumentReturns($document);
		}
	}

	/**
	 * The representative of the company, and the member composition along with them where the run
	 * writes none of its own: the communications of the company member are filled by the domain
	 * path that applies the composition, so a document whose composition is left alone keeps
	 * inviting the previous representative.
	 *
	 * A document laying its company side out as an HR role is refused instead: the representative
	 * of such a document is the role itself, while the setting of the activity names an employee.
	 *
	 * @see \Bitrix\Sign\Service\Sign\DocumentService::modifyRepresentativeId() the two rules
	 * @see \Bitrix\Sign\Controllers\V1\Document\Member::setupB2ePartiesAction() writes the same pair
	 *
	 * @param bool $appliesComposition whether the step is the one to apply the composition
	 */
	private function buildRepresentativeStep(
		Item\Document $document,
		DocumentService $documentService,
		Main\ErrorCollection $errors,
		bool $appliesComposition
	): ?Step
	{
		if (!$this->isSettingFilled(self::PARAM_REPRESENTATIVE))
		{
			return null;
		}

		$representative = $this->{self::PARAM_REPRESENTATIVE};
		if (CBPHelper::isEmptyValue($representative))
		{
			$errors->setError(self::makeEmptySettingError(self::PARAM_REPRESENTATIVE));

			return null;
		}

		$representativeId = (int)CBPHelper::extractFirstUser($representative, $this->getDocumentId());
		if ($representativeId < 1)
		{
			$errors->setError(self::makeError(self::ERROR_INVALID_REPRESENTATIVE));

			return null;
		}

		$companyMember = self::findCompanyMember($document);
		if ($document->isTemplated() && $companyMember?->entityType === EntityType::ROLE)
		{
			$errors->setError(self::makeError(self::ERROR_REPRESENTATIVE_OF_ROLE));

			return null;
		}

		// a document with no company member of its own has no communications to fill either
		$appliesComposition = $appliesComposition && $companyMember !== null;

		// read here and only by the step that writes a composition
		// @see self::listRefusedSignerIds() why the group is read while the plan is built
		$refusedSignerIds = $appliesComposition ? self::listRefusedSignerIds() : [];

		return Step::createReversible(
			Step::REPRESENTATIVE,
			fn(Item\Document $target): Main\Result => $this->applyRepresentative(
				$documentService,
				$target,
				$representativeId,
				$appliesComposition,
				$refusedSignerIds,
			),
		);
	}

	/**
	 * The field of the document, and the composition the company member takes the communications
	 * of the representative from.
	 *
	 * A step that moved neither of the two is marked as a step that changed nothing: the
	 * compensation restores the composition of every step it knows as a writer of one, and that
	 * restore is a wipe and a create anew, the very cost the unchanged mark is there to spare.
	 *
	 * @param list<int> $refusedSignerIds employees who refused the electronic signing, read while
	 *        the plan was built
	 *
	 * @see Step::COMPOSITION_WRITERS this step among them
	 * @see self::applyMembers() the composition it marks unchanged itself
	 */
	private function applyRepresentative(
		DocumentService $documentService,
		Item\Document $document,
		int $representativeId,
		bool $appliesComposition,
		array $refusedSignerIds
	): Main\Result
	{
		$isFieldUnchanged = (int)$document->representativeId === $representativeId;

		$result = $documentService->modifyRepresentativeId(
			(string)$document->uid,
			$representativeId,
			EntityType::COMPANY,
		);
		if (!$result->isSuccess())
		{
			return $result;
		}

		if (!$appliesComposition)
		{
			return $isFieldUnchanged ? Step::markUnchanged(new Main\Result()) : $result;
		}

		$compositionResult = $this->applyMembers(
			$document,
			signerIds: [],
			replaceSigners: false,
			reminderType: null,
			refusedSignerIds: $refusedSignerIds,
			representativeId: $representativeId,
		);
		if (!$compositionResult->isSuccess())
		{
			return $compositionResult;
		}

		return $isFieldUnchanged && Step::isUnchanged($compositionResult)
			? Step::markUnchanged(new Main\Result())
			: new Main\Result()
		;
	}

	/**
	 * The member the company side of the document is laid out as: a company of the portal or an
	 * HR role of its structure.
	 */
	private static function findCompanyMember(Item\Document $document): ?Item\Member
	{
		return Container::instance()
			->getMemberService()
			->getAssignee($document)
		;
	}

	/**
	 * The company and the provider are resolved together: the document stores the uid of a
	 * company registration, and a given code must be one the company is registered with.
	 * Whichever of the two the settings name, the step of that setting writes both.
	 *
	 * @return Step[]
	 */
	private function buildCompanyAndProviderSteps(
		Item\Document $document,
		DocumentService $documentService,
		Main\ErrorCollection $errors
	): array
	{
		$this->providerCodeMovedByTheCompany = null;
		$companyId = $this->resolveCompanyId($errors);
		$providerCode = $this->resolveProviderCode($errors);
		if ($companyId === null && $providerCode === null)
		{
			return [];
		}

		$companyToResolve = $companyId ?? (int)$document->companyEntityId;
		$providers = [];
		if ($companyToResolve > 0)
		{
			$providers = $this->loadCompanyProviders($companyToResolve, $document, $errors);
			if ($providers === null)
			{
				return [];
			}
		}

		$registration = self::findRegistration($providers, $providerCode);
		if ($companyToResolve > 0 && $registration === null)
		{
			$errorCode = $providerCode === null
				? self::ERROR_COMPANY_WITHOUT_PROVIDER
				: self::ERROR_PROVIDER_NOT_IN_COMPANY
			;
			$errors->setError(self::makeError($errorCode));

			return [];
		}

		// a document with no company of its own has no registration to be bound to, and with no
		// code in the settings the registration is the one telling which provider signs it
		$registrationUid = $registration === null ? null : (string)$registration['uid'];
		$codeToApply = $providerCode ?? (string)$registration['code'];

		// the settings name no provider, so the code comes from the registration and not from them:
		// a run that changes the scheme the document is signed by must not do it silently
		if ($providerCode === null && $codeToApply !== (string)$document->providerCode)
		{
			$this->providerCodeMovedByTheCompany = $codeToApply;
		}

		$steps = [];

		if ($companyId !== null)
		{
			$steps[] = Step::createReversible(
				Step::COMPANY,
				self::makeRegistrationWriter(
					$documentService,
					$registrationUid,
					$codeToApply,
					$companyId,
					$companyToResolve,
				),
			);
		}

		if ($providerCode !== null)
		{
			$steps[] = Step::createReversible(
				Step::PROVIDER,
				self::makeRegistrationWriter(
					$documentService,
					$registrationUid,
					$codeToApply,
					null,
					$companyToResolve,
				),
			);
		}

		return $steps;
	}

	/**
	 * Writes the registration of the company and the code of its provider as one pair: the
	 * document keeps the uid of a registration with a provider, and the code beside it decides
	 * the scheme the document is signed by. A pair left half written binds the document to the
	 * registration of one provider and has it signed by the scheme of another.
	 *
	 * @see \Bitrix\Sign\Controllers\V1\Document::modifyCompanyAction() writes the same pair
	 *
	 * @param int|null $companyId null keeps the company the document has at the moment of the write
	 * @param int $resolvedForCompanyId the company the pair was resolved for, zero where the
	 *        document had none: the write belongs to that company and to no other
	 *
	 * @return \Closure(Item\Document): Main\Result
	 */
	private static function makeRegistrationWriter(
		DocumentService $documentService,
		?string $registrationUid,
		string $providerCode,
		?int $companyId,
		int $resolvedForCompanyId
	): \Closure
	{
		return static function (Item\Document $target) use (
			$documentService,
			$registrationUid,
			$providerCode,
			$companyId,
			$resolvedForCompanyId
		): Main\Result
		{
			$companyEntityId = $companyId ?? (int)$target->companyEntityId;

			// asked again under the lock: the registrations behind the pair were read for the
			// company the document carried when the plan was built, and a neighbour of the run
			// could have moved the document to another company since. The registrations of that
			// company are unknown here, and asking the signing service for them would bring the
			// wait for its answer back under the lock.
			if ($companyEntityId !== $resolvedForCompanyId)
			{
				return (new Main\Result())->addError(self::makeError(self::ERROR_COMPANY_CHANGED));
			}

			if ($registrationUid !== null && $companyEntityId > 0)
			{
				$companyResult = $documentService->modifyCompany(
					(string)$target->uid,
					$registrationUid,
					$companyEntityId,
				);
				if (!$companyResult->isSuccess())
				{
					return $companyResult;
				}

				// only the step naming the company moves it; the step of the provider alone passes
				// null and leaves the company, its member and its card exactly where they were
				if ($companyId !== null)
				{
					$mirrorResult = self::moveCompanyMember($target, $companyId);
					if (!$mirrorResult->isSuccess())
					{
						return $mirrorResult;
					}
				}
			}

			return $documentService->modifyProviderCodeForSingleDocument($target, $providerCode);
		};
	}

	/**
	 * Points the company member and the card of the smart process at the company the document has
	 * just been moved to. The domain path of the wizard does it while it writes the composition,
	 * and a run naming the company alone plans no member step, so without this the member would
	 * keep the company the document no longer has and the card would mirror that same stale
	 * company.
	 *
	 * The write belongs to the domain and is asked of it: it is a write of the composition, and it
	 * has to take the lock and the state check every other writer of the composition takes.
	 *
	 * @see \Bitrix\Sign\Service\Sign\MemberService::moveB2eCompanyMember() the write itself
	 * @see self::buildComposition() repoints the same member where a member step is planned
	 */
	private static function moveCompanyMember(Item\Document $document, int $companyId): Main\Result
	{
		return Container::instance()
			->getMemberService()
			->moveB2eCompanyMember((string)$document->uid, $companyId)
		;
	}

	private function buildDocumentCodeStep(DocumentService $documentService, Main\ErrorCollection $errors): ?Step
	{
		$documentCode = $this->resolveSettingString(self::PARAM_DOCUMENT_CODE, $errors);
		if ($documentCode === null)
		{
			return null;
		}

		return Step::createReversible(
			Step::DOCUMENT_CODE,
			static fn(Item\Document $target): Main\Result => $documentService->modifyRegionDocumentType(
				(string)$target->uid,
				$documentCode,
			),
		);
	}

	private function buildExternalIdStep(DocumentService $documentService, Main\ErrorCollection $errors): ?Step
	{
		$externalId = $this->resolveSettingString(self::PARAM_EXTERNAL_ID, $errors);
		if ($externalId === null)
		{
			return null;
		}

		return Step::createReversible(
			Step::EXTERNAL_ID,
			static fn(Item\Document $target): Main\Result => $documentService->modifyExternalId(
				documentUid: (string)$target->uid,
				externalId: $externalId,
				sourceType: ExternalIdSourceType::MANUAL,
				hcmLinkSettingId: null,
			),
		);
	}

	private function buildDateSignUntilStep(
		Item\Document $document,
		DocumentService $documentService,
		Main\ErrorCollection $errors
	): ?Step
	{
		if (!$this->isSettingFilled(self::PARAM_DATE_SIGN_UNTIL))
		{
			return null;
		}

		$value = $this->{self::PARAM_DATE_SIGN_UNTIL};
		if (CBPHelper::isEmptyValue($value))
		{
			$errors->setError(self::makeEmptySettingError(self::PARAM_DATE_SIGN_UNTIL));

			return null;
		}

		$timestamp = (int)CBPHelper::makeTimestamp($value);
		if ($timestamp < 1)
		{
			$errors->setError(self::makeError(self::ERROR_INVALID_DATE_SIGN_UNTIL));

			return null;
		}

		$dateSignUntil = Main\Type\DateTime::createFromTimestamp($timestamp);

		// the validation reads the date from the document, so it is checked on a copy
		$documentWithDate = clone $document;
		$documentWithDate->dateSignUntil = $dateSignUntil;
		$validationResult = (new ValidateDateSignUntil($documentWithDate))->launch();
		if (!$validationResult->isSuccess())
		{
			$errors->add($validationResult->getErrors());

			return null;
		}

		// the domain marks a change the signing service has taken, whatever the local write failed with
		return Step::createIrreversible(
			Step::DATE_SIGN_UNTIL,
			static fn(Item\Document $target): Main\Result => $documentService->modifyDateSignUntil(
				(string)$target->uid,
				$dateSignUntil,
			),
		);
	}

	/**
	 * The signer list and the reminder type are one member composition for the domain, so they
	 * make one step. A reminder has nobody to reach when neither this run nor the document
	 * brings a single signer, and a full replacement of the list has nothing to put in its
	 * place when the settings name no signer: both are refused before anything is applied.
	 */
	private function buildMembersStep(Item\Document $document, Main\ErrorCollection $errors): ?Step
	{
		$hasSigners = $this->isSettingFilled(self::PARAM_SIGNERS);
		$hasReminder = $this->isSettingFilled(self::PARAM_REMINDER_TYPE);
		$replaceSigners = $this->resolveReplaceSigners($errors);

		if ($replaceSigners && !$hasSigners)
		{
			$errors->setError(self::makeError(self::ERROR_REPLACE_WITHOUT_SIGNERS));

			return null;
		}

		if (!$hasSigners && !$hasReminder)
		{
			return null;
		}

		$signerIds = $hasSigners ? $this->resolveSignerIds($errors) : [];
		$reminderType = $hasReminder ? $this->resolveReminderType($errors) : null;

		if ($hasReminder && !$hasSigners && !self::documentHasSigners($document))
		{
			$errors->setError(self::makeError(self::ERROR_REMINDER_WITHOUT_SIGNERS));
		}

		if (!$errors->isEmpty())
		{
			return null;
		}

		$refusedSignerIds = self::listRefusedSignerIds();

		return Step::createReversible(
			Step::MEMBERS,
			fn(Item\Document $target): Main\Result => $this->applyMembers(
				$target,
				$signerIds,
				$replaceSigners,
				$reminderType,
				$refusedSignerIds,
			),
		);
	}

	/**
	 * @return int[] employees the signer setting names, with its departments and groups expanded
	 */
	private function resolveSignerIds(Main\ErrorCollection $errors): array
	{
		$value = $this->{self::PARAM_SIGNERS};
		if (CBPHelper::isEmptyValue($value))
		{
			$errors->setError(self::makeEmptySettingError(self::PARAM_SIGNERS));

			return [];
		}

		$userIds = [];
		foreach ((array)CBPHelper::extractUsers($value, $this->getDocumentId()) as $userId)
		{
			$userId = (int)$userId;
			if ($userId > 0)
			{
				$userIds[] = $userId;
			}
		}

		if ($userIds === [])
		{
			$errors->setError(self::makeError(self::ERROR_SIGNERS_NOT_RESOLVED));

			return [];
		}

		// the whole selection is expanded before its size is known, and the expansion is paid in
		// full even for a refusal: a department code brings its employees one by one, and the
		// platform helper keeps its list unique by scanning it for every employee it adds. Asking
		// the domain for the size beforehand would expand the same sources a second time, because
		// the composition is built from the identifiers of this very expansion, and the codes the
		// field accepts include portal groups and roles of the process no entity of sign stands for
		// @see \Bitrix\Sign\Service\Sign\MemberService::getUniqueSignersCount() counts, ids apart
		$limitError = self::findSignersLimitError(count($userIds));
		if ($limitError !== null)
		{
			$errors->setError($limitError);

			return [];
		}

		return $userIds;
	}

	private function resolveReminderType(Main\ErrorCollection $errors): ?ReminderType
	{
		$code = $this->resolveSettingString(self::PARAM_REMINDER_TYPE, $errors);
		if ($code === null)
		{
			return null;
		}

		$reminderType = ReminderType::tryFrom($code);
		if ($reminderType === null)
		{
			$errors->setError(self::makeError(self::ERROR_INVALID_REMINDER));
		}

		return $reminderType;
	}

	/**
	 * The composition goes to the domain path of the signing wizard whole: it checks the
	 * composition, wipes the previous members, creates the given ones and sets the document
	 * company and its parties by itself. A composition the document already carries is left alone:
	 * the write would cost all of that and change nothing.
	 *
	 * @see self::isSameComposition() what makes a composition the one the document carries
	 *
	 * @param int[] $signerIds
	 * @param list<int> $refusedSignerIds employees who refused the electronic signing, read while
	 *        the plan was built
	 * @param ?int $representativeId the representative the company member takes its communications
	 *        from; null leaves the one the document carries
	 */
	private function applyMembers(
		Item\Document $document,
		array $signerIds,
		bool $replaceSigners,
		?ReminderType $reminderType,
		array $refusedSignerIds,
		?int $representativeId = null
	): Main\Result
	{
		$memberService = Container::instance()->getMemberService();
		$documentMembers = $memberService->listByDocumentId((int)$document->id);

		// a document of no template lays its company side out as a company of the portal, never as
		// an HR role: a role left in the composition would be written into the company of the card
		// @see \Bitrix\Sign\Controllers\V1\Document\Member::setupB2ePartiesAction() drops the same
		$savedMembers = $document->isTemplated()
			? $documentMembers
			: $documentMembers->filterExcludeEntityTypes(EntityType::ROLE)
		;

		$collected = self::collectSigners($savedMembers, $signerIds, $replaceSigners);

		// the reminder of a run that brings no signer of its own, asked again under the lock: the
		// plan asked the document about its signers before the lock, and a neighbour of the run
		// could have taken the last one away in between. A composition without a single signer is
		// taken by the domain path as it is, and a reminder in it would reach nobody.
		if ($collected === [] && $reminderType !== null && $signerIds === [])
		{
			return (new Main\Result())->addError(self::makeError(self::ERROR_REMINDER_WITHOUT_SIGNERS));
		}

		$signers = $this->keepEligibleSigners($document, $collected, $refusedSignerIds);
		if ($signers === [] && $collected !== [])
		{
			return (new Main\Result())->addError(self::makeError(self::ERROR_NO_ELIGIBLE_SIGNERS));
		}

		// the settings are bounded before the lock by the list they name; the composition is
		// bounded here by what it would leave in the document, and a run adding its names to the
		// signers of the document is the one the settings alone cannot count
		$limitError = $signerIds === [] ? null : self::findSignersLimitError(self::countEmployees($signers));
		if ($limitError !== null)
		{
			return (new Main\Result())->addError($limitError);
		}

		$composition = self::buildComposition($document, $savedMembers, $signers, $reminderType);
		if ($composition === null)
		{
			return (new Main\Result())->addError(self::makeError(self::ERROR_MEMBERS_WITHOUT_COMPANY));
		}

		$partyPlanResult = Container::instance()
			->getCompanyInitiatedPartyPlanService()
			->plan($document, $composition)
		;
		if (!$partyPlanResult->isSuccess())
		{
			return $partyPlanResult;
		}

		$composition = $partyPlanResult->members;

		$representativeToApply = $representativeId ?? (int)$document->representativeId;
		if (
			$representativeToApply === (int)$document->representativeId
			&& self::isSameComposition($composition, $documentMembers)
		)
		{
			return Step::markUnchanged(new Main\Result());
		}

		return $memberService->setupB2eMembers(
			documentUid: (string)$document->uid,
			memberCollection: $composition,
			representativeId: $representativeToApply,
			skipPermissionCheck: true,
		);
	}

	/**
	 * Whether the composition is the one the document already carries. The domain path knows no
	 * partial write: it wipes the members and creates the given ones anew, so a composition
	 * applied again costs new member rows, communications reset to idle, relations with the
	 * structure nodes built from scratch and the company of the card written once more, all of it
	 * for no change at all.
	 *
	 * @see \Bitrix\Sign\Service\Sign\MemberService::setupB2eMembers() what the write does
	 * @see \Bitrix\Sign\Item\MemberCollection::describeForComparison() what the compared
	 *      composition is made of
	 */
	private static function isSameComposition(
		Item\MemberCollection $composition,
		Item\MemberCollection $documentMembers
	): bool
	{
		return $composition->describeForComparison() === $documentMembers->describeForComparison();
	}

	/**
	 * @param Item\Member[] $signers
	 *
	 * @return int the employees the composition names one by one; the employees behind a structure
	 *         node the document carries are counted by the domain path the composition is written
	 *         with
	 */
	private static function countEmployees(array $signers): int
	{
		$employees = 0;
		foreach ($signers as $signer)
		{
			if ($signer->entityType === EntityType::USER)
			{
				$employees++;
			}
		}

		return $employees;
	}

	/**
	 * Signers the document may be given: an employee who no longer works in the company and an
	 * employee who refused the electronic signing are left out, the way the path of the signing
	 * wizard leaves them out before it applies a composition. Both reasons reach the process log:
	 * a signer the run does not take may not be dropped without a word.
	 *
	 * @see \Bitrix\Sign\Service\Sign\MemberService::filterFiredSigners() the fired employees
	 * @see \Bitrix\Sign\Operation\Member\GetMembersFromUserPartyEntities the refused ones, of the
	 *      signer role and of an employee entity only
	 * @see self::logRefusedLeftOut() why the refused ones are counted and not named
	 *
	 * @param Item\Member[] $signers
	 * @param list<int> $refusedSignerIds employees who refused the electronic signing, read while
	 *        the plan was built
	 *
	 * @return Item\Member[]
	 */
	private function keepEligibleSigners(Item\Document $document, array $signers, array $refusedSignerIds): array
	{
		$employees = new Item\MemberCollection();
		foreach ($signers as $signer)
		{
			if ($signer->entityType === EntityType::USER)
			{
				$employees->add($signer);
			}
		}

		if ($employees->isEmpty())
		{
			return $signers;
		}

		$employeeIds = self::listEntityIds($employees);
		$workingIds = self::listEntityIds(
			Container::instance()->getMemberService()->filterFiredSigners($employees),
		);

		$firedIds = array_values(array_diff($employeeIds, $workingIds));
		$refusedIds = array_values(array_intersect($workingIds, $refusedSignerIds));

		$this->logFiredLeftOut($firedIds);
		$this->logRefusedLeftOut($document, $refusedIds);

		$leftOut = array_flip([...$firedIds, ...$refusedIds]);

		return array_values(array_filter(
			$signers,
			static fn(Item\Member $signer): bool =>
				$signer->entityType !== EntityType::USER || !isset($leftOut[(int)$signer->entityId])
			,
		));
	}

	/**
	 * Employees who refused the electronic signing, as the group of the portal keeps them. The
	 * group is read while the plan is built: it belongs to the portal and not to the document the
	 * lock guards, so the critical section is not the place to wait for it. Only the identifiers
	 * are taken: the filter of the composition compares numbers, and an item per employee of the
	 * group would be built for nothing.
	 *
	 * @see \Bitrix\Sign\Service\SignersListService::listRejectedSignerIds() the read itself
	 *
	 * @return list<int>
	 */
	private static function listRefusedSignerIds(): array
	{
		return Container::instance()->getSignersListService()->listRejectedSignerIds();
	}

	/**
	 * @return list<int>
	 */
	private static function listEntityIds(Item\MemberCollection $members): array
	{
		$ids = [];
		foreach ($members as $member)
		{
			$ids[] = (int)$member->entityId;
		}

		return $ids;
	}

	/**
	 * Null means the document has neither a company member nor a company to make one of, and
	 * the domain path refuses a composition without it.
	 *
	 * @param Item\Member[] $signers
	 */
	private static function buildComposition(
		Item\Document $document,
		Item\MemberCollection $savedMembers,
		array $signers,
		?ReminderType $reminderType
	): ?Item\MemberCollection
	{
		// every role but the signer keeps a party of its own: the domain allows several members
		// in the last party only
		$composition = $savedMembers->filterExcludeRoles(Role::SIGNER)->copyForWrite();
		$companyMember = $composition->findFirstByRole(Role::ASSIGNEE);
		$companyId = (int)$document->companyEntityId;

		if ($companyMember === null)
		{
			if ($companyId < 1)
			{
				return null;
			}

			$composition->add(new Item\Member(
				party: self::nextParty($composition),
				entityType: EntityType::COMPANY,
				entityId: $companyId,
				role: Role::ASSIGNEE,
			));
		}
		elseif (
			$companyMember->entityType === EntityType::COMPANY
			&& $companyId > 0
			&& $companyMember->entityId !== $companyId
		)
		{
			// the company step goes before this one, and the requisite preset belongs to the
			// company the member points at
			$companyMember->entityId = $companyId;
			$companyMember->presetId = 0;
		}

		$signerParty = self::nextParty($composition);
		foreach ($signers as $signer)
		{
			$signer->party = $signerParty;
			if ($reminderType !== null)
			{
				$signer->reminder->type = $reminderType;
			}

			$composition->add($signer);
		}

		return $composition;
	}

	/**
	 * The saved signers stay unless the settings replace the list: the domain knows the whole
	 * composition only, so keeping a signer means passing it again.
	 *
	 * @param int[] $signerIds
	 *
	 * @return Item\Member[]
	 */
	private static function collectSigners(
		Item\MemberCollection $savedMembers,
		array $signerIds,
		bool $replaceSigners
	): array
	{
		$signers = $replaceSigners
			? []
			: $savedMembers->filterByRole(Role::SIGNER)->copyForWrite()->toArray()
		;

		$takenUserIds = [];
		foreach ($signers as $signer)
		{
			if ($signer->entityType === EntityType::USER)
			{
				$takenUserIds[$signer->entityId] = true;
			}
		}

		foreach ($signerIds as $userId)
		{
			if (isset($takenUserIds[$userId]))
			{
				continue;
			}

			$takenUserIds[$userId] = true;
			$signers[] = new Item\Member(
				entityType: EntityType::USER,
				entityId: $userId,
				role: Role::SIGNER,
			);
		}

		return $signers;
	}

	/**
	 * The party the next role takes: the signers share the last party of the document.
	 */
	private static function nextParty(Item\MemberCollection $members): int
	{
		$party = 0;
		foreach ($members as $member)
		{
			$party = max($party, (int)$member->party);
		}

		return $party + 1;
	}

	/**
	 * Whether the document carries a signer at all: the question is answered by one member, and the
	 * composition of a document holds up to a hundred of them.
	 *
	 * @see \Bitrix\Sign\Service\Sign\MemberService::getSigner() the selection bounded to one row
	 */
	private static function documentHasSigners(Item\Document $document): bool
	{
		return Container::instance()->getMemberService()->getSigner($document) !== null;
	}

	private function resolveCompanyId(Main\ErrorCollection $errors): ?int
	{
		$value = $this->resolveSettingString(self::PARAM_COMPANY, $errors);
		if ($value === null)
		{
			return null;
		}

		$companyId = (int)$value;
		if ($companyId < 1)
		{
			$errors->setError(self::makeError(self::ERROR_INVALID_COMPANY));

			return null;
		}

		return $companyId;
	}

	private function resolveProviderCode(Main\ErrorCollection $errors): ?string
	{
		$value = $this->resolveSettingString(self::PARAM_PROVIDER_CODE, $errors);
		if ($value === null)
		{
			return null;
		}

		$providerCode = ProviderCode::createFromProviderLikeString($value);
		if ($providerCode === null)
		{
			$errors->setError(self::makeError(self::ERROR_INVALID_PROVIDER));

			return null;
		}

		// the settings form offers the signing providers alone, while an expression of the process
		// brings any string the process carries
		if (ProviderCode::isTechnical($providerCode))
		{
			$errors->setError(self::makeError(self::ERROR_TECHNICAL_PROVIDER));

			return null;
		}

		return $providerCode;
	}

	/**
	 * Registrations of the company in the signing service: the document keeps the uid of one of
	 * them as its company. Null means the company itself could not be resolved.
	 *
	 * The service is asked once per hit, whatever it answers: a chain of links and a batch of cards
	 * ask about one and the same company, and every question asked anew costs a request to crm for
	 * the company, up to four more for its requisites and the thirty second wait for the service.
	 *
	 * @return array<array{uid: string, code: string, timestamp: int}>|null
	 */
	private function loadCompanyProviders(
		int $companyId,
		Item\Document $document,
		Main\ErrorCollection $errors
	): ?array
	{
		// the signing order changes the registrations the service answers with
		$signerSignsFirst = $document->initiatedByType->isEmployee();
		$askedFor = $companyId . '|' . ($signerSignsFirst ? '1' : '0');
		$answered = self::$companyProviders[$askedFor] ?? null;
		if ($answered !== null)
		{
			$errors->add($answered['errors']);

			return $answered['errors'] === [] ? $answered['providers'] : null;
		}

		$container = Container::instance();

		$company = null;
		$myCompanies = $container->getCrmMyCompanyService()->listWithTaxIds(
			inIds: [$companyId],
			checkRequisitePermissions: false,
		);
		foreach ($myCompanies as $myCompany)
		{
			if ($myCompany->id === $companyId)
			{
				$company = $myCompany;
				break;
			}
		}

		if ($company === null)
		{
			$errors->setError(self::makeError(self::ERROR_COMPANY_NOT_FOUND));

			return null;
		}

		$taxId = trim((string)$company->taxId);
		if ($taxId === '')
		{
			$errors->setError(self::makeError(self::ERROR_COMPANY_WITHOUT_TAX_ID));

			return null;
		}

		$response = $container->getCompanyService()->getCompanies(
			taxIds: [$taxId],
			supportedProviders: ProviderCode::getAllFormattedCodes(),
			useProvidersWhereSignerSignFirst: $signerSignsFirst,
		);
		if (!$response->isSuccess())
		{
			self::$companyProviders[$askedFor] = ['providers' => [], 'errors' => $response->getErrors()];
			$errors->add($response->getErrors());

			return null;
		}

		self::$companyProviders[$askedFor] = [
			'providers' => self::extractProviders($response, $taxId),
			'errors' => [],
		];

		return self::$companyProviders[$askedFor]['providers'];
	}

	/**
	 * A seam for the tests and for nothing else: the runtime path never forgets the answers of the
	 * signing service, because they are kept for the whole hit on purpose. A test substituting the
	 * service anew has to be answered by that one, so it drops what an earlier substitution left.
	 *
	 * @see self::$companyProviders why the answers outlive a single run
	 */
	protected static function forgetCompanyProviders(): void
	{
		self::$companyProviders = [];
	}

	/**
	 * @return array<array{uid: string, code: string, timestamp: int}>
	 */
	private static function extractProviders(Item\Api\Company\GetResponse $response, string $taxId): array
	{
		$providers = [];
		foreach ($response->companies as $company)
		{
			if (!is_array($company) || (string)($company['taxId'] ?? '') !== $taxId)
			{
				continue;
			}

			foreach ((array)($company['providers'] ?? []) as $provider)
			{
				if (!is_array($provider))
				{
					continue;
				}

				$uid = trim((string)($provider['uid'] ?? ''));
				$code = ProviderCode::createFromProviderLikeString((string)($provider['code'] ?? ''));
				if ($uid !== '' && $code !== null)
				{
					$providers[] = [
						'uid' => $uid,
						'code' => $code,
						'timestamp' => (int)($provider['date'] ?? 0),
					];
				}
			}
		}

		return self::sortRegistrations($providers);
	}

	/**
	 * The registration made last comes first, and the uid decides between the registrations of one
	 * date: the order the signing service answers in is not defined, while the registration the
	 * document is bound to must not depend on it.
	 *
	 * The company dictionary of the signing wizard orders the registrations the same way, save for
	 * the one its user took last: that half of its order is per user, and a robot has no user of
	 * its own to ask about.
	 *
	 * @see \Bitrix\Sign\Controllers\V1\Integration\Crm\B2eCompany::listAction()
	 *
	 * @param array<array{uid: string, code: string, timestamp: int}> $providers
	 *
	 * @return array<array{uid: string, code: string, timestamp: int}>
	 */
	private static function sortRegistrations(array $providers): array
	{
		usort(
			$providers,
			static function (array $first, array $second): int
			{
				$byDate = $second['timestamp'] <=> $first['timestamp'];

				return $byDate === 0 ? strcmp($first['uid'], $second['uid']) : $byDate;
			},
		);

		return $providers;
	}

	/**
	 * The company registration to bind the document to: with no code given the registration the
	 * company made last is taken.
	 *
	 * @see self::sortRegistrations()
	 *
	 * @param array<array{uid: string, code: string, timestamp: int}> $providers
	 *
	 * @return array{uid: string, code: string, timestamp: int}|null
	 */
	private static function findRegistration(array $providers, ?string $providerCode): ?array
	{
		foreach ($providers as $provider)
		{
			if ($providerCode === null || $provider['code'] === $providerCode)
			{
				return $provider;
			}
		}

		return null;
	}

	/**
	 * The document the settings name, zero where they name none. The platform casts the parameter
	 * of an int field, and a cast of `12345x` would quietly name the document 12345, an object the
	 * settings check has never seen, because the same value names no document there either.
	 *
	 * The value is taken the way the platform takes it: an expression of the settings is only known
	 * on execution, so the raw parameter is read through the same substitution.
	 *
	 * @see self::findSettingsDocument() the settings half of the same strictness
	 */
	private function resolveDocumentId(): int
	{
		$value = self::normalizeToString($this->parseValue($this->getRawProperty(self::PARAM_DOCUMENT_ID)));

		return self::isDigitsOnly($value) ? (int)$value : 0;
	}

	private function isSettingFilled(string $property): bool
	{
		return !CBPHelper::isEmptyValue($this->getRawProperty($property));
	}

	/**
	 * Null means the parameter is not filled in the settings and does not get into the plan.
	 * A filled parameter whose expression brought nothing is an error instead.
	 */
	private function resolveSettingString(string $property, Main\ErrorCollection $errors): ?string
	{
		if (!$this->isSettingFilled($property))
		{
			return null;
		}

		$value = self::normalizeToString($this->{$property});
		if ($value === '')
		{
			$errors->setError(self::makeEmptySettingError($property));

			return null;
		}

		return $value;
	}

	/**
	 * Whether the run replaces the list of signers instead of adding to it. The setting carries a
	 * default of its own, and that default means the same as a setting nobody filled, so
	 * {@see self::isSettingFilled()} cannot tell the two apart the way it does for the settings of
	 * {@see self::SETTINGS}: the raw value does. An expression of the process that brought nothing is
	 * refused naming the setting: read as "add", it would leave the signers of the document in place
	 * and have them invited to sign, while the settings asked for them to be replaced.
	 */
	private function resolveReplaceSigners(Main\ErrorCollection $errors): bool
	{
		$rawValue = $this->getRawProperty(self::PARAM_REPLACE_SIGNERS);
		if (!static::isExpression($rawValue))
		{
			return CBPHelper::getBool($this->{self::PARAM_REPLACE_SIGNERS});
		}

		// the value is read before the platform casts it: the cast of a bool field turns a value
		// that never came and an explicit "no" into one and the same false
		$value = $this->parseValue($rawValue);
		if ($value !== false && CBPHelper::isEmptyValue($value))
		{
			$errors->setError(self::makeEmptySettingError(self::PARAM_REPLACE_SIGNERS));

			return false;
		}

		return CBPHelper::getBool($value);
	}

	/**
	 * The map must stay free of CRM and external service calls: the same map serves
	 * rendering, settings validation and execution.
	 */
	public static function getPropertiesMap(array $documentType, array $context = []): array
	{
		return [
			self::PARAM_DOCUMENT_ID => [
				'Name' => Loc::getMessage('SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_CONFIGURE_DOCUMENT_ID'),
				'Hint' => Loc::getMessage('SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_CONFIGURE_DOCUMENT_ID_HINT'),
				'FieldName' => self::PARAM_DOCUMENT_ID,
				'Type' => FieldType::INT,
				'Required' => true,
			],
			self::PARAM_COMPANY => [
				'Name' => Loc::getMessage('SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_CONFIGURE_COMPANY'),
				'FieldName' => self::PARAM_COMPANY,
				'Type' => FieldType::STRING,
				'Required' => false,
			],
			self::PARAM_PROVIDER_CODE => [
				'Name' => Loc::getMessage('SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_CONFIGURE_PROVIDER_CODE'),
				'FieldName' => self::PARAM_PROVIDER_CODE,
				'Type' => FieldType::STRING,
				'Required' => false,
			],
			self::PARAM_REPRESENTATIVE => [
				'Name' => Loc::getMessage('SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_CONFIGURE_REPRESENTATIVE'),
				'FieldName' => self::PARAM_REPRESENTATIVE,
				'Type' => FieldType::USER,
				'Required' => false,
			],
			self::PARAM_SIGNERS => [
				'Name' => Loc::getMessage('SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_CONFIGURE_SIGNERS'),
				'FieldName' => self::PARAM_SIGNERS,
				'Type' => FieldType::USER,
				'Multiple' => true,
				'Required' => false,
			],
			self::PARAM_REPLACE_SIGNERS => [
				'Name' => Loc::getMessage('SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_CONFIGURE_REPLACE_SIGNERS'),
				'FieldName' => self::PARAM_REPLACE_SIGNERS,
				'Type' => FieldType::BOOL,
				'Default' => 'N',
				'Required' => false,
			],
			self::PARAM_DOCUMENT_CODE => [
				'Name' => Loc::getMessage('SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_CONFIGURE_DOCUMENT_CODE'),
				'FieldName' => self::PARAM_DOCUMENT_CODE,
				'Type' => FieldType::STRING,
				'Required' => false,
			],
			self::PARAM_EXTERNAL_ID => [
				'Name' => Loc::getMessage('SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_CONFIGURE_EXTERNAL_ID'),
				'FieldName' => self::PARAM_EXTERNAL_ID,
				'Type' => FieldType::STRING,
				'Required' => false,
			],
			self::PARAM_REMINDER_TYPE => [
				'Name' => Loc::getMessage('SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_CONFIGURE_REMINDER_TYPE'),
				'FieldName' => self::PARAM_REMINDER_TYPE,
				'Type' => FieldType::SELECT,
				'Options' => self::getReminderOptions(),
				'Required' => false,
			],
			self::PARAM_DATE_SIGN_UNTIL => [
				'Name' => Loc::getMessage('SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_CONFIGURE_DATE_SIGN_UNTIL'),
				'FieldName' => self::PARAM_DATE_SIGN_UNTIL,
				'Type' => FieldType::DATETIME,
				'Required' => false,
			],
		];
	}

	public static function getPropertiesDialogMap(?PropertiesDialog $dialog = null): array
	{
		return static::getPropertiesMap($dialog?->getDocumentType() ?? []);
	}

	public static function validateProperties($testProperties = [], \CBPWorkflowTemplateUser $user = null): array
	{
		$errors = parent::validateProperties($testProperties, $user);
		if (!static::checkModules())
		{
			return $errors;
		}

		// zero user is the system context of template copy and import: there is no subject to check
		$userId = (int)($user?->getId() ?? 0);
		if ($userId > 0 && !self::canEditB2eDocuments($userId, self::findSettingsDocument($testProperties)))
		{
			$errors[] = self::makeSettingsError(
				'AccessDenied',
				'',
				'SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_CONFIGURE_ERROR_ACCESS_DENIED',
			);
		}

		$providerCode = self::normalizeToString($testProperties[self::PARAM_PROVIDER_CODE] ?? null);
		if ($providerCode !== '' && !static::isExpression($providerCode))
		{
			$savedProviderCode = ProviderCode::createFromProviderLikeString($providerCode);
			if ($savedProviderCode === null)
			{
				$errors[] = self::makeSettingsError(
					'InvalidProviderCode',
					self::PARAM_PROVIDER_CODE,
					'SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_CONFIGURE_ERROR_INVALID_PROVIDER',
				);
			}
			elseif (ProviderCode::isTechnical($savedProviderCode))
			{
				$errors[] = self::makeSettingsError(
					'TechnicalProviderCode',
					self::PARAM_PROVIDER_CODE,
					'SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_CONFIGURE_ERROR_TECHNICAL_PROVIDER',
				);
			}
		}

		$reminderCode = self::normalizeToString($testProperties[self::PARAM_REMINDER_TYPE] ?? null);
		if (
			$reminderCode !== ''
			&& !static::isExpression($reminderCode)
			&& ReminderType::tryFrom($reminderCode) === null
		)
		{
			$errors[] = self::makeSettingsError(
				'InvalidReminderType',
				self::PARAM_REMINDER_TYPE,
				'SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_CONFIGURE_ERROR_INVALID_REMINDER',
			);
		}

		$dateSignUntil = self::normalizeToString($testProperties[self::PARAM_DATE_SIGN_UNTIL] ?? null);
		if (
			$dateSignUntil !== ''
			&& !static::isExpression($dateSignUntil)
			&& CBPHelper::makeTimestamp($dateSignUntil) < 1
		)
		{
			$errors[] = self::makeSettingsError(
				'InvalidDateSignUntil',
				self::PARAM_DATE_SIGN_UNTIL,
				'SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_CONFIGURE_ERROR_INVALID_DATE_SIGN_UNTIL',
			);
		}

		return $errors;
	}

	protected static function getFileName(): string
	{
		return __FILE__;
	}

	/**
	 * The document the settings name by a literal id: an expression is only known on execution,
	 * and a document of the settings may not exist yet, so both leave the check without an object.
	 */
	private static function findSettingsDocument(array $testProperties): ?Item\Document
	{
		$documentId = self::normalizeToString($testProperties[self::PARAM_DOCUMENT_ID] ?? null);
		if (!self::isDigitsOnly($documentId))
		{
			return null;
		}

		return Container::instance()->getDocumentRepository()->getById((int)$documentId);
	}

	/**
	 * Right of the subject to configure B2E documents, and the right to the given one where the
	 * document is already known: the permission of the scope is only honoured against an object.
	 */
	private static function canEditB2eDocuments(int $userId, ?Item\Document $document = null): bool
	{
		// createForCurrentUser() may fall back to an all-allowing controller, the subject is explicit here
		$accessController = Container::instance()->getAccessControllerFactory()->createByUserId($userId);
		if ($accessController === null)
		{
			return false;
		}

		return $document === null
			? $accessController->check(ActionDictionary::ACTION_B2E_DOCUMENT_EDIT)
			: $accessController->checkByItem(ActionDictionary::ACTION_B2E_DOCUMENT_EDIT, $document)
		;
	}

	private static function getReminderOptions(): array
	{
		return [
			ReminderType::NONE->value => Loc::getMessage(
				'SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_CONFIGURE_REMINDER_NONE',
			),
			ReminderType::ONCE_PER_DAY->value => Loc::getMessage(
				'SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_CONFIGURE_REMINDER_ONCE_PER_DAY',
			),
			ReminderType::TWICE_PER_DAY->value => Loc::getMessage(
				'SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_CONFIGURE_REMINDER_TWICE_PER_DAY',
			),
			ReminderType::THREE_TIMES_PER_DAY->value => Loc::getMessage(
				'SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_CONFIGURE_REMINDER_THREE_TIMES_PER_DAY',
			),
		];
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
	 * The setting whose value came empty travels beside the code: one code stands for every setting
	 * of the form, and the reader of the refusal has to know which of them it was.
	 */
	private static function makeEmptySettingError(string $property): Main\Error
	{
		return self::makeError(
			self::ERROR_EMPTY_SETTING,
			['#PROPERTY#' => self::getSettingTitle($property)],
			['setting' => $property],
		);
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
	 * The refusal of a list of signers too long, or null where it fits. The bound is the one of the
	 * tariff, and the activity brings its own where the tariff bounds nothing.
	 */
	private static function findSignersLimitError(int $count): ?Main\Error
	{
		$tariffLimit = B2eTariff::instance()->getB2eSignersCountLimitWithUnlimitCheck();

		return $count > ($tariffLimit ?? self::MAX_SIGNERS_WITHOUT_TARIFF_LIMIT)
			? self::makeSignersLimitError($count, $tariffLimit)
			: null
		;
	}

	/**
	 * A tariff that bounds the signers refuses with the error of its own: it is the one telling the
	 * user the bound grows with the plan. Where the tariff bounds nothing, the refusal names the
	 * size of the list the activity applies at once.
	 */
	private static function makeSignersLimitError(int $count, ?int $tariffLimit): Main\Error
	{
		if ($tariffLimit !== null)
		{
			return B2eTariff::instance()->getSignersCountAccessError();
		}

		return self::makeError(
			self::ERROR_TOO_MANY_SIGNERS,
			['#COUNT#' => $count, '#LIMIT#' => self::MAX_SIGNERS_WITHOUT_TARIFF_LIMIT],
		);
	}

	/**
	 * Steps the process log names on their own: they change more than the setting they are built
	 * from.
	 *
	 * A method and not a constant: the bizproc scheduler unserializes an activity in a cron agent
	 * where the sign autoload is not registered yet, and class constants are evaluated there.
	 *
	 * @return array<string, string> step name => message code
	 */
	private static function getStepTitles(): array
	{
		return [
			Step::MEMBERS => 'SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_CONFIGURE_STEP_MEMBERS',
		];
	}

	/**
	 * Setting each plan step changes, to name the step in the process log.
	 *
	 * @see self::getStepTitles() why the maps of the steps are methods
	 *
	 * @return array<string, string> step name => setting of the form
	 */
	private static function getStepSettings(): array
	{
		return [
			Step::REPRESENTATIVE => self::PARAM_REPRESENTATIVE,
			Step::COMPANY => self::PARAM_COMPANY,
			Step::PROVIDER => self::PARAM_PROVIDER_CODE,
			Step::DOCUMENT_CODE => self::PARAM_DOCUMENT_CODE,
			Step::EXTERNAL_ID => self::PARAM_EXTERNAL_ID,
			Step::DATE_SIGN_UNTIL => self::PARAM_DATE_SIGN_UNTIL,
		];
	}

	private static function getStepTitle(Step $step): string
	{
		$messageCode = self::getStepTitles()[$step->name] ?? null;
		if ($messageCode !== null)
		{
			return Loc::getMessage($messageCode) ?? $step->name;
		}

		return self::getSettingTitle(self::getStepSettings()[$step->name] ?? $step->name);
	}

	/**
	 * The setting is named in the process log the way the settings form names it. The names of the
	 * whole form are taken once: a single line of the log names up to eight settings.
	 */
	private static function getSettingTitle(string $property): string
	{
		if (self::$settingTitles === [])
		{
			foreach (self::getPropertiesMap([]) as $name => $field)
			{
				self::$settingTitles[$name] = (string)($field['Name'] ?? $name);
			}
		}

		return self::$settingTitles[$property] ?? $property;
	}

	private static function makeSettingsError(string $code, string $parameter, string $messageCode): array
	{
		return [
			'code' => $code,
			'parameter' => $parameter,
			'message' => Loc::getMessage($messageCode) ?? '',
		];
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
