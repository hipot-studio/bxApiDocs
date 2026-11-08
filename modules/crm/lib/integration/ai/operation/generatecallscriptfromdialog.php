<?php

namespace Bitrix\Crm\Integration\AI\Operation;

use Bitrix\AI\Context;
use Bitrix\AI\Engine\IEngine;
use Bitrix\AI\Payload\IPayload;
use Bitrix\Crm\Copilot\CallAssessment\Analytics\CreateScriptEvent;
use Bitrix\Crm\Copilot\CallAssessment\CallAssessmentItem;
use Bitrix\Crm\Copilot\CallAssessment\Controller\CopilotCallAssessmentController;
use Bitrix\Crm\Copilot\CallAssessment\Entity\CopilotCallAssessmentTable;
use Bitrix\Crm\Copilot\PullManager;
use Bitrix\Crm\Dto\Dto;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\Dto\Scoring\CallScriptCreatorPayload;
use Bitrix\Crm\Integration\AI\ErrorCode;
use Bitrix\Crm\Integration\AI\EventHandler;
use Bitrix\Crm\Integration\AI\Model\EO_Queue;
use Bitrix\Crm\Integration\AI\Model\QueueTable;
use Bitrix\Crm\Integration\AI\Operation\Handler\CallScriptCreatorHandler;
use Bitrix\Crm\Integration\AI\Operation\Payload\PayloadFactory;
use Bitrix\Crm\Integration\AI\Result;
use Bitrix\Crm\Integration\Analytics\Builder\AI\AIBaseEvent;
use Bitrix\Crm\Integration\Analytics\Builder\AI\GenerateCallScriptFromDialogEvent;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Service\Container;
use Bitrix\Main;
use Bitrix\Main\ORM\Data\AddResult;
use Bitrix\Main\ORM\Data\UpdateResult;
use CCrmOwnerType;

final class GenerateCallScriptFromDialog extends AbstractOperation
{
	public const TYPE_ID = 13;
	public const CONTEXT_ID = 'generate_call_script_from_dialog';

	protected const PAYLOAD_CLASS = CallScriptCreatorPayload::class;
	protected const ENGINE_CODE = EventHandler::SETTINGS_CALL_ASSESSMENT_ENGINE_CODE;
	private const MAX_RETRY_COUNT = 2;
	private const EVENT_LOCK_NAME_PREFIX = 'crm_ai_generate_call_script_from_dialog_';
	private const EVENT_LOCK_TIMEOUT = 1;
	private static int $retryLaunchDepth = 0;
	private static ?\WeakMap $payloadByAIResult = null;

	private string $userText = '';
	private ?IPayload $retryPayload = null;
	private ?int $expectedPreviousJobId = null;
	private ?string $expectedPreviousJobHash = null;
	private ?int $expectedPreviousRetryCount = null;
	private ?string $retryErrorCode = null;
	private ?string $retryErrorMessage = null;
	private ?array $retryContextAdditionalInfo = null;
	private int $attemptNumber = 1;

	public function __construct(
		int $assessmentId,
		?int $userId = null,
		?int $parentJobId = null,
		private readonly ?string $retryLanguageId = null,
	)
	{
		parent::__construct(
			new ItemIdentifier(CCrmOwnerType::CopilotCallAssessment, $assessmentId),
			$userId,
			$parentJobId,
		);
	}

	public static function isAccessGranted(int $userId, ItemIdentifier $target): bool
	{
		if ($userId <= 0)
		{
			return false;
		}

		return Container::getInstance()->getUserPermissions($userId)->copilotCallAssessment()->canEdit();
	}

	public static function isSuitableTarget(ItemIdentifier $target): bool
	{
		return $target->getEntityTypeId() === CCrmOwnerType::CopilotCallAssessment
			&& $target->getEntityId() > 0
		;
	}

	public function setUserText(string $text): self
	{
		$this->userText = $text;

		return $this;
	}

	protected static function checkPreviousJobs(ItemIdentifier $target, int $parentId): Main\Result
	{
		$result = new Main\Result();
		$previousJob = self::findLatestJob($target);
		if (!$previousJob)
		{
			return $result;
		}

		$result->setData(['previousJob' => $previousJob]);
		if (
			$previousJob->requireExecutionStatus() === QueueTable::EXECUTION_STATUS_ERROR
			&& $previousJob->requireRetryCount() >= self::MAX_RETRY_COUNT
		)
		{
			return $result->addError(ErrorCode::getJobMaxRetriesExceededError());
		}

		return $result->addError(ErrorCode::getJobAlreadyExistsError());
	}

	protected function checkPreviousJobsBeforeLaunch(): Main\Result
	{
		if ($this->expectedPreviousJobId === null || $this->expectedPreviousJobHash === null)
		{
			$pool = Main\Application::getInstance()->getConnectionPool();
			$pool->useMasterOnly(true);
			try
			{
				return parent::checkPreviousJobsBeforeLaunch();
			}
			finally
			{
				$pool->useMasterOnly(false);
			}
		}

		$job = self::findJobOnPrimary($this->expectedPreviousJobId);
		$result = new Main\Result();
		if (
			$job
			&& $job->requireHash() === $this->expectedPreviousJobHash
			&& $job->requireExecutionStatus() === QueueTable::EXECUTION_STATUS_ERROR
			&& $job->requireRetryCount() < self::MAX_RETRY_COUNT
		)
		{
			$result->setData(['previousJob' => $job]);

			return $result;
		}

		return $result->addError(ErrorCode::getJobAlreadyExistsError());
	}

	protected function getAIPayload(): Main\Result
	{
		if ($this->retryPayload !== null)
		{
			return (new Main\Result())->setData(['payload' => $this->retryPayload]);
		}

		$additionalData = [
			'userText' => $this->userText,
			'assessmentSettingsId' => $this->target->getEntityId(),
		];

		return PayloadFactory::build(self::TYPE_ID, $this->userId, $this->target)
			->setAdditionalData($additionalData)
			->setMarkers([])
			->getResult()
		;
	}

	protected function getJobUpdateFields(): array
	{
		$fields = parent::getJobUpdateFields();
		$fields['FINISHED_TIME'] = null;
		$fields['RESULT'] = null;
		$fields['ERROR_CODE'] = $this->retryErrorCode;
		$fields['ERROR_MESSAGE'] = $this->retryErrorMessage;

		return $fields;
	}

	protected function getContextAdditionalInfo(): array
	{
		$additionalInfo = $this->retryContextAdditionalInfo ?? parent::getContextAdditionalInfo();
		$additionalInfo['callScriptAttempt'] = $this->attemptNumber;
		if ($this->expectedPreviousJobId !== null && $this->expectedPreviousJobHash !== null)
		{
			$additionalInfo['jobId'] = $this->expectedPreviousJobId;
			$additionalInfo['previousHash'] = $this->expectedPreviousJobHash;
		}

		return $additionalInfo;
	}

	protected function getContextLanguageId(): string
	{
		return $this->retryLanguageId ?? parent::getContextLanguageId();
	}

	public static function onQueueJobFail(Main\Event $event, EO_Queue $job): Result
	{
		$connection = Main\Application::getConnection();
		$lockName = self::getEventLockName($job);
		self::lockEvent($connection, $lockName);

		try
		{
			$currentJob = self::findJobOnPrimary($job->getId());
			if (!$currentJob || !self::isCurrentPendingAttempt($event, $currentJob))
			{
				return self::constructCurrentResult($currentJob ?? $job);
			}

			return parent::onQueueJobFail($event, $currentJob);
		}
		finally
		{
			$connection->unlock($lockName);
		}
	}

	public static function onQueueJobExecute(Main\Event $event, EO_Queue $job): Result
	{
		$connection = Main\Application::getConnection();
		$lockName = self::getEventLockName($job);
		$retryOperation = null;
		$retryJobId = null;
		$retryJobHash = null;
		$retryCount = null;
		$retryLaunchError = null;
		$failedResult = null;
		self::lockEvent($connection, $lockName);

		try
		{
			$currentJob = self::findJobOnPrimary($job->getId());
			if (!$currentJob || !self::isCurrentPendingAttempt($event, $currentJob))
			{
				return self::constructCurrentResult($currentJob ?? $job);
			}

			$currentJob->setErrorCode(null);
			$currentJob->setErrorMessage(null);
			$eventForProcessing = self::getEventWithoutSensitiveAIResponse($event, $currentJob);
			$result = parent::onQueueJobExecute($eventForProcessing, $currentJob);
			$processedJob = $currentJob;

			if ($processedJob->requireExecutionStatus() === QueueTable::EXECUTION_STATUS_SUCCESS)
			{
				return $result;
			}

			if (!self::isLocalResponseError($processedJob->getErrorCode()))
			{
				return $result;
			}

			if ($processedJob->requireRetryCount() >= self::MAX_RETRY_COUNT)
			{
				return $result;
			}

			$retryOperation = self::createRetryOperation($event, $processedJob);
			if ($retryOperation === null)
			{
				$retryLaunchError = new Result(self::TYPE_ID);
				$retryLaunchError->addError(ErrorCode::getPayloadNotFoundError());

				$failedResult = self::saveRetryLaunchError($processedJob, $retryLaunchError);
			}
			else
			{
				$retryJobId = $processedJob->getId();
				$retryJobHash = $processedJob->requireHash();
				$retryCount = $processedJob->requireRetryCount();
			}
		}
		finally
		{
			$connection->unlock($lockName);
		}

		if ($retryOperation === null)
		{
			if ($failedResult)
			{
				self::notifyAboutJobError($failedResult);

				return $failedResult;
			}

			return $retryLaunchError ?? new Result(self::TYPE_ID);
		}

		self::$retryLaunchDepth++;
		try
		{
			$retryResult = $retryOperation->launch();
		}
		catch (\Throwable $throwable)
		{
			AIManager::logger()->critical(
				'{date}: {class}: Retry launch threw {exceptionClass}' . PHP_EOL,
				['class' => self::class, 'exceptionClass' => $throwable::class],
			);

			$retryResult = new Result(self::TYPE_ID);
			$retryResult->addError(ErrorCode::getJobExecutionFailedError());
		}
		finally
		{
			self::$retryLaunchDepth--;
		}
		if ($retryResult->isSuccess())
		{
			return self::constructCurrentResult($retryJobId);
		}

		self::lockEvent($connection, $lockName);

		try
		{
			$currentJob = self::findJobOnPrimary($retryJobId);
			if (!$currentJob)
			{
				return self::constructCurrentResult($retryJobId);
			}

			$isUnclaimedAttempt = $currentJob->requireHash() === $retryJobHash
				&& $currentJob->requireExecutionStatus() === QueueTable::EXECUTION_STATUS_ERROR
				&& $currentJob->requireRetryCount() === $retryCount
			;
			$isClaimedAttempt = $currentJob->requireHash() === $retryJobHash
				&& $currentJob->requireExecutionStatus() === QueueTable::EXECUTION_STATUS_PENDING
				&& $currentJob->requireRetryCount() === $retryCount + 1
			;
			if (!$isUnclaimedAttempt && !$isClaimedAttempt)
			{
				return self::constructCurrentResult($currentJob);
			}

			$failedResult = self::saveRetryLaunchError($currentJob, $retryResult);
		}
		finally
		{
			$connection->unlock($lockName);
		}

		if ($failedResult)
		{
			self::notifyAboutJobError($failedResult);

			return $failedResult;
		}

		return $retryResult;
	}

	private static function findLatestJob(ItemIdentifier $target): ?EO_Queue
	{
		return QueueTable::query()
			->setSelect(['ID', 'HASH', 'EXECUTION_STATUS', 'RETRY_COUNT'])
			->where('ENTITY_TYPE_ID', $target->getEntityTypeId())
			->where('ENTITY_ID', $target->getEntityId())
			->where('TYPE_ID', self::TYPE_ID)
			->setOrder(['ID' => 'DESC'])
			->setLimit(1)
			->fetchObject()
		;
	}

	private static function findJobOnPrimary(int $jobId): ?EO_Queue
	{
		$pool = Main\Application::getInstance()->getConnectionPool();
		$pool->useMasterOnly(true);
		try
		{
			return QueueTable::query()
				->setSelect(['*'])
				->where('ID', $jobId)
				->setLimit(1)
				->fetchObject()
			;
		}
		finally
		{
			$pool->useMasterOnly(false);
		}
	}

	private static function constructCurrentResult(EO_Queue|int $job): Result
	{
		if (is_int($job))
		{
			$job = self::findJobOnPrimary($job);
		}

		return $job instanceof EO_Queue ? self::constructResult($job) : new Result(self::TYPE_ID);
	}

	public static function getEventLockNameForTarget(ItemIdentifier $target): string
	{
		return self::EVENT_LOCK_NAME_PREFIX
			. self::TYPE_ID
			. '_'
			. $target->getEntityTypeId()
			. '_'
			. $target->getEntityId();
	}

	private static function getEventLockName(EO_Queue $job): string
	{
		return self::getEventLockNameForTarget(new ItemIdentifier(
			$job->requireEntityTypeId(),
			$job->requireEntityId(),
		));
	}

	private static function lockEvent(Main\DB\Connection $connection, string $lockName): void
	{
		while (!$connection->lock($lockName, self::EVENT_LOCK_TIMEOUT))
		{
		}
	}

	private static function isLocalResponseError(?string $code): bool
	{
		return in_array($code, [
			ErrorCode::CALL_SCRIPT_EMPTY_RESPONSE,
			ErrorCode::CALL_SCRIPT_JSON_NOT_FOUND,
			ErrorCode::CALL_SCRIPT_INVALID_JSON,
			ErrorCode::CALL_SCRIPT_NO_VALID_CRITERIA,
		], true);
	}

	private static function isCurrentPendingAttempt(Main\Event $event, EO_Queue $job): bool
	{
		$eventHash = $event->getParameter('queue');
		if (
			!is_string($eventHash)
			|| $job->requireHash() !== $eventHash
			|| $job->requireExecutionStatus() !== QueueTable::EXECUTION_STATUS_PENDING
		)
		{
			return false;
		}

		$engine = $event->getParameter('engine');
		if (!$engine instanceof IEngine || $engine->getContext()->getContextId() !== self::CONTEXT_ID)
		{
			return false;
		}

		$parameters = $engine->getContext()->getParameters();
		$additionalInfo = is_array($parameters) ? ($parameters['additionalInfo'] ?? null) : null;
		if (!is_array($additionalInfo) || !array_key_exists('callScriptAttempt', $additionalInfo))
		{
			return $job->requireRetryCount() === 0;
		}

		$attempt = $additionalInfo['callScriptAttempt'];

		return is_int($attempt) && $attempt === $job->requireRetryCount() + 1;
	}

	private static function getEventWithoutSensitiveAIResponse(Main\Event $event, EO_Queue $job): Main\Event
	{
		$aiResult = $event->getParameter('result');
		if (!$aiResult instanceof \Bitrix\AI\Result)
		{
			return $event;
		}

		$payload = self::extractPayloadFromAIResult($aiResult, $job);
		if (!$payload->hasValidationErrors())
		{
			self::$payloadByAIResult ??= new \WeakMap();
			self::$payloadByAIResult[$aiResult] = $payload;

			return $event;
		}

		$safeAIResult = new \Bitrix\AI\Result(rawData: null, prettifyData: null);
		self::$payloadByAIResult ??= new \WeakMap();
		self::$payloadByAIResult[$safeAIResult] = $payload;

		$eventWithoutSensitiveResponse = clone $event;
		$eventWithoutSensitiveResponse->setParameter('result', $safeAIResult);

		return $eventWithoutSensitiveResponse;
	}

	private static function hasLocalResponseError(Result $result): bool
	{
		foreach ($result->getErrors() as $error)
		{
			if (self::isLocalResponseError((string)$error->getCode()))
			{
				return true;
			}
		}

		return false;
	}

	private static function createRetryOperation(Main\Event $event, EO_Queue $job): ?self
	{
		$engine = $event->getParameter('engine');
		if (!($engine instanceof IEngine))
		{
			return null;
		}

		$payload = $engine->getPayload();
		if (!($payload instanceof IPayload))
		{
			return null;
		}

		$payloadClass = $payload::class;
		$restoredPayload = $payloadClass::unpack($payload->pack());
		if (!($restoredPayload instanceof IPayload))
		{
			return null;
		}

		$operation = new self(
			$job->requireEntityId(),
			$job->requireUserId(),
			$job->requireParentId(),
			$job->getLanguageId(),
		);
		$operation->retryPayload = $restoredPayload;
		$operation->expectedPreviousJobId = $job->getId();
		$operation->expectedPreviousJobHash = $job->requireHash();
		$operation->expectedPreviousRetryCount = $job->requireRetryCount();
		$operation->retryErrorCode = $job->getErrorCode();
		$operation->retryErrorMessage = $job->getErrorMessage();
		$contextAdditionalInfo = $engine->getContext()->getParameters()['additionalInfo'] ?? null;
		$operation->retryContextAdditionalInfo = is_array($contextAdditionalInfo) ? $contextAdditionalInfo : null;
		$operation->attemptNumber = $job->requireRetryCount() + 2;
		$operation->setIsManualLaunch($job->requireIsManualLaunch());

		return $operation;
	}

	protected function beforeLaunchDispatch(?EO_Queue $previousJob): Main\Result
	{
		$result = new Main\Result();
		if (
			$this->expectedPreviousJobId === null
			|| $this->expectedPreviousJobHash === null
			|| $this->expectedPreviousRetryCount === null
		)
		{
			return $result;
		}

		$connection = Main\Application::getConnection();
		$lockName = self::getEventLockNameForTarget($this->target);
		self::lockEvent($connection, $lockName);
		try
		{
			$currentJob = self::findJobOnPrimary($this->expectedPreviousJobId);
			if (
				!$currentJob
				|| $currentJob->requireHash() !== $this->expectedPreviousJobHash
				|| $currentJob->requireExecutionStatus() !== QueueTable::EXECUTION_STATUS_ERROR
				|| $currentJob->requireRetryCount() !== $this->expectedPreviousRetryCount
			)
			{
				return $result->addError(ErrorCode::getJobAlreadyExistsError());
			}

			$saveResult = QueueTable::update($currentJob->getId(), [
				'EXECUTION_STATUS' => QueueTable::EXECUTION_STATUS_PENDING,
				'RETRY_COUNT' => $this->expectedPreviousRetryCount + 1,
				'FINISHED_TIME' => null,
				'RESULT' => null,
			]);
			if (!$saveResult->isSuccess())
			{
				$result->addErrors($saveResult->getErrors());
			}

			return $result;
		}
		finally
		{
			$connection->unlock($lockName);
		}
	}

	protected function saveLaunchResult(?string $hash, ?EO_Queue $previousJob): AddResult|UpdateResult
	{
		if (
			$this->expectedPreviousJobId === null
			|| $this->expectedPreviousJobHash === null
			|| $this->expectedPreviousRetryCount === null
		)
		{
			return parent::saveLaunchResult($hash, $previousJob);
		}

		$connection = Main\Application::getConnection();
		$lockName = self::getEventLockNameForTarget($this->target);
		self::lockEvent($connection, $lockName);
		try
		{
			$currentJob = self::findJobOnPrimary($this->expectedPreviousJobId);
			if (!$currentJob)
			{
				return self::createRefusedUpdateResult();
			}

			$claimedRetryCount = $this->expectedPreviousRetryCount + 1;
			if (
				$currentJob->requireHash() === $this->expectedPreviousJobHash
				&& $currentJob->requireExecutionStatus() === QueueTable::EXECUTION_STATUS_PENDING
				&& $currentJob->requireRetryCount() === $claimedRetryCount
			)
			{
				return QueueTable::update($currentJob->getId(), ['HASH' => $hash]);
			}

			if (
				$currentJob->requireHash() === $hash
				|| $currentJob->requireRetryCount() > $claimedRetryCount
			)
			{
				return self::createSuccessfulUpdateResult($currentJob->getId());
			}

			return self::createRefusedUpdateResult();
		}
		finally
		{
			$connection->unlock($lockName);
		}
	}

	private static function createSuccessfulUpdateResult(int $jobId): UpdateResult
	{
		$result = new UpdateResult();
		$result->setPrimary(['ID' => $jobId]);

		return $result;
	}

	private static function createRefusedUpdateResult(): UpdateResult
	{
		$result = new UpdateResult();
		$result->addError(ErrorCode::getJobAlreadyExistsError());

		return $result;
	}

	private static function saveRetryLaunchError(EO_Queue $job, Result $launchResult): ?Result
	{
		$errors = $launchResult->getErrors();
		$error = end($errors);
		if (!($error instanceof Main\Error))
		{
			return null;
		}

		$saveResult = QueueTable::update($job->getId(), [
			'EXECUTION_STATUS' => QueueTable::EXECUTION_STATUS_ERROR,
			'ERROR_CODE' => $error->getCode(),
			'ERROR_MESSAGE' => $error->getMessage(),
			'RESULT' => null,
		]);
		if (!$saveResult->isSuccess())
		{
			$launchResult->addErrors($saveResult->getErrors());

			return null;
		}

		$failedJob = self::findJobOnPrimary($job->getId());
		if (!$failedJob)
		{
			return null;
		}

		return self::constructResult($failedJob);
	}

	// region notify
	protected static function notifyTimelineAfterSuccessfulLaunch(Result $result): void
	{
	}

	protected static function notifyTimelineAfterSuccessfulJobFinish(Result $result): void
	{
	}

	protected static function notifyAboutJobError(
		Result $result,
		bool $withSyncBadges = true,
		bool $withSendAnalytics = true,
		?ItemIdentifier $target = null,
	): void
	{
		AIManager::logger()->error(
			'{date}: {class}: Job error on target: {target}' . PHP_EOL,
			[
				'class' => self::class,
				'target' => $result->getTarget(),
			],
		);

		if (self::$retryLaunchDepth > 0 && $result->getJobId() === null)
		{
			return;
		}

		$retryCount = $result->getRetryCount();
		if (self::hasLocalResponseError($result) && $retryCount !== null && $retryCount < self::MAX_RETRY_COUNT)
		{
			return;
		}

		$assessmentId = (int)($result->getTarget()?->getEntityId() ?? 0);
		if ($assessmentId <= 0)
		{
			return;
		}

		$isGenerating = CopilotCallAssessmentTable::query()
			->setSelect(['ID'])
			->where('ID', $assessmentId)
			->where('STATUS', CallAssessmentItem::STATUS_GENERATING_FROM_DIALOG)
			->fetch()
		;
		if (!$isGenerating)
		{
			return;
		}

		$deleteResult = CopilotCallAssessmentController::getInstance()->delete($assessmentId);
		if ($deleteResult === null || !$deleteResult->isSuccess())
		{
			return;
		}

		$isStillGenerating = CopilotCallAssessmentTable::query()
			->setSelect(['ID'])
			->where('ID', $assessmentId)
			->where('STATUS', CallAssessmentItem::STATUS_GENERATING_FROM_DIALOG)
			->fetch()
		;
		if ($isStillGenerating || !self::findJobOnPrimary((int)$result->getJobId()))
		{
			return;
		}

		(new PullManager())->sendCreateCompletePullEvent((int)$result->getUserId(), $assessmentId);
	}
	// endregion

	protected static function extractPayloadFromAIResult(\Bitrix\AI\Result $result, EO_Queue $job): Dto
	{
		if (isset(self::$payloadByAIResult[$result]))
		{
			return self::$payloadByAIResult[$result];
		}

		$response = $result->getPrettifiedData();
		if ($response === null || trim($response) === '')
		{
			return self::createInvalidPayload(ErrorCode::CALL_SCRIPT_EMPTY_RESPONSE);
		}

		$firstBracePosition = strpos($response, '{');
		$lastBracePosition = strrpos($response, '}');
		if ($firstBracePosition === false)
		{
			return self::createInvalidPayload(ErrorCode::CALL_SCRIPT_JSON_NOT_FOUND);
		}
		if ($lastBracePosition === false || $lastBracePosition < $firstBracePosition)
		{
			return self::createInvalidPayload(ErrorCode::CALL_SCRIPT_INVALID_JSON);
		}

		try
		{
			$json = Main\Web\Json::decode(substr(
				$response,
				$firstBracePosition,
				$lastBracePosition - $firstBracePosition + 1,
			));
		}
		catch (Main\ArgumentException)
		{
			return self::createInvalidPayload(ErrorCode::CALL_SCRIPT_INVALID_JSON);
		}

		$payload = new CallScriptCreatorPayload([
			'name' => is_string($json['name'] ?? null) ? $json['name'] : null,
			'description' => is_string($json['description'] ?? null) ? $json['description'] : null,
			'newCriteria' => self::filterCriteria($json['new_criteria'] ?? []),
			'removedCriteria' => array_values(array_filter(
				(array)($json['removed_criteria'] ?? []),
				static fn ($item): bool => is_string($item) && $item !== '',
			)),
			'updatedCriteria' => self::filterCriteria($json['updated_criteria'] ?? []),
		]);

		if ($payload->newCriteria === [])
		{
			return self::createInvalidPayload(ErrorCode::CALL_SCRIPT_NO_VALID_CRITERIA);
		}

		return $payload;
	}

	private static function createInvalidPayload(string $errorCode): CallScriptCreatorPayload
	{
		$payload = new CallScriptCreatorPayload([]);
		$payload->getValidationErrors()->setError(ErrorCode::getCallScriptResponseError($errorCode));

		return $payload;
	}

	private static function filterCriteria(mixed $rows): array
	{
		if (!is_array($rows))
		{
			return [];
		}

		$result = [];
		foreach ($rows as $row)
		{
			if (!is_array($row))
			{
				continue;
			}

			$name = $row['name'] ?? null;
			$description = $row['description'] ?? null;
			if (
				!is_string($name)
				|| !is_string($description)
				|| trim($name) === ''
				|| trim($description) === ''
			)
			{
				continue;
			}

			$result[] = [
				'name' => $name,
				'description' => $description,
			];
		}

		return $result;
	}

	protected static function getJobFinishEventBuilder(): AIBaseEvent
	{
		return new GenerateCallScriptFromDialogEvent();
	}

	protected static function onAfterSuccessfulJobFinish(Result $result, ?Context $context = null): void
	{
		/** @var CallScriptCreatorPayload $payload */
		$payload = $result->getPayload();
		if (!$payload || !$result->isSuccess())
		{
			AIManager::logger()->error(
				'{date}: {class}: Error in generate call script job: {target}' . PHP_EOL,
				[
					'class' => self::class,
					'target' => $result->getTarget(),
				],
			);

			return;
		}

		$assessmentId = (int)($result->getTarget()?->getEntityId() ?? 0);
		if ($assessmentId <= 0)
		{
			AIManager::logger()->error(
				'{date}: {class}: Empty assessment target on success: {target}' . PHP_EOL,
				[
					'class' => self::class,
					'target' => $result->getTarget(),
				],
			);

			return;
		}

		$userId = $result->getUserId() ?? 0;
		$handler = new CallScriptCreatorHandler($payload, $assessmentId, $userId);
		if (!$handler->enrichScript())
		{
			return;
		}

		(new CreateScriptEvent())
			->setCallAssessmentId($assessmentId)
			->setIsManualLaunch(true)
			->buildEvent()
			->send()
		;
	}
}
