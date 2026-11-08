<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Service;

use Bitrix\Crm\Controller\ErrorCode;
use Bitrix\Crm\EO_Status;
use Bitrix\Crm\EO_Status_Collection;
use Bitrix\Crm\Integration\PullManager;
use Bitrix\Crm\Kanban\Entity;
use Bitrix\Crm\PhaseSemantics;
use Bitrix\Crm\Result;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Factory;
use Bitrix\Crm\Service\UserPermissions\ConfigurationCallSite;
use Bitrix\Crm\Service\UserPermissions\ConfigurationScope;
use Bitrix\Crm\Stage\DefaultColorGenerator;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Request\Stage\CreateStageDto;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Request\Stage\SetStageListItemDto;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Response;
use Bitrix\Main\Application;
use CCrmStatus;
use RuntimeException;
use Throwable;

final readonly class StageService
{
	private const SORT_STEP = 10;

	public function __construct(
		private CategoryService $categoryService,
	)
	{
	}

	public function createStage(
		int $userId,
		int $entityTypeId,
		?int $categoryId,
		CreateStageDto $stage,
	): Result
	{
		$result = $this->prepare($userId, $entityTypeId, $categoryId);
		if (!$result->isSuccess())
		{
			return $result;
		}

		/** @var Factory $factory */
		$factory = $result->get('factory');

		/** @var ?int $categoryId */
		$categoryId = $result->get('categoryId');

		$existingStages = $factory->getStages($categoryId);
		$sortValidationResult = $this->validateNewStageSort($existingStages, $stage);
		if (!$sortValidationResult->isSuccess())
		{
			return $sortValidationResult;
		}

		$service = new CCrmStatus($factory->getStagesEntityId($categoryId));
		$fields = [
			'NAME' => $stage->title,
			'COLOR' => $stage->color ?? $this->generateDefaultColor($existingStages, $stage->semantics),
			'SORT' => $stage->sort,
			'SEMANTICS' => ($stage->semantics !== PhaseSemantics::PROCESS ? $stage->semantics : null),
			'CATEGORY_ID' => $categoryId,
		];

		$id = $service->Add($fields);
		if (!$id)
		{
			$message = $service->GetLastError() ?: 'CRM returned no details while creating the stage.';

			return Result::fail(
				"Failed to create stage '{$stage->title}' for entityTypeId {$entityTypeId}, categoryId "
				. ($categoryId === null ? 'null' : (string)$categoryId)
				. ". {$message}"
			);
		}

		$factory->getStageBroker()->clearCache();
		$stageObject = $this->findStageById($factory->getStages($categoryId), (int)$id);
		if ($stageObject === null)
		{
			return Result::fail(
				"Stage '{$stage->title}' was created, but could not be read after creation."
			);
		}

		$this->sendStageAddedPullEvent($factory, $stageObject);

		return Result::success(stage: Response\Stage::fromObject($stageObject));
	}

	public function listStages(int $userId, int $entityTypeId, ?int $categoryId = null): Result
	{
		$result = $this->prepare($userId, $entityTypeId, $categoryId);
		if (!$result->isSuccess())
		{
			return $result;
		}

		/** @var Factory $factory */
		$factory = $result->get('factory');

		/** @var ?int $categoryId */
		$categoryId = $result->get('categoryId');

		$stages = [];
		foreach ($factory->getStages($categoryId) as $stage)
		{
			$stages[] = Response\Stage::fromObject($stage);
		}

		return Result::success(stages: $stages);
	}

	public function renameStage(
		int $userId,
		int $entityTypeId,
		string $stageId,
		string $title,
	): Result
	{
		$factoryResult = $this->getFactory($entityTypeId);
		if (!$factoryResult->isSuccess())
		{
			return $factoryResult;
		}

		/** @var Factory $factory */
		$factory = $factoryResult->get('factory');

		$stageToRename = $factory->getStage($stageId);
		if ($stageToRename === null)
		{
			return Result::fail(
				"Stage '{$stageId}' was not found for entityTypeId {$entityTypeId}. Use list_stages to get available stage IDs."
			);
		}

		$categoryId = $stageToRename->getCategoryId();
		if (!$this->canManageStages($userId, $entityTypeId, $categoryId))
		{
			return Result::failAccessDenied();
		}

		$result = $stageToRename
			->setName($title)
			->save()
		;

		if (!$result->isSuccess())
		{
			return Result::fail($result->getErrorCollection());
		}

		$this->sendStageUpdatedPullEvent($factory, $stageToRename->getCategoryId());

		return Result::success(stage: Response\Stage::fromObject($stageToRename));
	}

	public function deleteStage(
		int $userId,
		int $entityTypeId,
		string $stageId,
	): Result
	{
		$factoryResult = $this->getFactory($entityTypeId);
		if (!$factoryResult->isSuccess())
		{
			return $factoryResult;
		}

		/** @var Factory $factory */
		$factory = $factoryResult->get('factory');

		$stageToDelete = $factory->getStage($stageId);
		if ($stageToDelete === null)
		{
			return Result::fail(
				"Stage '{$stageId}' was not found for entityTypeId {$entityTypeId}. Use list_stages to get available stage IDs."
			);
		}

		$categoryId = $stageToDelete->getCategoryId();
		if (!$this->canManageStages($userId, $entityTypeId, $categoryId))
		{
			return Result::failAccessDenied();
		}

		$result = $stageToDelete->delete();
		if (!$result->isSuccess())
		{
			return Result::fail($result->getErrorCollection());
		}

		$this->sendStageDeletedPullEvent($factory, $categoryId, $stageToDelete);

		return Result::success(stageId: $stageId);
	}

	/**
	 * @param int $userId
	 * @param int $entityTypeId
	 * @param int|null $categoryId
	 * @param SetStageListItemDto[] $stages
	 * @return Result
	 */
	public function setStageList(
		int $userId,
		int $entityTypeId,
		?int $categoryId,
		array $stages,
	): Result
	{
		$result = $this->prepare($userId, $entityTypeId, $categoryId);
		if (!$result->isSuccess())
		{
			return $result;
		}

		/** @var Factory $factory */
		$factory = $result->get('factory');

		/** @var ?int $categoryId */
		$categoryId = $result->get('categoryId');

		if (!$this->canManageStages($userId, $entityTypeId, $categoryId))
		{
			return Result::failAccessDenied();
		}

		$semantics = [];
		foreach ($stages as $stage)
		{
			$semantics[$stage->semantics] = true;
		}

		$requiredSemantics = [
			PhaseSemantics::PROCESS,
			PhaseSemantics::SUCCESS,
			PhaseSemantics::FAILURE,
		];

		foreach ($requiredSemantics as $requiredSemantic)
		{
			if (!($semantics[$requiredSemantic] ?? false))
			{
				return Result::fail(
					"At least one stage with semantics '{$requiredSemantic}' is required. Pass stages with process, success, and failure semantics."
				);
			}
		}

		$connection = Application::getConnection();
		try
		{
			$connection->startTransaction();

			$deleteAllResult = $this->deleteAllCurrentStages($entityTypeId, $categoryId);
			if (!$deleteAllResult->isSuccess())
			{
				throw new RuntimeException($deleteAllResult->getError()?->getMessage());
			}

			$saveResult = $this->saveStages($entityTypeId, $categoryId, $stages);
			if (!$saveResult->isSuccess())
			{
				throw new RuntimeException($saveResult->getError()?->getMessage());
			}

			$connection->commitTransaction();
		}
		catch (Throwable $exception)
		{
			$connection->rollbackTransaction();

			return Result::fail($exception->getMessage(), $exception->getCode());
		}

		$this->sendStageUpdatedPullEvent($factory, $categoryId);

		$resultStages = [];
		foreach ($factory->getStages($categoryId) as $stage)
		{
			$resultStages[] = Response\Stage::fromObject($stage);
		}

		return Result::success(stages: $resultStages);
	}

	private function deleteAllCurrentStages(int $entityTypeId, ?int $categoryId): Result
	{
		$factoryResult = $this->getFactory($entityTypeId);
		if (!$factoryResult->isSuccess())
		{
			return $factoryResult;
		}

		/** @var Factory $factory */
		$factory = $factoryResult->get('factory');

		$currentStageCollection = $factory->getStages($categoryId);
		foreach ($currentStageCollection as $stage)
		{
			$deleteResult = $stage->delete();
			if (!$deleteResult->isSuccess())
			{
				return Result::fail($deleteResult->getErrorCollection());
			}
		}

		return Result::success();
	}

	/**
	 * @param int $entityTypeId
	 * @param int|null $categoryId
	 * @param SetStageListItemDto[] $stages
	 * @return Result
	 */
	private function saveStages(int$entityTypeId, ?int $categoryId, array $stages): Result
	{
		$factoryResult = $this->getFactory($entityTypeId);
		if (!$factoryResult->isSuccess())
		{
			return $factoryResult;
		}

		/** @var Factory $factory */
		$factory = $factoryResult->get('factory');

		$stageEntityId = $factory->getStagesEntityId($categoryId);
		$stageService = new CCrmStatus($stageEntityId);
		$colorGenerator = new DefaultColorGenerator();

		usort(
			array: $stages,
			callback: static function (SetStageListItemDto $a, SetStageListItemDto $b) {
				$order = [
					PhaseSemantics::PROCESS => 1,
					PhaseSemantics::SUCCESS => 2,
					PhaseSemantics::FAILURE => 3,
				];

				return $order[$a->semantics] <=> $order[$b->semantics];
			}
		);

		$sort = self::SORT_STEP;
		foreach ($stages as $stage)
		{
			$fields = [
				'NAME' => $stage->title,
				'COLOR' => $stage->color ?? $colorGenerator->generate($stage->semantics),
				'SORT' => $sort,
				'SEMANTICS' => $stage->semantics === PhaseSemantics::PROCESS ? null : $stage->semantics,
				'CATEGORY_ID' => $categoryId,
			];

			$addResult = $stageService->Add($fields);
			if ($addResult === false)
			{
				$errorMessage = $stageService->GetLastError() ?? ErrorCode::getGeneralError()->getMessage();

				return Result::fail($errorMessage);
			}

			$sort += self::SORT_STEP;
		}

		$factory->getStageBroker()->clearCache();

		return Result::success();
	}

	private function validateNewStageSort(
		EO_Status_Collection $existingStages,
		CreateStageDto $stage,
	): Result
	{
		$minimumFinalSort = null;
		$maximumNonFailureSort = 0;
		foreach ($existingStages as $existingStage)
		{
			$existingSort = $existingStage->getSort();
			if ($existingSort === $stage->sort)
			{
				return Result::fail(
					"Sort {$stage->sort} is already used by stage '{$existingStage->getStatusId()}'. "
					. 'Choose a free positive integer or use set_stage_list to reorder all stages.'
				);
			}

			$existingSemantics = $existingStage->getSemantics() ?? PhaseSemantics::PROCESS;
			if ($existingSemantics !== PhaseSemantics::PROCESS)
			{
				$minimumFinalSort = min($minimumFinalSort ?? $existingSort, $existingSort);
			}
			if ($existingSemantics !== PhaseSemantics::FAILURE)
			{
				$maximumNonFailureSort = max($maximumNonFailureSort, $existingSort);
			}
		}

		if ($stage->semantics === PhaseSemantics::PROCESS)
		{
			$maximumAllowedSort = $minimumFinalSort === null ? PHP_INT_MAX : $minimumFinalSort - 1;
			if ($stage->sort > $maximumAllowedSort)
			{
				return Result::fail(
					"Process stage sort must be between 1 and {$maximumAllowedSort} so that all P stages precede S and F stages. "
					. 'Use set_stage_list when there is no free integer in this interval.'
				);
			}

			return Result::success();
		}

		if ($stage->semantics === PhaseSemantics::FAILURE)
		{
			if ($maximumNonFailureSort === PHP_INT_MAX)
			{
				return Result::fail(
					'There is no free integer sort after the P and S stages. Use set_stage_list to reorder all stages.'
				);
			}

			$minimumAllowedSort = $maximumNonFailureSort + 1;
			if ($stage->sort < $minimumAllowedSort)
			{
				return Result::fail(
					"Failure stage sort must be between {$minimumAllowedSort} and " . PHP_INT_MAX
					. ' so that all F stages follow P and S stages. Use set_stage_list when there is no free integer in this interval.'
				);
			}

			return Result::success();
		}

		return Result::fail('create_stage supports only P and F semantics.');
	}

	private function generateDefaultColor(EO_Status_Collection $existingStages, string $semantics): string
	{
		$generator = new DefaultColorGenerator();
		if ($semantics === PhaseSemantics::PROCESS)
		{
			foreach ($existingStages as $existingStage)
			{
				$existingSemantics = $existingStage->getSemantics() ?? PhaseSemantics::PROCESS;
				if ($existingSemantics === PhaseSemantics::PROCESS)
				{
					$generator->generate();
				}
			}
		}

		return $generator->generate($semantics);
	}

	private function prepare(int $userId, int $entityTypeId, ?int $categoryId = null): Result
	{
		$factoryResult = $this->getFactory($entityTypeId);
		if (!$factoryResult->isSuccess())
		{
			return $factoryResult;
		}

		$categoryNormalizeResult = $this->categoryService->normalizeCategoryId($entityTypeId, $categoryId);
		if (!$categoryNormalizeResult->isSuccess())
		{
			return $categoryNormalizeResult;
		}

		$categoryId = $categoryNormalizeResult->get('categoryId');

		if (!$this->canManageStages($userId, $entityTypeId, $categoryId))
		{
			return Result::failAccessDenied();
		}

		return Result::success(
			factory: $factoryResult->get('factory'),
			categoryId: $categoryId,
		);
	}

	private function canManageStages(int $userId, int $entityTypeId, ?int $categoryId): bool
	{
		return Container::getInstance()
			->getUserPermissions($userId)
			->configuration()
			->canManageStages(
				ConfigurationCallSite::McpStageService,
				ConfigurationScope::ofEntityType($entityTypeId, $categoryId),
			)
		;
	}

	private function getFactory(int $entityTypeId): Result
	{
		$factory = Container::getInstance()->getFactory($entityTypeId);
		if ($factory === null)
		{
			return Result::failEntityTypeNotSupported($entityTypeId);
		}

		if (!$factory->isStagesSupported())
		{
			return Result::fail("Stages are not supported for entityTypeId {$entityTypeId}.");
		}

		if (!$factory->isStagesEnabled())
		{
			return Result::fail("Stages are not enabled for entityTypeId {$entityTypeId}.");
		}

		return Result::success(factory: $factory);
	}

	private function findStageById(EO_Status_Collection $collection, int $id): ?EO_Status
	{
		foreach ($collection->getAll() as $stage)
		{
			if ((int)$stage->getId() === $id)
			{
				return $stage;
			}
		}

		return null;
	}

	private function sendStageAddedPullEvent(Factory $factory, ?EO_Status $stage): void
	{
		if ($stage === null)
		{
			return;
		}

		$entityTypeName = $factory->getEntityName();
		$item = Entity::getInstance($entityTypeName)?->createPullStageByObj($stage);
		if ($item !== null)
		{
			PullManager::getInstance()->sendStageAddedEvent($item, [
				'TYPE' => $entityTypeName,
				'CATEGORY_ID' => $stage->getCategoryId(),
			]);
		}
	}

	private function sendStageDeletedPullEvent(Factory $factory, ?int $categoryId, EO_Status $stage): void
	{
		$entityTypeName = $factory->getEntityName();
		$item = Entity::getInstance($entityTypeName)?->createPullStageByObj($stage);
		if ($item !== null)
		{
			PullManager::getInstance()->sendStageDeletedEvent($item, [
				'TYPE' => $entityTypeName,
				'CATEGORY_ID' => $categoryId,
			]);
		}
	}

	private function sendStageUpdatedPullEvent(Factory $factory, ?int $categoryId): void
	{
		PullManager::getInstance()->sendStageUpdatedEvent([], [
			'TYPE' => $factory->getEntityName(),
			'CATEGORY_ID' => $categoryId,
		]);
	}
}
