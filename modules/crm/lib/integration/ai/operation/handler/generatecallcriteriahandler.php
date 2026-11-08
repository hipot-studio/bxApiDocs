<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\AI\Operation\Handler;

use Bitrix\Crm\Copilot\CallAssessment\Analytics\CreateScriptEvent;
use Bitrix\Crm\Copilot\CallAssessment\Analytics\CriteriaGenEvent;
use Bitrix\Crm\Copilot\CallAssessment\CallAssessmentItem;
use Bitrix\Crm\Copilot\CallAssessment\Controller\CopilotCallAssessmentController;
use Bitrix\Crm\Copilot\CallAssessment\Controller\CopilotCallAssessmentCriteriaController;
use Bitrix\Crm\Copilot\CallAssessment\CriteriaWriter;
use Bitrix\Crm\Copilot\CallAssessment\Enum\AutoCheckType;
use Bitrix\Crm\Copilot\CallAssessment\Enum\CallType;
use Bitrix\Crm\Copilot\CallAssessment\Enum\ClientType;
use Bitrix\Crm\Copilot\CallScriptMaintenance\Dispatcher;
use Bitrix\Crm\Copilot\CallScriptMaintenance\FirstScriptCreatedFlag;
use Bitrix\Crm\Copilot\CallScriptMaintenance\State;
use Bitrix\Crm\Copilot\CallScriptMaintenance\StateRepository;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\Dto\Scoring\GenerateCallCriteriaPayload;
use Bitrix\Main\Application;

final class GenerateCallCriteriaHandler
{
	private const HANDLED_JOB_TYPES = [
		State::JOB_TYPE_CREATE_CALL_ASSESSMENT,
		State::JOB_TYPE_ENRICH_CALL_ASSESSMENT,
		State::JOB_TYPE_BACKFILL_CALL_ASSESSMENT,
	];

	public function __construct(
		private int $assessmentId,
		private readonly GenerateCallCriteriaPayload $payload,
	)
	{

	}

	public function saveCriteria(): void
	{
		$this->sendCriteriaGenAnalytics($this->saveCriteriaAndGetCount());
	}

	private function saveCriteriaAndGetCount(): int
	{
		if ($this->assessmentId <= 0)
		{
			return 0;
		}

		$criteria = array_map(
			static fn($criterion): array => [
				'title' => $criterion->name,
				'description' => $criterion->description,
			],
			$this->payload->newCriteria,
		);

		$results = (new CriteriaWriter())->append($this->assessmentId, $criteria);

		$savedCount = 0;
		foreach ($results as $result)
		{
			if ($result->isSuccess())
			{
				$savedCount++;

				continue;
			}

			AIManager::logger()->error(
				'{date}: {class}: Failed to save generated criterion: {errors}',
				[
					'class' => self::class,
					'errors' => $result->getErrors(),
				],
			);
		}

		return $savedCount;
	}

	private function sendCriteriaGenAnalytics(int $savedCount): void
	{
		if ($savedCount <= 0)
		{
			return;
		}

		(new CriteriaGenEvent())
			->setCallAssessmentId($this->assessmentId)
			->setCriteriaCount($savedCount)
			->setIsManualLaunch(false)
			->buildEvent()
			->send()
		;
	}

	public function applyMaintenancePostActionsByJob(int $jobId): bool
	{
		$context = (new StateRepository())->popMaintenanceJob(
			$jobId,
			self::HANDLED_JOB_TYPES,
		);

		if ($context === null)
		{
			return false;
		}

		$dispatcher = Dispatcher::getInstance();
		$type = (string)($context['type'] ?? '');
		$selectionIds = $context['selectionIds'] ?? [];

		if ($type === State::JOB_TYPE_CREATE_CALL_ASSESSMENT)
		{
			if (empty($this->payload->newCriteria))
			{
				AIManager::logger()->warning(
					'{date}: {class}: empty criteria — skipping script creation (job {job})',
					['class' => self::class, 'job' => $jobId],
				);

				return true;
			}

			$newId = $this->createScriptFromPayload($context['groupName'] ?? null);
			if ($newId <= 0)
			{
				AIManager::logger()->error(
					'{date}: {class}: failed to create new script from maintenance payload (job {job})',
					['class' => self::class, 'job' => $jobId],
				);

				return true;
			}

			$this->assessmentId = $newId;
			$this->saveCriteria();
			$dispatcher->markGrouped($selectionIds);
			$dispatcher->pingAgent();

			(new CreateScriptEvent())
				->setCallAssessmentId($newId)
				->setIsManualLaunch(false)
				->buildEvent()
				->send()
			;
		}
		elseif ($type === State::JOB_TYPE_ENRICH_CALL_ASSESSMENT)
		{
			$this->saveCriteria();
			$dispatcher->markEnriched($selectionIds);
			$dispatcher->pingAgent();

			if (!empty($this->payload->newCriteria))
			{
				(new FirstScriptCreatedFlag())->markEnriched($this->assessmentId);
			}
		}
		elseif ($type === State::JOB_TYPE_BACKFILL_CALL_ASSESSMENT)
		{
			$savedCount = $this->saveCriteriaAndGetCount();
			$this->sendCriteriaGenAnalytics($savedCount);
			$this->backfillAssessmentDescriptionIfEmpty();

			if ($savedCount > 0 && $savedCount === count($this->payload->newCriteria))
			{
				$this->deleteReplacedCriteria((array)($context['criteriaIdsToReplace'] ?? []));
			}
		}
		else
		{
			$this->saveCriteria();
		}

		return true;
	}

	/** @param int[] $criterionIds */
	private function deleteReplacedCriteria(array $criterionIds): void
	{
		$criterionIds = array_values(array_unique(array_filter(
			array_map('intval', $criterionIds),
			static fn(int $id): bool => $id > 0,
		)));
		if (empty($criterionIds))
		{
			return;
		}

		$controller = CopilotCallAssessmentCriteriaController::getInstance();
		$existingCriterionIds = [];
		foreach ($criterionIds as $criterionId)
		{
			$criterion = $controller->getById($criterionId);
			if ($criterion === null)
			{
				continue;
			}
			if ((int)$criterion['ASSESSMENT_ID'] !== $this->assessmentId)
			{
				AIManager::logger()->error(
					'{date}: {class}: Refusing to delete unrelated replaced criterion {criterion}',
					['class' => self::class, 'criterion' => $criterionId],
				);

				return;
			}

			$existingCriterionIds[] = $criterionId;
		}
		if (empty($existingCriterionIds))
		{
			return;
		}

		$connection = Application::getConnection();
		$connection->startTransaction();
		try
		{
			foreach ($existingCriterionIds as $criterionId)
			{
				$result = $controller->deleteById($criterionId);
				if ($result === null || !$result->isSuccess())
				{
					throw new \RuntimeException(implode('; ', $result?->getErrorMessages() ?? []));
				}
			}

			$connection->commitTransaction();
		}
		catch (\Throwable $error)
		{
			$connection->rollbackTransaction();
			AIManager::logger()->error(
				'{date}: {class}: Failed to delete replaced criteria after successful repair: {error}',
				['class' => self::class, 'error' => $error->getMessage()],
			);
		}
	}

	private function backfillAssessmentDescriptionIfEmpty(): void
	{
		if ($this->assessmentId <= 0)
		{
			return;
		}

		$newDescription = $this->getPayloadDescription();
		if ($newDescription === '')
		{
			return;
		}

		$controller = CopilotCallAssessmentController::getInstance();
		$current = $controller->getById($this->assessmentId);
		if ($current === null || $current->getDescription() !== '')
		{
			return;
		}

		$result = $controller->updateDescription($this->assessmentId, $newDescription);
		if (!$result->isSuccess())
		{
			AIManager::logger()->error(
				'{date}: {class}: failed to backfill DESCRIPTION for call assessment {assessmentId}: {errors}',
				[
					'class' => self::class,
					'assessmentId' => $this->assessmentId,
					'errors' => implode('; ', $result->getErrorMessages()),
				],
			);
		}
	}

	private function createScriptFromPayload(?string $fallbackName): int
	{
		$title = $this->pickTitle($fallbackName);
		$description = $this->payload->description ?? '';
		$borders = CopilotCallAssessmentController::getInstance()->getMasterBorders();

		$item = CallAssessmentItem::createFromArray([
			'title' => $title,
			'description' => $description,
			'prompt' => '',
			'isHintEnabled' => false,
			'gist' => null,
			'clientTypeIds' => [ClientType::ANY->value],
			'callTypeId' => CallType::ALL->value,
			'lowBorder' => $borders['lowBorder'] ?? CallAssessmentItem::LOW_BORDER_DEFAULT,
			'highBorder' => $borders['highBorder'] ?? CallAssessmentItem::HIGH_BORDER_DEFAULT,
			'autoCheckTypeId' => AutoCheckType::ALL->value,
		]);

		$addResult = CopilotCallAssessmentController::getInstance()->add($item);
		if (!$addResult->isSuccess())
		{
			AIManager::logger()->error(
				'{date}: {class}: create script failed: {errors}',
				['class' => self::class, 'errors' => $addResult->getErrors()],
			);

			return 0;
		}

		$newId = (int)$addResult->getId();
		(new FirstScriptCreatedFlag())->markCreated($newId);

		return $newId;
	}

	private function getPayloadDescription(): string
	{
		return trim($this->payload->description ?? '');
	}

	private function pickTitle(?string $fallbackName): string
	{
		if ($this->payload->name !== null && $this->payload->name !== '')
		{
			return $this->payload->name;
		}

		if (is_string($fallbackName) && $fallbackName !== '')
		{
			return $fallbackName;
		}

		return 'Auto-generated call script';
	}
}
