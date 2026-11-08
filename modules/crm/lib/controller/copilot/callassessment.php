<?php

namespace Bitrix\Crm\Controller\Copilot;

use Bitrix\Crm\Controller\Base;
use Bitrix\Crm\Controller\ErrorCode;
use Bitrix\Crm\Copilot\CallAssessment\Analytics\CreateScriptEvent;
use Bitrix\Crm\Copilot\CallAssessment\Analytics\CriteriaGenEvent;
use Bitrix\Crm\Copilot\CallAssessment\CallAssessmentItem;
use Bitrix\Crm\Copilot\CallAssessment\Controller\CopilotCallAssessmentController;
use Bitrix\Crm\Copilot\CallAssessment\Controller\CopilotCallAssessmentCriteriaController;
use Bitrix\Crm\Copilot\CallAssessment\CriteriaWriter;
use Bitrix\Crm\Copilot\CallAssessment\Dto\ScriptStructureDto;
use Bitrix\Crm\Copilot\CallAssessment\Entity\CopilotCallAssessment;
use Bitrix\Crm\Copilot\CallAssessment\Enum\AutoCheckType;
use Bitrix\Crm\Copilot\CallAssessment\Enum\AvailabilityType;
use Bitrix\Crm\Copilot\CallAssessment\Enum\CallType;
use Bitrix\Crm\Copilot\CallAssessment\Enum\ClientType;
use Bitrix\Crm\Copilot\CallAssessment\HintStateResolver;
use Bitrix\Crm\Copilot\CallAssessment\PromptsChecker;
use Bitrix\Crm\Copilot\CallAssessment\ScriptEditChangeDetector;
use Bitrix\Crm\Copilot\CallAssessment\V2ScriptDataLoader;
use Bitrix\Crm\Copilot\CallScriptEditReview\EditReviewRepository;
use Bitrix\Crm\Integration\AI;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\Enum\GlobalSetting;
use Bitrix\Crm\Integration\AI\ErrorCode as AIErrorCode;
use Bitrix\Crm\Integration\AI\JobRepository;
use Bitrix\Crm\Integration\AI\Model\QueueTable;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\DB\TransactionException;
use Bitrix\Main\Engine\ActionFilter\Scope;
use Bitrix\Main\Engine\AutoWire\ExactParameter;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

final class CallAssessment extends Base
{
	private const MIN_USER_TEXT_LENGTH = 100;

	private const CLIENT_EDITABLE_ITEM_FIELDS = [
		'id',
		'title',
		'description',
		'prompt',
		'isHintEnabled',
		'clientTypeIds',
		'callTypeId',
		'autoCheckTypeId',
		'availabilityType',
		'availabilityData',
		'isAiImprovementEnabled',
		'lowBorder',
		'highBorder',
		'criteria',
	];

	protected function getDefaultPreFilters(): array
	{
		$filters = parent::getDefaultPreFilters();
		$filters[] = new Scope(Scope::NOT_REST);

		return $filters;
	}

	// region actions
	public function saveAction(
		CallAssessmentItem $callAssessmentItem,
		?int $id = null,
		array $criteria = [],
		?string $eventId = null,
		bool $criteriaGeneratedByAi = false,
		?int $aiGeneratedCriteriaCount = null,
	): Result
	{
		if (!Container::getInstance()->getUserPermissions()->copilotCallAssessment()->canEdit())
		{
			$this->addError(ErrorCode::getAccessDeniedError());

			return new Result();
		}

		if (AIManager::isCallScoringV2Enabled())
		{
			return $this->saveV2(
				$id ?? 0,
				$callAssessmentItem,
				$criteria,
				$criteriaGeneratedByAi,
				$aiGeneratedCriteriaCount,
			);
		}

		return $this->saveV1($callAssessmentItem, $id, $eventId);
	}

	private function saveV1(
		CallAssessmentItem $callAssessmentItem,
		?int $id,
		?string $eventId,
	): Result
	{
		$userId = $this->getCurrentUserId();

		$controller = CopilotCallAssessmentController::getInstance();

		if ($id)
		{
			$entity = $controller->getById($id);
			if ($entity === null)
			{
				$this->addError(ErrorCode::getNotFoundError());

				return new Result();
			}

			$isNeedExtractCriteria = PromptsChecker::isChanged(
				$entity->getPrompt(),
				$callAssessmentItem->getPrompt(),
			);

			if ($isNeedExtractCriteria)
			{
				$extractScoringCriteriaResult = JobRepository::getInstance()->getExtractScoringCriteriaResultById($id);
				if ($extractScoringCriteriaResult?->isPending())
				{
					$this->addError(
						new Error(
							'Operation by extract scoring criteria is already in progress',
							AI\ErrorCode::OPERATION_IS_PENDING,
						),
					);

					return new Result();
				}

				if (!$this->isAiOperationAvailable($userId))
				{
					$this->addError(new Error('Operation is not available', AIErrorCode::AI_NOT_AVAILABLE));

					return new Result();
				}
			}

			$callAssessmentItem
				->setGist($isNeedExtractCriteria ? null : $entity->getGist())
				->setJobId($entity->getJobId())
				->setStatus($isNeedExtractCriteria ? QueueTable::EXECUTION_STATUS_PENDING : $entity->getStatus())
				->setCode($entity->getCode())
			;
			if ($callAssessmentItem->isHintEnabled() === null)
			{
				$callAssessmentItem->setHintEnabled($entity->getIsHintEnabled());
			}

			$context = clone Container::getInstance()->getContext();
			$context->setEventId($eventId);
			$result = $controller->update($id, $callAssessmentItem, $context);
		}
		else
		{
			if (!$this->isAiOperationAvailable($userId))
			{
				$this->addError(AI\ErrorCode::getAINotAvailableError());

				return new Result();
			}

			$isNeedExtractCriteria = true;

			$callAssessmentItem->setStatus(QueueTable::EXECUTION_STATUS_PENDING);
			$result = $controller->add($callAssessmentItem);
		}

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return $result;
		}

		if ($isNeedExtractCriteria)
		{
			$launchOperationResult = AIManager::launchExtractScoringCriteria(
				$result->getId(),
				$callAssessmentItem->getPrompt(),
				$userId,
			);
			if (!$launchOperationResult->isSuccess())
			{
				$this->addErrors($launchOperationResult->getErrors());

				if (!$id)
				{
					// roll back the freshly created record so it is not left orphaned in the PENDING status
					$controller->delete($result->getId());

					return $launchOperationResult;
				}

				return $result;
			}
		}

		return $result;
	}

	public function activeAction(int $id, string $isEnabled): Result
	{
		if (!Container::getInstance()->getUserPermissions()->copilotCallAssessment()->canEdit())
		{
			$this->addError(ErrorCode::getAccessDeniedError());

			return new Result();
		}

		$entity = CopilotCallAssessmentController::getInstance()->getById($id);
		if ($entity === null)
		{
			$this->addError(ErrorCode::getNotFoundError());

			return new Result();
		}

		$availabilityType = $isEnabled === 'Y'
			? AvailabilityType::ALWAYS_ACTIVE
			: AvailabilityType::INACTIVE
		;

		$callAssessmentItem = (CallAssessmentItem::createFromEntity($entity))
			->setAvailabilityType($availabilityType)
		;

		$result = CopilotCallAssessmentController::getInstance()->update($id, $callAssessmentItem);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());
		}

		return $result;
	}

	public function deleteAction(int $id): Result
	{
		if (!Container::getInstance()->getUserPermissions()->copilotCallAssessment()->canEdit())
		{
			$this->addError(ErrorCode::getAccessDeniedError());

			return new Result();
		}

		$result = CopilotCallAssessmentController::getInstance()->delete($id);
		if (!$result?->isSuccess())
		{
			$this->addErrors($result?->getErrors());
		}

		return $result;
	}

	public function toggleAutofillAction(int $id, bool $isEnabled): Result
	{
		if (!Container::getInstance()->getUserPermissions()->copilotCallAssessment()->canEdit())
		{
			$this->addError(ErrorCode::getAccessDeniedError());

			return new Result();
		}

		if (!AIManager::isCallScoringV2Enabled())
		{
			$this->addError(AI\ErrorCode::getAINotAvailableError());

			return new Result();
		}

		$entity = CopilotCallAssessmentController::getInstance()->getById($id);
		if ($entity === null)
		{
			$this->addError(ErrorCode::getNotFoundError());

			return new Result();
		}

		$callAssessmentItem = CallAssessmentItem::createFromEntity($entity)
			->setAiImprovementEnabled($isEnabled)
		;

		$result = CopilotCallAssessmentController::getInstance()->update($id, $callAssessmentItem);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());
		}

		return $result;
	}

	public function generateFromDialogAction(
		string $userText,
		string $scriptName = '',
		array $clientTypeIds = [],
		?int $callTypeId = null,
	): ?array
	{
		if (!Container::getInstance()->getUserPermissions()->copilotCallAssessment()->canEdit())
		{
			$this->addError(ErrorCode::getAccessDeniedError());

			return null;
		}

		$userId = $this->getCurrentUserId();
		if (!$this->isAiOperationAvailable($userId) || !AIManager::isCallScoringV2Enabled())
		{
			$this->addError(AI\ErrorCode::getAINotAvailableError());

			return null;
		}

		$userText = trim($userText);
		if (mb_strlen($userText) < self::MIN_USER_TEXT_LENGTH)
		{
			$this->addError(new Error('User text is too short', ErrorCode::INVALID_ARG_VALUE));

			return null;
		}

		$validClientTypeIds = array_values(array_filter(
			array_map('intval', $clientTypeIds),
			static fn (int $id): bool => ClientType::tryFrom($id) !== null,
		));
		if ($validClientTypeIds === [])
		{
			$this->addError(new Error('At least one client type is required', ErrorCode::INVALID_ARG_VALUE));

			return null;
		}

		if ($callTypeId !== null && CallType::tryFrom($callTypeId) === null)
		{
			$this->addError(new Error('Invalid call type', ErrorCode::INVALID_ARG_VALUE));

			return null;
		}

		$assessmentId = $this->preCreatePendingAssessment(trim($scriptName), $validClientTypeIds, $callTypeId);
		if ($assessmentId <= 0)
		{
			return null;
		}

		$launchResult = AIManager::launchGenerateCallScriptFromDialog($userText, $assessmentId, $userId);
		if (!$launchResult->isSuccess())
		{
			CopilotCallAssessmentController::getInstance()->delete($assessmentId);
			$this->addErrors($launchResult->getErrors());

			return null;
		}

		return [
			'jobId' => $launchResult->getJobId(),
			'assessmentId' => $assessmentId,
		];
	}

	private function preCreatePendingAssessment(
		string $scriptName,
		array $clientTypeIds,
		?int $callTypeId,
	): int
	{
		$item = CallAssessmentItem::createFromArray([
			'title' => empty($scriptName)
				? Loc::getMessage('CRM_AI_CALL_SCRIPT_PENDING_TITLE', ['#COPILOT_NAME#' => AIManager::getCopilotName()])
				: $scriptName,
			'description' => '',
			'prompt' => '',
			'isHintEnabled' => false,
			'gist' => null,
			'clientTypeIds' => $clientTypeIds,
			'callTypeId' => $callTypeId ?? CallType::ALL->value,
			'lowBorder' => CallAssessmentItem::LOW_BORDER_DEFAULT,
			'highBorder' => CallAssessmentItem::HIGH_BORDER_DEFAULT,
			'autoCheckTypeId' => AutoCheckType::ALL->value,
			'status' => CallAssessmentItem::STATUS_GENERATING_FROM_DIALOG,
		]);

		$addResult = CopilotCallAssessmentController::getInstance()->add($item);
		if (!$addResult->isSuccess())
		{
			$this->addErrors($addResult->getErrors());

			return 0;
		}

		return (int)$addResult->getId();
	}

	private function saveV2(
		int $id,
		CallAssessmentItem $patch,
		array $criteria,
		bool $criteriaGeneratedByAi = false,
		?int $aiGeneratedCriteriaCount = null,
	): Result
	{
		$result = new Result();
		if (!$patch->areHintFieldsValid())
		{
			$this->addError(new Error(
				'Prompt and isHintEnabled must be provided together with valid types',
				ErrorCode::INVALID_ARG_VALUE,
			));

			return $result;
		}

		if ($id <= 0)
		{
			return $this->createV2(
				$patch,
				$criteria,
				$criteriaGeneratedByAi,
				$aiGeneratedCriteriaCount,
			);
		}

		$entity = CopilotCallAssessmentController::getInstance()->getById($id);
		if ($entity === null)
		{
			$this->addError(ErrorCode::getNotFoundError());

			return $result;
		}

		$payloadCriteria = $this->filterCriteriaPayload($criteria);
		if ($payloadCriteria === [])
		{
			$this->addError(new Error(
				'Script must have at least one non-empty criterion',
				ErrorCode::INVALID_ARG_VALUE,
			));

			return $result;
		}

		$item = $this->mergeIntoItem($entity, $patch);

		$prevSnapshot = new ScriptStructureDto(
			callType: $entity->getCallType(),
			clientTypeIds: $this->collectEntityClientTypeIds($entity),
			criteria: $this->loadCurrentCriteriaPairs($id),
		);

		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			$updateResult = CopilotCallAssessmentController::getInstance()->update($id, $item);
			if (!$updateResult->isSuccess())
			{
				$this->rollbackTransactionSafely($connection);
				$this->addErrors($updateResult->getErrors());

				return $result;
			}

			$syncResult = (new CriteriaWriter())->sync($id, $payloadCriteria);
			if (!$syncResult->isSuccess())
			{
				$this->rollbackTransactionSafely($connection);
				$this->addErrors($syncResult->getErrors());

				return $result;
			}

			$connection->commitTransaction();
		}
		catch (\Throwable $e)
		{
			$this->rollbackTransactionSafely($connection);
			$this->addError(new Error($e->getMessage()));

			return $result;
		}

		if ($item->isAiImprovementEnabled())
		{
			$nextSnapshot = new ScriptStructureDto(
				callType: $item->getCallTypeId(),
				clientTypeIds: $item->getClientTypeIds(),
				criteria: $payloadCriteria,
			);
			$this->launchReviewIfChanged($id, $prevSnapshot, $nextSnapshot);
		}

		$result->setData(['data' => (new V2ScriptDataLoader())->loadById($id)]);

		return $result;
	}

	private function createV2(
		CallAssessmentItem $patch,
		array $criteria,
		bool $criteriaGeneratedByAi = false,
		?int $aiGeneratedCriteriaCount = null,
	): Result
	{
		$result = new Result();

		$payloadCriteria = $this->filterCriteriaPayload($criteria);
		if ($payloadCriteria === [])
		{
			$this->addError(new Error(
				'Script must have at least one non-empty criterion',
				ErrorCode::INVALID_ARG_VALUE,
			));

			return $result;
		}

		$patchData = $patch->toArray();
		$prompt = $patch->areHintFieldsProvided() ? $patch->getPrompt() : '';
		$isHintEnabled = $patch->areHintFieldsProvided() ? $patch->isHintEnabled() : false;
		$item = CallAssessmentItem::createFromArray([
			'title' => $patchData['title'],
			'description' => $patchData['description'] ?? '',
			'prompt' => $prompt,
			'isHintEnabled' => $isHintEnabled,
			'gist' => null,
			'clientTypeIds' => $patchData['clientTypeIds'],
			'callTypeId' => $patchData['callTypeId'] ?? CallType::ALL->value,
			'lowBorder' => CallAssessmentItem::LOW_BORDER_DEFAULT,
			'highBorder' => CallAssessmentItem::HIGH_BORDER_DEFAULT,
			'autoCheckTypeId' => AutoCheckType::ALL->value,
			'isAiImprovementEnabled' => $patchData['isAiImprovementEnabled'] ?? true,
			'status' => QueueTable::EXECUTION_STATUS_SUCCESS,
		]);

		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			$addResult = CopilotCallAssessmentController::getInstance()->add($item);
			if (!$addResult->isSuccess())
			{
				$this->rollbackTransactionSafely($connection);
				$this->addErrors($addResult->getErrors());

				return $result;
			}

			$newId = (int)$addResult->getId();

			$syncResult = (new CriteriaWriter())->sync($newId, $payloadCriteria);
			if (!$syncResult->isSuccess())
			{
				$this->rollbackTransactionSafely($connection);
				$this->addErrors($syncResult->getErrors());

				return $result;
			}

			$connection->commitTransaction();
		}
		catch (\Throwable $e)
		{
			$this->rollbackTransactionSafely($connection);
			$this->addError(new Error($e->getMessage()));

			return $result;
		}

		(new CreateScriptEvent())
			->setCallAssessmentId($newId)
			->setIsManualLaunch(true)
			->buildEvent()
			->send()
		;

		if ($criteriaGeneratedByAi)
		{
			$persistedCriteriaCount = count(CopilotCallAssessmentCriteriaController::getInstance()->getList([
				'select' => ['ID'],
				'filter' => ['=ASSESSMENT_ID' => $newId],
			]));
			$criteriaCount = $aiGeneratedCriteriaCount === null
				? $persistedCriteriaCount
				: max(0, min($aiGeneratedCriteriaCount, $persistedCriteriaCount))
			;

			if ($criteriaCount > 0)
			{
				(new CriteriaGenEvent())
					->setCallAssessmentId($newId)
					->setCriteriaCount($criteriaCount)
					->setIsManualLaunch(true)
					->buildEvent()
					->send()
				;
			}
		}

		$result->setData(['data' => (new V2ScriptDataLoader())->loadById($newId)]);

		return $result;
	}

	private function rollbackTransactionSafely(Connection $connection): void
	{
		try
		{
			$connection->rollbackTransaction();
		}
		catch (TransactionException)
		{
			// MySQL restores the savepoint before reporting that nested rollbacks are unsupported.
		}
	}

	private function launchReviewIfChanged(
		int $assessmentId,
		ScriptStructureDto $prev,
		ScriptStructureDto $next,
	): void
	{
		if (!ScriptEditChangeDetector::hasStructureChanged($prev, $next))
		{
			return;
		}

		$launchResult = AIManager::launchReviewCallScriptAfterEdit($assessmentId, $this->getCurrentUserId());
		if (!$launchResult->isSuccess())
		{
			AIManager::logger()->info(
				'{date}: {class}: review job launch skipped for assessment {id}: {errors}',
				[
					'class' => self::class,
					'id' => $assessmentId,
					'errors' => $launchResult->getErrors(),
				],
			);

			return;
		}

		$jobId = $launchResult->getJobId() ?? 0;
		if ($jobId <= 0)
		{
			return;
		}

		EditReviewRepository::getInstance()->upsert($assessmentId, $jobId);
	}

	/**
	 * @return int[]
	 */
	private function collectEntityClientTypeIds(CopilotCallAssessment $entity): array
	{
		$ids = [];
		foreach ($entity->getClientTypes() ?? [] as $clientType)
		{
			$ids[] = $clientType->getClientTypeId();
		}

		return $ids;
	}

	/**
	 * @return array<int, array{title: string, description: string}>
	 */
	private function loadCurrentCriteriaPairs(int $assessmentId): array
	{
		$rows = CopilotCallAssessmentCriteriaController::getInstance()->getList([
			'select' => [
				'TITLE',
				'DESCRIPTION',
			],
			'filter' => [
				'=ASSESSMENT_ID' => $assessmentId,
			],
		]);

		$pairs = [];
		foreach ($rows as $row)
		{
			$pairs[] = [
				'title' => $row['TITLE'] ?? '',
				'description' => $row['DESCRIPTION'] ?? '',
			];
		}

		return $pairs;
	}

	private function mergeIntoItem(CopilotCallAssessment $entity, CallAssessmentItem $patch): CallAssessmentItem
	{
		$merged = CallAssessmentItem::createFromEntity($entity)->toArray();
		$patchData = $patch->toArray();

		$merged['title'] = $patchData['title'];
		$merged['clientTypeIds'] = $patchData['clientTypeIds'];
		if ($patchData['callTypeId'] !== null)
		{
			$merged['callTypeId'] = $patchData['callTypeId'];
		}
		if ($patch->areHintFieldsProvided())
		{
			$merged['prompt'] = $patch->getPrompt();
			$merged['isHintEnabled'] = $patch->isHintEnabled();
		}
		elseif ($merged['isHintEnabled'] === null)
		{
			$merged['isHintEnabled'] = (new HintStateResolver())->resolve(
				null,
				$entity->getPrompt(),
				$entity->getCode(),
			);
		}

		return CallAssessmentItem::createFromArray($merged);
	}

	private function filterCriteriaPayload(array $criteria): array
	{
		$out = [];
		foreach ($criteria as $criterion)
		{
			if (!is_array($criterion))
			{
				continue;
			}

			$title = trim($criterion['title'] ?? '');
			$description = trim($criterion['description'] ?? '');
			if ($title === '' || $description === '')
			{
				continue;
			}

			$out[] = $criterion;
		}

		return $out;
	}

	public function getScriptDataAction(int $id): ?array
	{
		if (!AIManager::isCallScoringV2Enabled())
		{
			$this->addError(new Error('AI is not available', AIErrorCode::AI_NOT_AVAILABLE));

			return null;
		}

		if (!Container::getInstance()->getUserPermissions()->copilotCallAssessment()->canRead())
		{
			$this->addError(ErrorCode::getAccessDeniedError());

			return null;
		}

		$data = (new V2ScriptDataLoader())->loadById($id);
		if ($data === null)
		{
			$this->addError(ErrorCode::getNotFoundError());

			return null;
		}

		return ['data' => $data];
	}
	// endregion

	public function getAutoWiredParameters(): array
	{
		return [
			new ExactParameter(
				CallAssessmentItem::class,
				'callAssessmentItem',
				static function($className, $data) {
					return CallAssessmentItem::createFromArray(self::sanitizeClientItemPayload($data));
				},
			),
		];
	}

	private static function sanitizeClientItemPayload(mixed $data): array
	{
		if (!is_array($data))
		{
			return [];
		}

		$data = array_intersect_key($data, array_flip(self::CLIENT_EDITABLE_ITEM_FIELDS));
		foreach (['isHintEnabled', 'isAiImprovementEnabled'] as $field)
		{
			if (($data[$field] ?? null) === 'true' || ($data[$field] ?? null) === 'false')
			{
				$data[$field] = $data[$field] === 'true';
			}
		}

		return $data;
	}

	private function getCurrentUserId(): int
	{
		return $this->getCurrentUser()?->getId() ?? Container::getInstance()->getContext()->getUserId();
	}

	private function isAiOperationAvailable(int $userId): bool
	{
		return AIManager::isAiCallProcessingEnabled()
			&& AIManager::isAILicenceAccepted($userId)
			&& AIManager::isEnabledInGlobalSettings(GlobalSetting::CallAssessment)
		;
	}
}
