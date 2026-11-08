<?php

namespace Bitrix\Crm\Integration\AI\Operation;

use Bitrix\AI\Context;
use Bitrix\AI\Engine;
use Bitrix\AI\Engine\IEngine;
use Bitrix\AI\Payload\IPayload;
use Bitrix\AI\Tuning\Manager;
use Bitrix\Crm\Badge;
use Bitrix\Crm\Copilot\Pipeline\StepContext;
use Bitrix\Crm\Copilot\Pipeline\TargetResolver;
use Bitrix\Crm\Copilot\Restriction\ExecutionDataManager;
use Bitrix\Crm\Dto\Dto;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\Config;
use Bitrix\Crm\Integration\AI\ErrorCode;
use Bitrix\Crm\Integration\AI\EventHandler;
use Bitrix\Crm\Integration\AI\Model\EO_Queue;
use Bitrix\Crm\Integration\AI\Model\QueueTable;
use Bitrix\Crm\Integration\AI\Operation\Payload\StubFactory;
use Bitrix\Crm\Integration\AI\Result;
use Bitrix\Crm\Integration\Analytics\Builder\AI\AIBaseEvent;
use Bitrix\Crm\Integration\Analytics\Builder\AI\CallParsingEvent;
use Bitrix\Crm\Integration\Analytics\Builder\AI\JobFinishEventContract;
use Bitrix\Crm\Integration\Analytics\Dictionary;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Requisite\EntityLink;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Timeline\ActivityController;
use Bitrix\Crm\Timeline\AI\Controller;
use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\DB\DuplicateEntryException;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Data\AddResult;
use Bitrix\Main\ORM\Data\UpdateResult;
use Bitrix\Main\ORM\Fields\FieldTypeMask;
use Bitrix\Main\Web\Json;
use Bitrix\Rest\Marketplace;
use CCrmActivity;
use CCrmActivityDirection;
use CCrmOwnerType;

/**
 * @todo refactor
 */
abstract class AbstractOperation
{
	public const TYPE_ID = 0;
	public const CONTEXT_ID = '';

	protected static int $engineId = 0;

	/** @var class-string<Dto> */
	protected const PAYLOAD_CLASS = Dto::class;
	protected const ENGINE_CATEGORY = 'text';
	protected const ENGINE_CODE = EventHandler::SETTINGS_FILL_ITEM_FROM_CALL_ENGINE_TEXT_CODE;

	/**
	 * Operations that never report their job finish to analytics. The only place where such silence
	 * is declared - operations themselves carry no flag.
	 *
	 * @see self::constructJobFinishEventBuilder
	 */
	private const EXCLUDED_OPERATION_TYPES = [
		// synthetic target id, no real assessment behind it
		GroupSuspiciousCalls::TYPE_ID,
		// test launches must stay out of production analytics
		Sandbox\FillRepeatSaleTips::TYPE_ID,
	];

	/** Operations that report a successful job finish through dedicated events. */
	private const SUCCESS_EXCLUDED_OPERATION_TYPES = [
		GenerateCallScriptFromDialog::TYPE_ID,
		SelectCallScoreScript::TYPE_ID,
		ScoreCallV2::TYPE_ID,
	];

	// @todo: inject via DI — currently each operation creates its own instance,
	// and PipelineExecutor/StepFactory create additional ones. Consolidate after AutoLauncher migration.
	protected TargetResolver $targetResolver;

	private bool $isManualLaunch = true;
	private ?string $scenario;
	private ?string $contextLanguageId;
	private ?int $nextTypeIdOverride = null;
	private array $contextExtra = [];

	/**
	 * The clicked entity of a manual FillFields launch, carried through the async engine context so
	 * it survives the Transcribe/Summarize→FillFields boundary (ALG-01, single-target).
	 * Set per step by PipelineExecutor::launchStep from StepContext::getManualTarget(); serialized into
	 * the engine Context additionalInfo (see getContextAdditionalInfo) and re-read on the callback by
	 * EventHandler::extractFillTargetFromEngineContext. Null for the auto path.
	 */
	private ?ItemIdentifier $manualFillTarget = null;

	public function __construct(
		protected ItemIdentifier $target,
		protected ?int $userId = null,
		private readonly ?int $parentJobId = null,
	)
	{
		$this->targetResolver = new TargetResolver();

		$this->userId ??= Container::getInstance()->getContext()->getUserId();
		$this->contextLanguageId = $this->getContextLanguageId();
		$this->scenario = Scenario::FULL_SCENARIO;
	}

	public static function isAccessGranted(int $userId, ItemIdentifier $target): bool
	{
		if ($userId <= 0)
		{
			return false;
		}

		$entityTypeId = $target->getEntityTypeId();

		// Any Factory-based CRM entity is a valid fill target (single source of truth).
		// Activity and CopilotCallAssessment stay allowed as technical step targets
		// (transcription/summary target the Activity, scoring targets the assessment).
		return AIManager::isEntityTypeSupported($entityTypeId)
			|| in_array(
				$entityTypeId,
				[
					CCrmOwnerType::Activity,
					CCrmOwnerType::CopilotCallAssessment,
				],
				true
			)
		;
	}

	public static function isSuitableTarget(ItemIdentifier $target): bool
	{
		return true;
	}

	protected static function checkPreviousJobs(ItemIdentifier $target, int $parentId): \Bitrix\Main\Result
	{
		$result = new \Bitrix\Main\Result();

		$previousJob = static::findDuplicateJob($target, $parentId);
		if (!$previousJob)
		{
			return $result;
		}

		$result->setData(['previousJob' => $previousJob]);

		if (
			$previousJob->requireExecutionStatus() === QueueTable::EXECUTION_STATUS_SUCCESS
			|| $previousJob->requireExecutionStatus() === QueueTable::EXECUTION_STATUS_PENDING
		)
		{
			return $result->addError(ErrorCode::getJobAlreadyExistsError());
		}

		if (
			$previousJob->requireExecutionStatus() === QueueTable::EXECUTION_STATUS_ERROR
			&& $previousJob->requireRetryCount() >= Result::MAX_RETRY_COUNT
		)
		{
			return $result->addError(ErrorCode::getJobMaxRetriesExceededError());
		}

		return $result;
	}

	protected function checkPreviousJobsBeforeLaunch(): \Bitrix\Main\Result
	{
		return static::checkPreviousJobs($this->target, (int)$this->parentJobId);
	}

	private static function isHarmlessDuplicateResult(\Bitrix\Main\Result $failedResult): bool
	{
		$errors = $failedResult->getErrors();
		if (empty($errors))
		{
			return false;
		}

		foreach ($errors as $error)
		{
			if ($error->getCode() !== ErrorCode::JOB_ALREADY_EXISTS)
			{
				return false;
			}
		}

		return true;
	}

	protected static function findDuplicateJob(ItemIdentifier $target, int $parentId): ?EO_Queue
	{
		return QueueTable::query()
			->setSelect(['ID', 'EXECUTION_STATUS', 'RETRY_COUNT'])
			->where('ENTITY_TYPE_ID', $target->getEntityTypeId())
			->where('ENTITY_ID', $target->getEntityId())
			->where('TYPE_ID', static::TYPE_ID)
			// we don't care about parent id - only one job of this type is allowed
			->setLimit(1)
			->fetchObject()
		;
	}

	private static function findJobRowByHash(?string $hash): ?EO_Queue
	{
		return QueueTable::query()
			->setSelect(['ID', 'ENTITY_TYPE_ID', 'ENTITY_ID', 'TYPE_ID', 'EXECUTION_STATUS', 'CREATED_TIME'])
			->where('HASH', $hash)
			->setLimit(1)
			->fetchObject()
		;
	}

	protected static function isSponsoredOperation(): bool
	{
		return false;
	}

	public function setIsManualLaunch(bool $isManualLaunch): self
	{
		$this->isManualLaunch = $isManualLaunch;

		return $this;
	}

	public function setScenario(string $scenario): self
	{
		$this->scenario = $scenario;

		return $this;
	}

	/**
	 * Carries the clicked entity of a manual FillFields launch into the engine context so it survives
	 * the async step boundary. Re-set on every step (Transcribe/Summarize/FillFields) so the last hop
	 * before FillFields still carries it. Null clears it (auto path).
	 * @see self::$manualFillTarget
	 */
	public function setManualFillTarget(?ItemIdentifier $target): self
	{
		$this->manualFillTarget = $target;

		return $this;
	}

	/**
	 * Determines whether the pipeline should continue to the next step after this operation completes.
	 * Override in subclasses for operation-specific logic (e.g., check autostart settings).
	 */
	public static function canProceedToNextStep(Result $result, StepContext $context): bool
	{
		return $result->isSuccess();
	}

	/**
	 * Determines whether a successful existing result must be ignored and the step re-launched.
	 * Default: no — an existing successful result shortcircuits the step.
	 * Override in subclasses that support manual re-run with new parameters (e.g., ScoreCall
	 * with a different assessmentSettingsId).
	 */
	public static function shouldRelaunch(Result $existingResult, StepContext $context): bool
	{
		return false;
	}

	protected static function checkPreviousJobsAllowingSuccessfulRelaunch(ItemIdentifier $target, int $parentId): \Bitrix\Main\Result
	{
		$result = new \Bitrix\Main\Result();

		$previousJob = static::findDuplicateJob($target, $parentId);
		if (!$previousJob)
		{
			return $result;
		}

		if ($previousJob->requireExecutionStatus() === QueueTable::EXECUTION_STATUS_SUCCESS)
		{
			return $result;
		}

		if ($previousJob->requireExecutionStatus() === QueueTable::EXECUTION_STATUS_PENDING)
		{
			return $result->addError(ErrorCode::getJobAlreadyExistsError());
		}

		if (
			$previousJob->requireExecutionStatus() === QueueTable::EXECUTION_STATUS_ERROR
			&& $previousJob->requireRetryCount() >= Result::MAX_RETRY_COUNT
		)
		{
			return $result->addError(ErrorCode::getJobMaxRetriesExceededError());
		}

		$result->setData(['previousJob' => $previousJob]);

		return $result;
	}

	public function setNextTypeIdOverride(int $nextTypeId): self
	{
		$this->nextTypeIdOverride = $nextTypeId;

		return $this;
	}

	public function setContextExtra(string $key, mixed $value): self
	{
		$this->contextExtra[$key] = $value;

		return $this;
	}

	public function launch(): Result
	{
		AIManager::logger()->debug(
			'{date}: {class}: Trying to launch operation {operationType} on target {target}'
			. ' for user {userId} and parent job {parentJobId}' . PHP_EOL,
			[
				'class' => static::class,
				'operationType' => static::TYPE_ID,
				'target' => $this->target,
				'userId' => $this->userId,
				'parentJobId' => $this->parentJobId,
			],
		);

		$result = new Result(
			static::TYPE_ID,
			$this->target,
			$this->userId,
			parentJobId: $this->parentJobId,
			isManualLaunch: $this->isManualLaunch,
		);

		if (!AIManager::isAILicenceAccepted($this->userId))
		{
			AIManager::logger()->error(
				'{date}: {class}: Cant start operation {operationType} on {target} because the license agreement to use AI has not been accepted' . PHP_EOL,
				['class' => static::class, 'target' => $this->target, 'operationType' => static::TYPE_ID],
			);

			$result->addError(ErrorCode::getLicenseNotAcceptedError());

			static::notifyAboutJobError($result, false, target: $this->manualFillTarget);

			return $result;
		}

		if (!AIManager::isAvailable())
		{
			AIManager::logger()->error(
				'{date}: {class}: Cant start operation {operationType} on {target} because AI module is not installed' . PHP_EOL,
				['class' => static::class, 'target' => $this->target, 'operationType' => static::TYPE_ID],
			);

			$result->addError(ErrorCode::getAINotAvailableError());

			static::notifyAboutJobError($result, false, target: $this->manualFillTarget);

			return $result;
		}

		if (!Scenario::isEnabledScenario($this->scenario))
		{
			AIManager::logger()->error(
				'{date}: {class}: Cant start operation because AI operations in CRM is disabled (see settings). Target {target}, operation {operationType}, scenario {scenario}' . PHP_EOL,
				[
					'class' => static::class,
					'target' => $this->target,
					'operationType' => static::TYPE_ID,
					'scenario' => $this->scenario,
				],
			);

			$sliderCode = Scenario::getDisabledSliderCode($this->scenario);
			$result->addError(
				$sliderCode !== null
					? ErrorCode::getAIDisabledError(['sliderCode' => $sliderCode])
					: ErrorCode::getAIScenarioError()
			);

			static::notifyAboutJobError($result, false, target: $this->manualFillTarget);

			return $result;
		}

		if (!static::isAccessGranted($this->userId, $this->target))
		{
			AIManager::logger()->error(
				'{date}: {class}: User with ID {userId} has no access to the target {target} for this operation {operationType}' . PHP_EOL,
				[
					'class' => static::class,
					'userId' => $this->userId,
					'target' => $this->target,
					'operationType' => static::TYPE_ID,
				],
			);

			$result->addError(\Bitrix\Crm\Controller\ErrorCode::getAccessDeniedError());

			static::notifyAboutJobError($result, false, target: $this->manualFillTarget);

			return $result;
		}

		if (!static::isSuitableTarget($this->target))
		{
			AIManager::logger()->error(
				'{date}: {class}: Target {target} is not suitable for this operation {operationType}' . PHP_EOL,
				['class' => static::class, 'target' => $this->target, 'operationType' => static::TYPE_ID],
			);

			$result->addError(ErrorCode::getNotSuitableTargetError());

			static::notifyAboutJobError($result, false, target: $this->manualFillTarget);

			return $result;
		}

		$context = $this->getAIEngineContext();
		$engine = $this->getAIEngine($context);
		if (!$engine)
		{
			AIManager::logger()->critical(
				'{date}: {class}: Cant start operation {operationType} on {target} because there is no relevant AI engine!'
				. ' Category {engineCategory}, context {engineContext}' . PHP_EOL,
				[
					'class' => static::class,
					'target' => $this->target,
					'operationType' => static::TYPE_ID,
					'engineCategory' => static::ENGINE_CATEGORY,
					'engineContext' => $context,
				],
			);

			$result->addError(ErrorCode::getAIEngineNotFoundError([
				'isAiMarketplaceAppsExist' => $this->isAiMarketplaceAppsExist(),
			]));

			static::notifyAboutJobError($result, false, target: $this->manualFillTarget);

			return $result;
		}

		if (method_exists($engine, 'skipAgreement'))
		{
			$engine->skipAgreement();
		}

		$checkJobsResult = $this->checkPreviousJobsBeforeLaunch();
		if (!$checkJobsResult->isSuccess())
		{
			AIManager::logger()->error(
				'{date}: {class}: Cant start operation {operationType} on {target}'
				. ' because of duplication or retry policy: {errors}' . PHP_EOL,
				['class' => static::class, 'target' => $this->target, 'operationType' => static::TYPE_ID, 'errors' => $checkJobsResult->getErrors()],
			);

			$result->addErrors($checkJobsResult->getErrors());

			// A harmless duplicate (operation already running/finished) must not be reported to the
			// timeline as a scoring failure - only genuine failures (e.g. retries exceeded) are.
			if (!self::isHarmlessDuplicateResult($checkJobsResult))
			{
				static::notifyAboutJobError($result, false, target: $this->manualFillTarget);
			}

			return $result;
		}

		$previousJob = $checkJobsResult->getData()['previousJob'] ?? null;

		$isSponsored = false;
		$aiPayloadResult = $this->getAIPayload();
		if (!$aiPayloadResult->isSuccess())
		{
			AIManager::logger()->error(
				'{date}: {class}: Error while trying to form AI payload for target {target}'
				. ' in operation {operationType}: {errors}' . PHP_EOL,
				[
					'class' => static::class,
					'target' => $this->target,
					'operationType' => static::TYPE_ID,
					'errors' => $aiPayloadResult->getErrors(),
				],
			);

			$result->addErrors($aiPayloadResult->getErrors());

			static::notifyAboutJobError($result, false, target: $this->manualFillTarget);

			return $result;
		}

		$hash = null;
		$error = null;
		$beforeDispatchResult = $this->beforeLaunchDispatch($previousJob);
		if (!$beforeDispatchResult->isSuccess())
		{
			$result->addErrors($beforeDispatchResult->getErrors());

			if (!self::isHarmlessDuplicateResult($beforeDispatchResult))
			{
				static::notifyAboutJobError($result, false, target: $this->manualFillTarget);
			}

			return $result;
		}

		if (AIManager::isStubMode())
		{
			$hash = AIManager::registerStubJob($engine, $this->getStubPayload());

			AIManager::logger()->info(
				'{date}: {class}: Created stub job {hash} for target {target} in operation {operationType}' . PHP_EOL,
				[
					'class' => static::class,
					'hash' => $hash,
					'target' => $this->target,
					'operationType' => static::TYPE_ID,
				],
			);
		}
		else
		{
			/** @var IPayload $payload */
			$aiPayload = $aiPayloadResult->getData()['payload'];
			$isSponsored = $aiPayload instanceof IPayload
				&& method_exists($aiPayload, 'setCost')
				&& Loader::includeModule('bitrix24')
				&& static::isSponsoredOperation()
			;

			if ($isSponsored)
			{
				AIManager::logger()->debug(
					'{date}: {class}: Set the cost of the operation to zero for target {target} in operation {operationType}' . PHP_EOL,
					[
						'class' => static::class,
						'target' => $this->target,
						'operationType' => static::TYPE_ID,
					],
				);

				$aiPayload->setCost(0);
			}

			$engine
				->setPayload($aiPayload)
				->setHistoryState(false)
				->onSuccess(static function (\Bitrix\AI\Result $result, ?string $queueHash = null) use (&$hash) {
					$hash = $queueHash;
				})
				->onError(static function (Error $processingError) use (&$error) {
					$error = $processingError;
				})
			;

			static::setQuality($engine);

			if (static::ENGINE_CATEGORY === 'audio')
			{
				$engine->completions();
			}
			else
			{
				$engine->completionsInQueue();
			}

			AIManager::logger()->info(
				'{date}: {class}: Created AI job for target {target} in operation {operationType}' . PHP_EOL,
				[
					'class' => static::class,
					'target' => $this->target,
					'operationType' => static::TYPE_ID,
				],
			);
		}

		if ($error instanceof Error)
		{
			//first, process limit errors
			$limitError = AIManager::fetchLimitError($error);
			if ($limitError !== null)
			{
				$result->addError($limitError);

				$errorData = $limitError->getCustomData() ?? [];

				AIManager::logger()->error(
					'{date}: {class}: Cant start operation {operationType} on {target} because limit of requests to AI exceeded!'
					. ' Category {engineCategory}, context {engineContext}, slider code "{sliderCode}"' . PHP_EOL,
					[
						'class' => static::class,
						'target' => $this->target,
						'operationType' => static::TYPE_ID,
						'engineCategory' => static::ENGINE_CATEGORY,
						'engineContext' => $context,
						'sliderCode' => $errorData['sliderCode'] ?? null,
					],
				);

				if (!$this->isManualLaunch)
				{
					static::notifyAboutLimitExceededError($result);
				}

				static::notifyAboutJobError($result, false, target: $this->manualFillTarget);

				return $result;
			}

			AIManager::logger()->critical(
				'{date}: {class}: Error while adding AI job for target {target} in operation {operationType} for activity{activity}: {error}' . PHP_EOL,
				[
					'class' => static::class,
					'target' => $this->target,
					'operationType' => static::TYPE_ID,
					'error' => $error,
					'activity' => self::getTargetRealId($this->target, $this->parentJobId),
				],
			);

			if (empty($error->getMessage()))
			{
				$error = new Error(ErrorCode::getAIEngineNotFoundError()->getMessage());
			}

			$result->addError($error);

			static::notifyAboutJobError($result, target: $this->manualFillTarget);

			return $result;
		}

		$this->logOperationLaunched((string)$hash, $this->parentJobId);

		if ($previousJob instanceof EO_Queue)
		{
			AIManager::logger()->debug(
				'{date}: {class}: Updating existing job {id} in CRM DB for target {target} in operation {operationType}' . PHP_EOL,
				[
					'class' => static::class,
					'id' => $previousJob->getId(),
					'target' => $this->target,
					'operationType' => static::TYPE_ID,
				],
			);
		}
		else
		{
			AIManager::logger()->debug(
				'{date}: {class}: Creating a new job row in CRM DB for target {target} in operation {operationType}' . PHP_EOL,
				[
					'class' => static::class,
					'target' => $this->target,
					'operationType' => static::TYPE_ID,
				],
			);
		}
		$dbSaveResult = $this->saveLaunchResult($hash, $previousJob);

		if (!$dbSaveResult->isSuccess())
		{
			AIManager::logger()->critical(
				'{date}: {class}: Errors while saving job to CRM DB for target {target} in operation {operationType} for hash {hash} for activity{activity}: {errors}' . PHP_EOL,
				[
					'class' => static::class,
					'target' => $this->target,
					'operationType' => static::TYPE_ID,
					'errors' => $dbSaveResult->getErrors(),
					'hash' => $hash,
					'activity' => self::getTargetRealId($this->target, $this->parentJobId),
				],
			);

			$result->addErrors($dbSaveResult->getErrors());

			// A hash held by a job that is still alive refuses the launch the same way a duplicate found by
			// the pre-check does: the first attempt keeps running, so the entity must not get the error badge
			// and the timeline record of a failure. Any other save failure is reported as before.
			if (!self::isHarmlessDuplicateResult($dbSaveResult))
			{
				static::notifyAboutJobError($result, true, false, $this->manualFillTarget);
			}

			return $result;
		}

		$result->setJobId($dbSaveResult->getId());

		if ($result->isSuccess())
		{
			AIManager::logger()->debug(
				'{date}: {class}: Notifying timeline about operation launch for target {target} in operation {operationType}' . PHP_EOL,
				[
					'class' => static::class,
					'target' => $this->target,
					'operationType' => static::TYPE_ID,
				],
			);

			static::notifyTimelineAfterSuccessfulLaunch($result);

			if ($isSponsored)
			{
				ExecutionDataManager::getInstance()->incrementExecutionCount();
			}
		}

		AIManager::logger()->debug(
			'{date}: {class}: Operation launch for target {target} finished with result {result}' . PHP_EOL,
			[
				'class' => static::class,
				'target' => $this->target,
				'result' => $result,
			],
		);

		if (static::TYPE_ID === 1)
		{
			static::notifyTimelinesAboutActivityUpdate($this->target->getEntityId());
			self::sendCallParsingAnalyticsEvent($result, self::extractActivityIdFromResult($result));
		}

		return $result;
	}

	abstract protected function getAIPayload(): \Bitrix\Main\Result;

	protected function beforeLaunchDispatch(?EO_Queue $previousJob): \Bitrix\Main\Result
	{
		return new \Bitrix\Main\Result();
	}

	protected function saveLaunchResult(?string $hash, ?EO_Queue $previousJob): AddResult|UpdateResult
	{
		return $this->saveJobRow(
			$hash,
			$previousJob?->getId(),
			['HASH' => $hash] + ($previousJob ? $this->getJobUpdateFields() : $this->getJobAddFields()),
		);
	}

	protected function getJobUpdateFields(): array
	{
		return [
			'USER_ID' => $this->userId,
			'EXECUTION_STATUS' => QueueTable::EXECUTION_STATUS_PENDING,
			'ERROR_CODE' => null,
			'ERROR_MESSAGE' => null,
			'RETRY_COUNT' => new SqlExpression('?# + 1', 'RETRY_COUNT'),
			'IS_MANUAL_LAUNCH' => $this->isManualLaunch,
			'LANGUAGE_ID' => $this->contextLanguageId,
			'ENGINE_ID' => self::$engineId,
			'NEXT_TYPE_ID' => $this->nextTypeIdOverride ?? Scenario::getNextTypeIdByScenario($this->scenario),
		];
	}

	protected function getJobAddFields(): array
	{
		return [
			'ENTITY_TYPE_ID' => $this->target->getEntityTypeId(),
			'ENTITY_ID' => $this->target->getEntityId(),
			'TYPE_ID' => static::TYPE_ID,
			'USER_ID' => $this->userId,
			'PARENT_ID' => (int)$this->parentJobId,
			'IS_MANUAL_LAUNCH' => $this->isManualLaunch,
			'LANGUAGE_ID' => $this->contextLanguageId,
			'ENGINE_ID' => self::$engineId,
			'NEXT_TYPE_ID' => $this->nextTypeIdOverride ?? Scenario::getNextTypeIdByScenario($this->scenario),
		];
	}

	/**
	 * The single write point of the queue row: a null $previousJobId inserts a new row, an id updates
	 * that row. The job hash comes from the `ai` module, which is allowed to reuse the hash of a job
	 * that is still alive, while IX_CRM_AI_QUEUE_HASH forbids a second row with it. A hash held by a
	 * row other than the one being updated refuses the launch with the "job already exists" error and
	 * leaves that row untouched. Its execution status and creation time go to the log: a hash held by
	 * an already finished row makes the refusal permanent instead of a wait for the callback of the
	 * first attempt.
	 */
	private function saveJobRow(?string $hash, ?int $previousJobId, array $fields): AddResult|UpdateResult
	{
		$occupiedRow = self::findJobRowByHash($hash);

		if ($occupiedRow === null)
		{
			try
			{
				return $previousJobId === null
					? QueueTable::add($fields)
					: QueueTable::update($previousJobId, $fields)
				;
			}
			catch (DuplicateEntryException)
			{
				// the hash was taken between the check above and this write
				$occupiedRow = self::findJobRowByHash($hash);
			}
		}

		if ($occupiedRow !== null)
		{
			if ($previousJobId !== null && $occupiedRow->getId() === $previousJobId)
			{
				return QueueTable::update($previousJobId, $fields);
			}

			$isOwnRow = $occupiedRow->getEntityTypeId() === $this->target->getEntityTypeId()
				&& $occupiedRow->getEntityId() === $this->target->getEntityId()
				&& (int)$occupiedRow->getTypeId() === static::TYPE_ID
			;

			if (!$isOwnRow)
			{
				AIManager::logger()->critical(
					'{date}: {class}: AI job {hash} is already taken by the row of entity'
					. ' {occupiedEntityTypeId}:{occupiedEntityId} in operation {occupiedOperationType}'
					. ' with status {occupiedExecutionStatus} created at {occupiedCreatedTime},'
					. ' launch on {target} in operation {operationType} is refused' . PHP_EOL,
					[
						'class' => static::class,
						'hash' => $hash,
						'occupiedEntityTypeId' => $occupiedRow->getEntityTypeId(),
						'occupiedEntityId' => $occupiedRow->getEntityId(),
						'occupiedOperationType' => (int)$occupiedRow->getTypeId(),
						'occupiedExecutionStatus' => $occupiedRow->getExecutionStatus(),
						'occupiedCreatedTime' => $occupiedRow->getCreatedTime(),
						'target' => $this->target,
						'operationType' => static::TYPE_ID,
					],
				);
			}
		}

		$refusedResult = new AddResult();
		$refusedResult->addError(ErrorCode::getJobAlreadyExistsError());

		return $refusedResult;
	}

	protected function getContextAdditionalInfo(): array
	{
		$additionalInfo = [
			'myCompanyName' => Container::getInstance()->getCompanyBroker()->getTitle(EntityLink::getDefaultMyCompanyId()),
			'scenario' => $this->scenario,
			...$this->contextExtra,
		];

		// Manual FillFields target rides the same channel as `scenario` across the async boundary
		// (ALG-01). Kept minimal (type/id) — categoryId is re-resolved lazily downstream.
		if ($this->manualFillTarget !== null)
		{
			$additionalInfo['fillTarget'] = [
				'entityTypeId' => $this->manualFillTarget->getEntityTypeId(),
				'entityId' => $this->manualFillTarget->getEntityId(),
			];
		}

		return $additionalInfo;
	}

	protected function getContextLanguageId(): string
	{
		return Config::getDefaultLanguageId();
	}

	private function getAIEngineContext(): Context
	{
		$context = new Context('crm', static::CONTEXT_ID, $this->userId);
		$context->setParameters([
			'target' => $this->target->toArray(),
			'userId' => $this->userId,
			'parentJobId' => $this->parentJobId,
			'additionalInfo' => $this->getContextAdditionalInfo(),
		]);
		$context->setLanguage($this->contextLanguageId);

		return $context;
	}

	protected function getAIEngine(Context $context): ?Engine
	{
		$manager = new Manager();
		$item = $manager->getItem(static::ENGINE_CODE);
		if ($item === null)
		{
			return null;
		}

		$engine = Engine::getByCode(
			$item->getValue(),
			$context,
			static::ENGINE_CATEGORY,
		);

		if ($engine && $engine->getIEngine()->isThirdParty())
		{
			self::$engineId = $engine->getIEngine()->getRestItem()->getId();
		}

		return $engine;
	}

	protected function getStubPayload(): mixed
	{
		return StubFactory::build(static::TYPE_ID, $this->target)->makeStub();
	}

	private function isAiMarketplaceAppsExist(): bool
	{
		if (!Loader::includeModule('rest'))
		{
			return false;
		}

		$tagId = 'ai_provider_partner_crm';
		$cache = Application::getInstance()->getCache();
		$cacheId = $tagId . '_marketplace';
		$cacheTtl = 60 * 60 * 24; // 24 hour
		$cachePath = '/crm/ai/aiAppTag/';
		if ($cache->initCache($cacheTtl, $cacheId, $cachePath))
		{
			$result = $cache->getVars();
		}
		else
		{
			$marketplaceApps = Marketplace\Client::getByTag($tagId, 0, 1);
			$result = count($marketplaceApps['ITEMS'] ?? []) > 0;
			$cache->startDataCache();
			$cache->endDataCache($result);
		}

		return $result;
	}

	public static function onQueueJobExecute(Event $event, EO_Queue $job): Result
	{
		AIManager::logger()->debug(
			'{date}: {class}: Processing event {event} for job {job}' . PHP_EOL,
			[
				'event' => $event,
				'job' => $job->collectValues(fieldsMask: FieldTypeMask::FLAT),
				'class' => static::class,
			],
		);

		$dummyResult = static::constructResult($job);

		// Clicked entity carried across the async boundary (ALG-01); passed into the error branches so
		// Transcribe/Summarize failures badge the initiating entity (ERR-002/AC-030), not a re-resolved
		// priority Deal/Lead. Null for the auto path — notifyAboutJobError then falls back to findTarget.
		$fillTarget = static::extractFillTargetFromEngineContext($event);

		/** @var \Bitrix\AI\Result|null $aiResult */
		$aiResult = $event->getParameter('result');

		if (!($aiResult instanceof \Bitrix\AI\Result))
		{
			AIManager::logger()->critical(
				'{date}: {class}: There is no AI result in event {event} for job {job} for activity{activity}' . PHP_EOL,
				[
					'event' => $event,
					'job' => $job->collectValues(fieldsMask: FieldTypeMask::FLAT),
					'class' => static::class,
					'activity' => self::extractActivityIdFromJob($job),
				],
			);

			$dummyResult->addError(ErrorCode::getAIResultFoundError());

			static::notifyAboutJobError($dummyResult, target: $fillTarget);

			return self::saveErrorToJobAndReturnResult($job, $dummyResult);
		}

		$payload = static::extractPayloadFromAIResult($aiResult, $job);

		if ($payload->hasValidationErrors())
		{
			AIManager::logger()->critical(
				'{date}: {class}: Error validating payload from AI result with hash {hash} for activity{activity}: errors {errors}, AI result {aiResult}' . PHP_EOL,
				[
					'class' => static::class,
					'errors' => $payload->getValidationErrors()->toArray(),
					'aiResult' => $aiResult,
					'hash' => $job->requireHash(),
					'activity' => self::extractActivityIdFromJob($job),
				],
			);

			$dummyResult->addErrors(
				$payload->getValidationErrors()->toArray()
			);

			$result = self::saveErrorToJobAndReturnResult($job, $dummyResult);

			static::notifyAboutJobError($dummyResult, target: $fillTarget);

			return $result;
		}

		$job->setResult(self::encodeResultWithContextMetadata($payload, $event->getParameter('engine')?->getContext()));
		$job->setExecutionStatus(QueueTable::EXECUTION_STATUS_SUCCESS);

		AIManager::logger()->debug(
			'{date}: {class}: Got valid response from AI, saving it to CRM DB: {job}' . PHP_EOL,
			[
				'class' => static::class,
				'job' => $job->collectValues(fieldsMask: FieldTypeMask::FLAT),
			],
		);

		$dbSaveResult = $job->save();
		if (!$dbSaveResult->isSuccess())
		{
			AIManager::logger()->critical(
				'{date}: {class}: Error while saving results to CRM DB with hash {hash} for activity{activity}: {errors}' . PHP_EOL,
				[
					'class' => static::class,
					'errors' => $dbSaveResult->getErrors(),
					'hash' => $job->requireHash(),
					'activity' => self::extractActivityIdFromJob($job),
				],
			);

			$job->resetResult();

			$dummyResult->addErrors($dbSaveResult->getErrors());

			static::notifyAboutJobError($dummyResult, true, false, $fillTarget);

			return self::saveErrorToJobAndReturnResult($job, $dummyResult);
		}

		$result = static::constructResult($job);

		if ($result->isSuccess())
		{
			static::onAfterSuccessfulJobFinish($result, $event->getParameter('engine')?->getContext());
			self::logOperationProgress('operationComplete',$result->getTarget(), $job->requireHash(), $result->getParentJobId());

			AIManager::logger()->debug(
				'{date}: {class}: Notifying timeline about operation finish'
				. ' for target {target} in operation {operationType}' . PHP_EOL,
				[
					'class' => static::class,
					'target' => $result->getTarget(),
					'operationType' => static::TYPE_ID,
				],
			);
			static::notifyTimelineAfterSuccessfulJobFinish($result);
		}
		else
		{
			static::notifyAboutJobError($result, true, false, $fillTarget);
		}

		$builder = self::constructJobFinishEventBuilder($job, $result->isSuccess());
		if ($builder)
		{
			$builder->setStatus($result->isSuccess() ? Dictionary::STATUS_SUCCESS : Dictionary::STATUS_ERROR);
			// the generic status goes first, so the operation may replace it with its own verdict
			if ($builder instanceof AIBaseEvent)
			{
				// domain enrichment is declared over the base event; an event assembled on its own
				// (@see GenerateManagerSummaryEvent) has no domain fields to add
				static::enrichJobFinishEventBuilder($builder, $result);
			}

			$builder->buildEvent()->send();
			// send the same analytics only with different TOOL and CATEGORY
			$builder
				->setTool(Dictionary::TOOL_CRM)
				->setCategory(Dictionary::CATEGORY_AI_OPERATIONS)
				->buildEvent()
				->send()
			;
		}

		AIManager::logger()->debug(
			'{date}: {class}: Job for target {hash} finished with result {result}' . PHP_EOL,
			[
				'class' => static::class,
				'hash' => $job->requireHash(),
				'result' => $result,
			],
		);

		return $result;
	}

	/**
	 * @template T of \Bitrix\Main\Result
	 *
	 * @param EO_Queue $job
	 * @param T $result
	 *
	 * @return T
	 */
	private static function saveErrorToJobAndReturnResult(EO_Queue $job, \Bitrix\Main\Result $result): \Bitrix\Main\Result
	{
		$errors = $result->getErrors();

		$errorToSave = end($errors);
		if (!($errorToSave instanceof Error))
		{
			return $result;
		}

		$job->setExecutionStatus(QueueTable::EXECUTION_STATUS_ERROR);
		$job->setErrorCode($errorToSave->getCode());
		$job->setErrorMessage($errorToSave->getMessage());

		$dbSaveResult = $job->save();
		if (!$dbSaveResult->isSuccess())
		{
			AIManager::logger()->critical(
				'{date}: {class}: Error while trying to save error info to CRM DB with hash {hash} for activity{activity}: {errors}',
				[
					'class' => static::class,
					'errors' => $dbSaveResult->getErrors(),
					'hash' => $job->requireHash(),
					'activity' => self::extractActivityIdFromJob($job),
				],
			);
		}

		$builder = self::constructJobFinishEventBuilder($job, false);
		if ($builder)
		{
			$builder->setStatus(Dictionary::STATUS_ERROR);
			$builder->buildEvent()->send();
			// send the same analytics only with different TOOL and CATEGORY
			$builder
				->setTool(Dictionary::TOOL_CRM)
				->setCategory(Dictionary::CATEGORY_AI_OPERATIONS)
				->buildEvent()
				->send()
			;
		}

		return $result;
	}

	protected static function onAfterSuccessfulJobFinish(Result $result, ?Context $context = null): void
	{
	}

	protected static function notifyAboutLimitExceededError(Result $result): void
	{
		$activityId = $result->getTarget()?->getEntityId();
		if ($activityId === null)
		{
			return;
		}

		self::syncBadges($activityId, Badge\Type\AiCallFieldsFillingResult::ERROR_LIMIT_EXCEEDED);
		static::notifyTimelinesAboutAutomationLaunchError($result);
	}

	final protected static function notifyTimelinesAboutActivityUpdate(int $activityId, bool $forceUpdateHistoryItems = false): void
	{
		$activity = CCrmActivity::GetByID($activityId, false);
		if ($activity)
		{
			ActivityController::getInstance()
				->notifyTimelinesAboutActivityUpdate($activity, (int)$activity['RESPONSIBLE_ID'], $forceUpdateHistoryItems)
			;
		}
	}

	final protected static function notifyTimelinesAboutAutomationLaunchError(
		Result $result,
		int $activityId = null,
		?ItemIdentifier $target = null
	): void
	{
		$activityId = $activityId ?? $result->getTarget()?->getEntityId();
		// Prefer the explicit job target (badge isolation per own entity, P3.T1); the auto path and
		// non-fill operations pass no target and keep resolving the priority Deal/Lead via findTarget.
		$target ??= (new TargetResolver())->findTarget($activityId);
		if ($target)
		{
			$errorCodes = array_map(static fn($error) => $error->getCode(), $result->getErrors());
			Controller::getInstance()->onAutomationLaunchError(
				$target,
				$activityId,
				[ 'ERROR_CODES' => $errorCodes ],
				$result->getUserId(),
			);
		}
	}

	final protected static function syncBadges(int $activityId, string $badgeValue = '', ?ItemIdentifier $target = null): void
	{
		// The badge is written/removed strictly for the job's own entity (P3.T1): callers with an
		// explicit target (FillFields → $result->getTarget()) isolate the status on the clicked entity.
		// Callers without a target (Transcribe/Summarize/other operations) keep resolving the single
		// priority Deal/Lead via findTarget — legacy "one target" behaviour, unchanged.
		$itemIdentifier = $target ?? (new TargetResolver())->findTarget($activityId);
		if (!$itemIdentifier)
		{
			return;
		}

		Badge\Badge::deleteByEntity($itemIdentifier, Badge\Badge::AI_FIELDS_FILLING_RESULT);
		if (!empty($badgeValue))
		{
			$badge = Container::getInstance()->getBadge(Badge\Badge::AI_FIELDS_FILLING_RESULT, $badgeValue);
			$sourceIdentifier = new Badge\SourceIdentifier(
				Badge\SourceIdentifier::CRM_OWNER_TYPE_PROVIDER,
				CCrmOwnerType::Activity,
				$activityId,
			);

			$badge->bind($itemIdentifier, $sourceIdentifier);
		}
	}

	final protected static function cleanBadgeByType(int $activityId, string $badgeType): void
	{
		$itemIdentifier = (new TargetResolver())->findTarget($activityId);
		if (!$itemIdentifier)
		{
			return;
		}

		$supportedTypes = [
			Badge\Badge::AI_FIELDS_FILLING_RESULT,
		];
		if (in_array($badgeType, $supportedTypes, true))
		{
			Badge\Badge::deleteByEntity($itemIdentifier, $badgeType);
		}
	}

	public static function onQueueJobFail(Event $event, EO_Queue $job): Result
	{
		AIManager::logger()->debug(
			'{date}: {class}: Processing event {event} for job {job}' . PHP_EOL,
			[
				'event' => $event,
				'job' => $job->collectValues(fieldsMask: FieldTypeMask::FLAT),
				'class' => static::class,
			],
		);

		$error = $event->getParameter('error');
		if (!($error instanceof Error))
		{
			$error = ErrorCode::getJobExecutionFailedError();
		}

		AIManager::logger()->critical(
			'{date}: {class}: Job {id} (hash {hash}) for activity{activity} failed because we have received error from AI queue: {error}' . PHP_EOL,
			[
				'class' => static::class,
				'id' => $job->getId(),
				'hash' => $job->requireHash(),
				'error' => $error,
				'activity' => self::extractActivityIdFromJob($job),
			],
		);

		self::saveErrorToJobAndReturnResult(
			$job,
			(new Result(static::TYPE_ID))->addError($error)
		);

		$result = static::constructResult($job);

		// Same async boundary as onQueueJobExecute: prefer the clicked entity (ERR-002/AC-030),
		// fall back to findTarget for the auto path.
		static::notifyAboutJobError($result, target: static::extractFillTargetFromEngineContext($event));

		return $result;
	}

	/**
	 * @param EO_Queue $job
	 *
	 * @return Result
	 * @throws ArgumentException
	 */
	public static function constructResult(EO_Queue $job): Result
	{
		if ((int)$job->requireTypeId() !== static::TYPE_ID)
		{
			throw new ArgumentException(static::class . ' processes only results of type ' . static::TYPE_ID);
		}

		/** @var Dto|null $payload */
		$payload = null;
		$fillFieldsEntityAllowed = null;

		if ($job->requireExecutionStatus() === QueueTable::EXECUTION_STATUS_SUCCESS)
		{
			$resultsJson = $job->requireResult();
			if (is_string($resultsJson) && $resultsJson !== '')
			{
				$resultsArray = self::decodeResult($resultsJson);
				if ($resultsArray !== null)
				{
					$fillFieldsEntityAllowed = array_key_exists('fillFieldsEntityAllowed', $resultsArray)
						? (bool)$resultsArray['fillFieldsEntityAllowed']
						: null
					;
					$payload = static::constructPayloadFromArray($resultsArray);
				}
			}
		}

		$result = new Result(
			static::TYPE_ID,
			new ItemIdentifier($job->requireEntityTypeId(), $job->requireEntityId()),
			$job->requireUserId(),
			$job->getId(),
			$job->requireExecutionStatus() === QueueTable::EXECUTION_STATUS_PENDING,
			$payload,
			$job->requireOperationStatus(),
			$job->requireParentId(),
			$job->requireRetryCount(),
			$job->requireIsManualLaunch(),
			$job->requireLanguageId(),
			$job->requireNextTypeId(),
			$fillFieldsEntityAllowed,
		);

		if ($job->requireExecutionStatus() === QueueTable::EXECUTION_STATUS_ERROR)
		{
			$result->addError(ErrorCode::getJobExecutionFailedError());
		}
		elseif ($result->getPayload() === null && !$result->isPending())
		{
			$result->addError(ErrorCode::getPayloadNotFoundError());
		}

		return $result;
	}

	public static function constructPayload(string $json): ?Dto
	{
		$resultsArray = self::decodeResult($json);

		return $resultsArray === null ? null : static::constructPayloadFromArray($resultsArray);
	}

	private static function decodeResult(string $json): ?array
	{
		if (empty($json))
		{
			return null;
		}

		try
		{
			$resultsArray = Json::decode($json);
		}
		catch (ArgumentException)
		{
			return null;
		}

		return is_array($resultsArray) ? $resultsArray : null;
	}

	private static function constructPayloadFromArray(array $resultsArray): ?Dto
	{
		unset(
			$resultsArray['targetOwnerTypeId'],
			$resultsArray['targetOwnerId'],
			$resultsArray['fillFieldsEntityAllowed'],
		);

		$class = static::PAYLOAD_CLASS;
		$payload = new $class($resultsArray);
		if ($payload->hasValidationErrors())
		{
			return null;
		}

		return $payload;
	}

	private static function encodeResultWithContextMetadata(Dto $payload, ?Context $context): string
	{
		$metadata = self::extractTargetOwnerFromContext($context);
		$additionalInfo = $context?->getParameters()['additionalInfo'] ?? null;
		if (is_array($additionalInfo) && array_key_exists('fillFieldsEntityAllowed', $additionalInfo))
		{
			$metadata['fillFieldsEntityAllowed'] = (bool)$additionalInfo['fillFieldsEntityAllowed'];
		}

		if ($metadata === [])
		{
			return Json::encode($payload, 0);
		}

		return Json::encode($payload->toArray() + $metadata, 0);
	}

	private static function extractTargetOwnerFromContext(?Context $context): array
	{
		if ($context === null)
		{
			return [];
		}

		$additionalInfo = $context->getParameters()['additionalInfo'] ?? [];
		if (
			!is_array($additionalInfo)
			|| !isset($additionalInfo['targetOwnerTypeId'], $additionalInfo['targetOwnerId'])
		)
		{
			return [];
		}

		$ownerTypeId = (int)$additionalInfo['targetOwnerTypeId'];
		$ownerId = (int)$additionalInfo['targetOwnerId'];
		if ($ownerTypeId <= 0 || $ownerId <= 0)
		{
			return [];
		}

		return [
			'targetOwnerTypeId' => $ownerTypeId,
			'targetOwnerId' => $ownerId,
		];
	}

	/**
	 * Writes the operation-launched progress line. Extension point: a subclass may override to add its
	 * own labelled progress line (calling parent first), e.g. to mark the launch source. Runs in
	 * instance context, so the override can read launch parameters set on the operation.
	 */
	protected function logOperationLaunched(string $hash, ?int $parentJobId): void
	{
		self::logOperationProgress('operationLaunched', $this->target, $hash, $parentJobId);
	}

	protected static function logOperationProgress(string $operation, ItemIdentifier $target, string $hash, ?int $parentJobId): void
	{
		if (Loader::includeModule('bitrix24'))
		{
			$logHost = Application::getInstance()->getContext()->getServer()->getHttpHost();
			$logStep = static::class;
			$logDate = (new \Bitrix\Main\Type\DateTime())->toString();
			$logTarget = self::getTargetRealId($target, $parentJobId);
			AddMessage2Log("crm.integration.AI {$logHost} {$operation} {$logDate}: started step {$logStep} with hash {$hash} for activity{$logTarget}", 'crm');
		}
	}

	protected static function getTargetRealId(ItemIdentifier $target, ?int $parentJobId): int
	{
		if ($target->getEntityTypeId() !== CCrmOwnerType::Activity)
		{
			return self::findParentJobActivityId($parentJobId);
		}

		return $target->getEntityId();
	}

	private static function findParentJobActivityId(?int $parentJobId): int
	{
		$parentJob = QueueTable::query()
			->setSelect(['ID', 'ENTITY_ID', 'ENTITY_TYPE_ID'])
			->where('ID', $parentJobId)
			->setLimit(1)
			->fetchObject()
		;

		if ($parentJob?->getEntityTypeId() === CCrmOwnerType::Activity)
		{
			return (int)$parentJob->getEntityId();
		}

		return 0;
	}

	private static function extractActivityIdFromJob(EO_Queue $job): int
	{
		return self::getTargetRealId(new ItemIdentifier($job->getEntityTypeId(), $job->getEntityId()), $job->getParentId());
	}

	private static function extractActivityIdFromResult(Result $result): int
	{
		return self::getTargetRealId($result->getTarget(), $result->getParentJobId());
	}

	/**
	 * Recovers the manual FillFields target (the clicked entity) from the engine context of a finished job.
	 * Rides the same `additionalInfo` channel as `scenario` (@see self::getContextAdditionalInfo where it is
	 * written), so a fresh manual launch keeps badging exactly the clicked entity across the async
	 * Transcribe/Summarize→FillFields boundary (ALG-01, single-target). Null for the auto path.
	 *
	 * Single source of truth: also reused by EventHandler::extractFillTargetFromEngineContext.
	 */
	public static function extractFillTargetFromEngineContext(Event $event): ?ItemIdentifier
	{
		$engine = $event->getParameter('engine');
		if (!($engine instanceof IEngine))
		{
			return null;
		}

		$fillTarget = $engine->getContext()->getParameters()['additionalInfo']['fillTarget'] ?? null;
		if (!is_array($fillTarget))
		{
			return null;
		}

		return ItemIdentifier::createByParams(
			(int)($fillTarget['entityTypeId'] ?? 0),
			(int)($fillTarget['entityId'] ?? 0),
		);
	}

	final protected static function sendCallParsingAnalyticsEvent(
		Result $result,
		int $activityId,
		?int $totalScenarioDuration = null
	): void
	{
		$activity = Container::getInstance()->getActivityBroker()->getById($activityId);
		if (!$activity)
		{
			return;
		}

		$owner = Container::getInstance()->getActivityBroker()->getOwner($activityId);
		if (!$owner)
		{
			return;
		}

		$direction = (int)($activity['DIRECTION'] ?? 0);

		$builder = (new CallParsingEvent())
			->setIsManualLaunch($result->isManualLaunch())
			->setActivityOwnerTypeId($owner->getEntityTypeId())
			->setActivityId($activityId)
			->setActivityDirection($direction <= 0 ? CCrmActivityDirection::Incoming : $direction)
			->setTotalScenarioDuration($totalScenarioDuration)
			->setElement(Dictionary::ELEMENT_COPILOT_BUTTON)
			->setStatus(CallParsingEvent::resolveStatusByJobResult($result))
		;
		$builder->buildEvent()->send();
		// send the same analytics only with different TOOL and CATEGORY
		$builder
			->setTool(Dictionary::TOOL_CRM)
			->setCategory(Dictionary::CATEGORY_AI_OPERATIONS)
			->buildEvent()
			->send()
		;
	}

	final protected static function extractPayloadPrettifiedData(\Bitrix\AI\Result $result): array
	{
		try
		{
			$prettifiedData = $result->getPrettifiedData() ?: '';
			$prettifiedData = trim($prettifiedData);

			$startPos = strpos($prettifiedData, '{');
			$endPos = strrpos($prettifiedData, '}');

			if ($startPos === false || $endPos === false)
			{
				return [];
			}

			$prettifiedData = substr($prettifiedData, $startPos, $endPos - $startPos + 1);
			$json = Json::decode($prettifiedData);
		}
		catch (ArgumentException)
		{
			$json = [];
		}

		if (!is_array($json))
		{
			$json = [];
		}

		return $json;
	}

	final protected static function extractPayloadString(mixed $input): ?string
	{
		$result = null;
		if (!empty($input))
		{
			$result = is_array($input)
				? implode(PHP_EOL, $input)
				: (string)$input
			;
		}

		return $result;
	}

	final protected static function stripUnresolvedMarkers(?string $value): ?string
	{
		if ($value === null)
		{
			return null;
		}

		$cleaned = trim(preg_replace('/\{\{\s*[A-Za-z0-9_]+\s*\}\}/', '', $value));

		return $cleaned === '' ? null : $cleaned;
	}

	/**
	 * What the operation actually ran on. For a call based operation it is the call activity - either the
	 * job target itself or the one taken from the parent job; for any other operation it is the job target
	 * as is, and then there is no provider to report.
	 *
	 * Read from the job and not from the domain result: the error path builds the event from a generic
	 * Result that may carry no target at all.
	 *
	 * @return array{id: int, typeId: int, provider: ?string}|null Null when there is nothing real to report on.
	 */
	private static function resolveAnalyticsTarget(EO_Queue $job): ?array
	{
		$entityTypeId = (int)$job->getEntityTypeId();
		$entityId = (int)$job->getEntityId();

		$activityId = $entityTypeId === CCrmOwnerType::Activity
			? $entityId
			: self::findParentJobActivityId($job->getParentId())
		;

		if ($activityId > 0)
		{
			$broker = Container::getInstance()->getActivityBroker();
			$owner = $broker->getOwner($activityId);
			$provider = $broker->getById($activityId)['PROVIDER_ID'] ?? null;
			if (!$owner || !$provider)
			{
				return null;
			}

			return [
				'id' => $activityId,
				'typeId' => $owner->getEntityTypeId(),
				'provider' => (string)$provider,
			];
		}

		if ($entityId <= 0)
		{
			return null;
		}

		return [
			'id' => $entityId,
			'typeId' => $entityTypeId,
			'provider' => null,
		];
	}

	private static function constructJobFinishEventBuilder(
		EO_Queue $job,
		bool $isSuccessfulResult = true,
	): ?JobFinishEventContract
	{
		if (in_array(static::TYPE_ID, self::EXCLUDED_OPERATION_TYPES, true))
		{
			AIManager::logger()->info(
				'{date}: {class}: Skipping analytics because this operation type never reports'
				. ' its job finish: {job}',
				[
					'job' => self::collectJobIdsForLog($job),
					'class' => static::class,
				]
			);

			return null;
		}

		if (
			$isSuccessfulResult
			&& in_array(static::TYPE_ID, self::SUCCESS_EXCLUDED_OPERATION_TYPES, true)
		)
		{
			AIManager::logger()->info(
				'{date}: {class}: Skipping common analytics because this operation reports'
				. ' its successful job finish through a dedicated event: {job}',
				[
					'job' => self::collectJobIdsForLog($job),
					'class' => static::class,
				],
			);

			return null;
		}

		if (static::hasSyntheticAnalyticsTarget($job))
		{
			AIManager::logger()->info(
				'{date}: {class}: Skipping analytics because this job ran on a synthetic target'
				. ' with no real entity behind its id: {job}',
				[
					'job' => self::collectJobIdsForLog($job),
					'class' => static::class,
				]
			);

			return null;
		}

		$target = self::resolveAnalyticsTarget($job);
		if (!$target)
		{
			AIManager::logger()->info(
				'{date}: {class}: Skipping analytics because job has no analytics target with a real id'
				. ' - either its call activity is gone or the job target id is empty: {job}',
				[
					'job' => self::collectJobIdsForLog($job),
					'class' => static::class,
				]
			);

			return null;
		}

		if (!$job->requireFinishedTime())
		{
			AIManager::logger()->info(
				'{date}: {class}: Skipping analytics because job doesnt have finishedTime set.'
				. ' May be this job was created before the update: {job}',
				[
					'job' => self::collectJobIdsForLog($job),
					'class' => static::class,
				]
			);

			return null;
		}

		$builder = static::getJobFinishEventBuilder()
			->setOperationType(static::TYPE_ID)
			->setCreatedTime($job->requireCreatedTime())
			->setFinishedTime($job->requireFinishedTime())
			->setIsManualLaunch($job->requireIsManualLaunch())
			->setTargetTypeId($target['typeId'])
			->setTargetId($target['id'])
		;

		if ($target['provider'] !== null)
		{
			$builder->setTargetProvider($target['provider']);
		}

		return $builder;
	}

	/**
	 * A closed list of job fields for a refusal log entry. The job also carries RESULT - the AI answer
	 * itself - and ERROR_MESSAGE, and neither of them belongs in the log at info level.
	 */
	private static function collectJobIdsForLog(EO_Queue $job): array
	{
		$loggableFields = [
			'ID',
			'TYPE_ID',
			'ENTITY_TYPE_ID',
			'ENTITY_ID',
			'PARENT_ID',
			'HASH',
			'EXECUTION_STATUS',
			'CREATED_TIME',
			'FINISHED_TIME',
		];

		return array_intersect_key(
			$job->collectValues(fieldsMask: FieldTypeMask::FLAT),
			array_flip($loggableFields),
		);
	}

	/**
	 * Whether this very job ran on a synthetic target - an id passed only to satisfy the queue, with no
	 * real entity behind it. Unlike self::EXCLUDED_OPERATION_TYPES, where the whole operation stays
	 * silent, the operation keeps reporting its other jobs.
	 *
	 * @see self::constructJobFinishEventBuilder
	 */
	protected static function hasSyntheticAnalyticsTarget(EO_Queue $job): bool
	{
		return false;
	}

	/**
	 * Lets the operation add its own domain fields to the assembled job finish event - a status of its
	 * own semantics and extra params. Called only on a successful job finish, where the domain result
	 * exists, and after the generic status is set, so the operation is free to override it.
	 *
	 * The error path builds its event from a generic Result with no domain payload and never calls this.
	 * Whether the event is sent at all stays with the general flow - the hook cannot cancel the sending.
	 */
	protected static function enrichJobFinishEventBuilder(AIBaseEvent $builder, Result $result): void
	{
	}

	protected static function setQuality(Engine $engine): void
	{
	}

	// @todo: refactor
	abstract protected static function notifyTimelineAfterSuccessfulLaunch(Result $result): void;
	abstract protected static function notifyTimelineAfterSuccessfulJobFinish(Result $result): void;
	abstract protected static function notifyAboutJobError(Result $result, bool $withSyncBadges = true, bool $withSendAnalytics = true, ?ItemIdentifier $target = null): void;
	abstract protected static function extractPayloadFromAIResult(\Bitrix\AI\Result $result, EO_Queue $job): Dto;
	abstract protected static function getJobFinishEventBuilder(): JobFinishEventContract;
}
