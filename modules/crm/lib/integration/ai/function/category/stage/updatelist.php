<?php

namespace Bitrix\Crm\Integration\AI\Function\Category\Stage;

use Bitrix\Crm\Controller\ErrorCode;
use Bitrix\Crm\EO_Status;
use Bitrix\Crm\EO_Status_Collection;
use Bitrix\Crm\Integration\AI\Contract\AIFunction;
use Bitrix\Crm\Integration\AI\Function\Category\Dto\Stage\UpdateListParameters;
use Bitrix\Crm\Integration\AI\Function\Category\Dto\Stage\UpdateListStage;
use Bitrix\Crm\Integration\PullManager;
use Bitrix\Crm\PhaseSemantics;
use Bitrix\Crm\Result;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\Stage\DefaultProcessColorGenerator;
use Bitrix\Main\Application;
use Bitrix\Main\ORM\Objectify\State;
use CCrmStatus;
use RuntimeException;
use Throwable;

final class UpdateList implements AIFunction
{
	private const SORT_STEP = 10;

	private readonly UserPermissions $permissions;

	public function __construct(
		private readonly int $currentUserId,
	)
	{
		$this->permissions = Container::getInstance()->getUserPermissions($this->currentUserId);
	}

	public function isAvailable(): bool
	{
		return true;
	}

	public function invoke(...$args): Result
	{
		$parameters = new UpdateListParameters($args);
		if ($parameters->hasValidationErrors())
		{
			return Result::fail($parameters->getValidationErrors());
		}

		if (!$this->permissions->isAdminForEntity($parameters->entityTypeId))
		{
			return Result::failAccessDenied();
		}

		$factory = Container::getInstance()->getFactory($parameters->entityTypeId);
		if ($factory === null)
		{
			return Result::fail("Entity type with ID {$parameters->entityTypeId} not found");
		}

		$connection = Application::getConnection();
		$colorGenerator = new DefaultProcessColorGenerator();

		$stagesAsArrays = array_map(
			static fn(UpdateListStage $stage) => ['name' => $stage->name, 'color' => $stage->color],
			$parameters->stages,
		);

		$changeCounts = null;

		try
		{
			$connection->startTransaction();

			$existingStages = $factory->getStages($parameters->categoryId);
			$changeCounts = $this->countStageChanges($existingStages, $stagesAsArrays);
			$newCollection = $this->applyStageChanges($existingStages, $stagesAsArrays, $colorGenerator);
			$this->saveStages($newCollection, $factory->getStagesEntityId($parameters->categoryId));

			$connection->commitTransaction();
		}
		catch (Throwable $e)
		{
			$connection->rollbackTransaction();

			return Result::fail($e->getMessage(), $e->getCode());
		}

		PullManager::getInstance()->sendStageUpdatedEvent(
			[],
			[
				'TYPE' => $factory->getEntityName(),
				'CATEGORY_ID' => $parameters->categoryId,
			],
		);

		return Result::success(changeCounts: $changeCounts);
	}

	private function applyStageChanges(
		EO_Status_Collection $existingStages,
		array $stagesToUpdate,
		DefaultProcessColorGenerator $colorGenerator,
	): EO_Status_Collection
	{
		$result = new EO_Status_Collection();
		$lastUpdatedStage = null;

		foreach ($existingStages->getAll() as $existingStage)
		{
			$isFinal = PhaseSemantics::isFinal($existingStage->getSemantics());
			$isEmpty = empty($stagesToUpdate);

			if (!$isEmpty && !$isFinal)
			{
				$current = array_shift($stagesToUpdate);
				$existingColor = $existingStage->getColor() ?: null;

				$existingStage
					->setName($current['name'])
					->setColor($current['color'] ?? $existingColor ?? $colorGenerator->generate())
				;

				$result->add($existingStage);
				$lastUpdatedStage = $existingStage;

				continue;
			}

			if (!$isEmpty && $isFinal)
			{
				foreach ($stagesToUpdate as $stage)
				{
					$result->add(
						(new EO_Status())
							->setName($stage['name'])
							->setColor($stage['color'] ?? $colorGenerator->generate()),
					);
				}

				$stagesToUpdate = [];
				$result->add($existingStage);

				continue;
			}

			if ($isEmpty && !$isFinal)
			{
				if ($existingStage->getSystem() && $lastUpdatedStage !== null)
				{
					$result->add(
						$existingStage
							->setName($lastUpdatedStage->getName())
							->setColor($lastUpdatedStage->getColor()),
					);

					$result->remove($lastUpdatedStage);

					$deleteResult = $lastUpdatedStage->delete();
					if (!$deleteResult->isSuccess())
					{
						$error = $deleteResult->getError() ?? ErrorCode::getGeneralError();

						throw new RuntimeException($error->getMessage());
					}

					continue;
				}

				$deleteResult = $existingStage->delete();
				if (!$deleteResult->isSuccess())
				{
					$error = $deleteResult->getError() ?? ErrorCode::getGeneralError();

					throw new RuntimeException($error->getMessage());
				}

				continue;
			}

			$result->add($existingStage);
		}

		$sort = self::SORT_STEP;
		foreach ($result->getAll() as $stage)
		{
			$stage->setSort($sort);
			$sort += self::SORT_STEP;
		}

		return $result;
	}

	private function saveStages(EO_Status_Collection $stages, string $stagesEntityId): void
	{
		$stageService = new CCrmStatus($stagesEntityId);

		foreach (array_reverse($stages->getAll()) as $stage)
		{
			if ($stage->state === State::DELETED)
			{
				continue;
			}

			$fields = [
				'NAME' => $stage->getName(),
				'COLOR' => $stage->getColor(),
				'SORT' => $stage->getSort(),
			];

			if ($stage->state === State::RAW)
			{
				$addResult = $stageService->Add($fields);
				if ($addResult === false)
				{
					$errorMessage = $stageService->GetLastError() ?? ErrorCode::getGeneralError()->getMessage();

					throw new RuntimeException($errorMessage);
				}

				continue;
			}

			$updateResult = $stageService->Update($stage->getId(), $fields);
			if ($updateResult === false)
			{
				$errorMessage = $stageService->GetLastError() ?? ErrorCode::getGeneralError()->getMessage();

				throw new RuntimeException($errorMessage);
			}
		}
	}

	/**
	 * @return array{added: int, renamed: int, deleted: int}
	 */
	private function countStageChanges(EO_Status_Collection $existingStages, array $stagesToUpdate): array
	{
		$inProcessStages = array_filter(
			$existingStages->getAll(),
			static fn(EO_Status $stage) => !PhaseSemantics::isFinal($stage->getSemantics()),
		);

		$existingCount = count($inProcessStages);
		$updateCount = count($stagesToUpdate);

		if ($updateCount > $existingCount)
		{
			return [
				'added' => $updateCount - $existingCount,
				'renamed' => $existingCount,
				'deleted' => 0,
			];
		}

		return [
			'added' => 0,
			'renamed' => $updateCount,
			'deleted' => $existingCount - $updateCount,
		];
	}
}
