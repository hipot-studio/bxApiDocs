<?php

namespace Bitrix\Crm\Copilot\CallAssessment\Controller;

use Bitrix\Crm\Copilot\AiQualityAssessment\Controller\AiQualityAssessmentController;
use Bitrix\Crm\Copilot\CallAssessment\CallAssessmentItem;
use Bitrix\Crm\Copilot\CallAssessment\Entity\CopilotCallAssessment;
use Bitrix\Crm\Copilot\CallAssessment\Entity\CopilotCallAssessmentTable;
use Bitrix\Crm\Copilot\CallAssessment\EntitySelector\PullManager as ScriptSelectorPullManager;
use Bitrix\Crm\Copilot\CallAssessment\Enum\AvailabilityType;
use Bitrix\Crm\Copilot\PullManager;
use Bitrix\Crm\Feature;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\Model\QueueTable;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Context;
use Bitrix\Crm\Traits\Singleton;
use Bitrix\Main\Application;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ORM\Data\AddResult;
use Bitrix\Main\ORM\Data\Result;
use Bitrix\Main\ORM\Objectify\Collection;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\ORM\Query\QueryHelper;
use Bitrix\Main\Type\DateTime;
use CCrmOwnerType;

final class CopilotCallAssessmentController
{
	use Singleton;

	private const DEFAULT_SELECT = [
		'*',
		'CLIENT_TYPES.CLIENT_TYPE_ID',
		'AVAILABILITY_DATA.START_POINT',
		'AVAILABILITY_DATA.END_POINT',
		'AVAILABILITY_DATA.WEEKDAY_TYPE',
	];

	private const DISTINCT_BORDERS_CACHE_TTL = 86400;
	private const BORDER_MIN = 0;
	private const BORDER_MAX = 100;

	public function updateDescription(int $assessmentId, string $description): Result
	{
		if ($assessmentId <= 0)
		{
			return (new Result())->addError(new Error('Invalid assessment id'));
		}

		return CopilotCallAssessmentTable::update($assessmentId, [
			'DESCRIPTION' => $description,
		]);
	}

	public function add(CallAssessmentItem $callAssessmentItem): AddResult
	{
		$result = CopilotCallAssessmentTable::add($this->getFields($callAssessmentItem));
		if ($result->isSuccess())
		{
			$modifyClientResult = $this->modifyClientTypeIds(
				$result->getId(),
				$callAssessmentItem->getClientTypeIds(),
				false,
			);
			if (!$modifyClientResult->isSuccess())
			{
				$result->addErrors($modifyClientResult->getErrors());
			}


			$modifyAvailabilityResult = $this->modifyAvailability(
				$result->getId(),
				$callAssessmentItem->getAvailabilityType(),
				$callAssessmentItem->getAvailabilityData(),
				false,
			);
			if (!$modifyAvailabilityResult->isSuccess())
			{
				$result->addErrors($modifyAvailabilityResult->getErrors());
			}
		}

		return $result;
	}

	public function update(int $id, CallAssessmentItem $callAssessmentItem, ?Context $context = null): Result
	{
		$result = CopilotCallAssessmentTable::update($id, $this->getFields($callAssessmentItem));

		if ($result->isSuccess())
		{
			$modifyClientResult = $this->modifyClientTypeIds(
				$id,
				$callAssessmentItem->getClientTypeIds(),
			);
			if (!$modifyClientResult->isSuccess())
			{
				$result->addErrors($modifyClientResult->getErrors());
			}

			$modifyAvailabilityResult = $this->modifyAvailability(
				$id,
				$callAssessmentItem->getAvailabilityType(),
				$callAssessmentItem->getAvailabilityData(),
			);
			if (!$modifyAvailabilityResult->isSuccess())
			{
				$result->addErrors($modifyAvailabilityResult->getErrors());
			}

			if ($context)
			{
				(new PullManager())->sendUpdateAssessmentPullEvent($id, [
					'eventId' => $context->getEventId(),
				]);
			}

			(new ScriptSelectorPullManager())->dispatchUpdateById($id);
		}

		return $result;
	}

	public function getList(array $params = []): Collection
	{
		$select = $params['select'] ?? self::DEFAULT_SELECT;
		$filter = $this->excludeGeneratingFromFilter($params['filter'] ?? []);
		$order = $params['order'] ?? [
			'ID' => 'DESC',
		];
		$offset = $params['offset'] ?? 0;
		$limit = $params['limit'] ?? 10;

		$query = CopilotCallAssessmentTable::query()
			->setSelect($select)
			->setFilter($filter)
			->setOrder($order)
			->setOffset($offset)
			->setLimit($limit)
		;

		return $this->decompose($query);
	}

	public function getById(int $id): ?CopilotCallAssessment
	{
		if ($id <=0)
		{
			return null;
		}

		return CopilotCallAssessmentTable::query()
			->setSelect(self::DEFAULT_SELECT)
			->setFilter(['=ID' => $id])
			->exec()
			->fetchObject()
		;
	}

	public function getTotalCount(array $filter = []): int
	{
		return CopilotCallAssessmentTable::query()
			->setFilter($this->excludeGeneratingFromFilter($filter))
			->queryCountTotal()
		;
	}

	private function excludeGeneratingFromFilter(array $filter): array
	{
		if (!array_key_exists('=STATUS', $filter) && !array_key_exists('STATUS', $filter))
		{
			$filter['!=STATUS'] = CallAssessmentItem::STATUS_GENERATING_FROM_DIALOG;
		}

		return $filter;
	}

	public function delete(int $id): ?Result
	{
		if ($id <= 0)
		{
			return null;
		}

		if ($this->hasAssessmentCalls($id))
		{
			return (new Result())->addError(
				new Error(
					Loc::getMessage(
						'COPILOT_CALL_ASSESSMENT_CONTROLLER_HAS_ASSESSMENTED_CALLS',
						['#COPILOT_NAME#' => AIManager::getCopilotName()],
					),
				),
			);
		}

		CopilotCallAssessmentClientTypeController::getInstance()->deleteByAssessmentId($id);
		CopilotCallAssessmentAvailabilityController::getInstance()->deleteByAssessmentId($id);
		CopilotCallAssessmentCriteriaController::getInstance()->deleteByAssessmentId($id);

		// clean jobs if needed
		QueueTable::deleteByItem(
			new ItemIdentifier(CCrmOwnerType::CopilotCallAssessment, $id),
		);

		return CopilotCallAssessmentTable::delete($id);
	}

	public function getCurrentAvailableAssessmentFilter(): array
	{
		if (!Feature::enabled(Feature\CopilotCallAssessmentAvailability::class))
		{
			return [];
		}

		$assessmentIds = CopilotCallAssessmentAvailabilityController::getInstance()->getCurrentAvailableAssessmentIds();
		// Every caller reads an empty filter as "no restriction", so nothing available right now must still
		// leave the always active settings as the only choice instead of opening up the scheduled ones.
		if (empty($assessmentIds))
		{
			return ['=AVAILABILITY_TYPE' => AvailabilityType::ALWAYS_ACTIVE->value];
		}

		return [
			'LOGIC' => 'OR',
			'=AVAILABILITY_TYPE' => AvailabilityType::ALWAYS_ACTIVE->value,
			'@ID' => $assessmentIds,
		];
	}

	public function syncBordersToAll(?int $lowBorder, ?int $highBorder): void
	{
		$validLowBorder = $this->validateBorder($lowBorder, CallAssessmentItem::LOW_BORDER_DEFAULT);
		$validHighBorder = $this->validateBorder($highBorder, CallAssessmentItem::HIGH_BORDER_DEFAULT);

		foreach ($this->getDistinctBorders() as $border)
		{
			if (
				(int)$border['LOW_BORDER'] !== $validLowBorder
				|| (int)$border['HIGH_BORDER'] !== $validHighBorder
			)
			{
				$this->applyBordersToAll($validLowBorder, $validHighBorder);

				return;
			}
		}
	}

	/**
	 * @return array{lowBorder: int, highBorder: int}|null
	 */
	public function getMasterBorders(): ?array
	{
		$row = CopilotCallAssessmentTable::query()
			->setSelect(['LOW_BORDER', 'HIGH_BORDER'])
			->setLimit(1)
			->setCacheTtl(self::DISTINCT_BORDERS_CACHE_TTL)
			->fetch()
		;

		if (!$row)
		{
			return null;
		}

		return [
			'lowBorder' => (int)$row['LOW_BORDER'],
			'highBorder' => (int)$row['HIGH_BORDER'],
		];
	}

	private function validateBorder(?int $value, int $default): int
	{
		if ($value === null || $value < self::BORDER_MIN || $value > self::BORDER_MAX)
		{
			return $default;
		}

		return $value;
	}

	/**
	 * @return array<int, array{LOW_BORDER: int, HIGH_BORDER: int}>
	 */
	private function getDistinctBorders(): array
	{
		return CopilotCallAssessmentTable::query()
			->setSelect(['LOW_BORDER', 'HIGH_BORDER'])
			->addGroup('LOW_BORDER')
			->addGroup('HIGH_BORDER')
			->setCacheTtl(self::DISTINCT_BORDERS_CACHE_TTL)
			->fetchAll()
		;
	}

	private function applyBordersToAll(int $lowBorder, int $highBorder): void
	{
		Application::getConnection()->queryExecute(sprintf(
			'UPDATE %s SET LOW_BORDER = %d, HIGH_BORDER = %d',
			CopilotCallAssessmentTable::getTableName(),
			$lowBorder,
			$highBorder,
		));

		CopilotCallAssessmentTable::cleanCache();
	}

	/*
	 * based on the Bitrix\Main\ORM\Query\QueryHelper::decompose,
	 * but currently it does not support sorting. ticket #204966
	 */
	private function decompose(Query $query): ?Collection
	{
		$entity = $query->getEntity();
		$queryClass = $entity->getDataClass()::getQueryClass();
		$runtimeChains = $query->getRuntimeChains() ?? [];
		$primaryNames = $entity->getPrimaryArray();
		$originalSelect = $query->getSelect();

		// select distinct primary
		$query->setSelect($entity->getPrimaryArray());
		$query->setDistinct();

		$rows = $query->fetchAll();

		// return empty result
		if (empty($rows))
		{
			return $query->getEntity()->createCollection();
		}

		// reset query
		$query = new $queryClass($entity);
		$query->setSelect($originalSelect);
		$query->where(QueryHelper::getPrimaryFilter($primaryNames, $rows));

		foreach ($runtimeChains as $chain)
		{
			$query->registerChain('runtime', $chain);
		}

		/** @var Collection $collection query data */
		$collection = $query->fetchCollection();

		$sortedCollection = $query->getEntity()->createCollection();
		foreach ($rows as $row)
		{
			$sortedCollection?->add($collection->getByPrimary($row));
		}

		return $sortedCollection;
	}

	private function getFields(CallAssessmentItem $callAssessmentItem): array
	{
		return [
			'TITLE' => $callAssessmentItem->getTitle(),
			'DESCRIPTION' => $callAssessmentItem->getDescription(),
			'PROMPT' => $callAssessmentItem->getPrompt(),
			'IS_HINT_ENABLED' => $callAssessmentItem->isHintEnabled(),
			'GIST' => $callAssessmentItem->getGist(),
			'CALL_TYPE' => $callAssessmentItem->getCallTypeId(),
			'AUTO_CHECK_TYPE' => $callAssessmentItem->getAutoCheckTypeId(),
			'IS_ENABLED' => $callAssessmentItem->isEnabled(),
			'IS_DEFAULT' => $callAssessmentItem->isDefault(),
			'IS_AI_IMPROVEMENT_ENABLED' => $callAssessmentItem->isAiImprovementEnabled(),
			'JOB_ID' => $callAssessmentItem->getJobId(),
			'STATUS' => $callAssessmentItem->getStatus(),
			'CODE' => $callAssessmentItem->getCode(),
			'LOW_BORDER' => $callAssessmentItem->getLowBorder(),
			'HIGH_BORDER' => $callAssessmentItem->getHighBorder(),
			'AVAILABILITY_TYPE' => $callAssessmentItem->getAvailabilityType(),
			'UPDATED_AT' => new DateTime(),
			'UPDATED_BY_ID' => Container::getInstance()->getContext()->getUserId(),
		];
	}

	private function modifyClientTypeIds(int $assessmentId, array $clientTypeIds, bool $deleteOldRecords = true): Result
	{
		$controller = CopilotCallAssessmentClientTypeController::getInstance();

		if ($deleteOldRecords)
		{
			$controller->deleteByAssessmentId($assessmentId);
		}

		foreach ($clientTypeIds as $clientTypeId)
		{
			$addResult = $controller->add($assessmentId, $clientTypeId);
			if (!$addResult->isSuccess())
			{
				return $addResult;
			}
		}

		return new Result();
	}

	private function modifyAvailability(
		int $assessmentId,
		string $availabilityType,
		array $availabilityData,
		bool $deleteOldRecords = true,
	): Result
	{
		if (!Feature::enabled(Feature\CopilotCallAssessmentAvailability::class))
		{
			return new Result();
		}

		$controller = CopilotCallAssessmentAvailabilityController::getInstance();

		if ($deleteOldRecords)
		{
			$controller->deleteByAssessmentId($assessmentId);
		}

		if (!AvailabilityType::isExtendedAvailabilityType($availabilityType))
		{
			return new Result();
		}

		foreach ($availabilityData as $row)
		{
			$addResult = $controller->add($assessmentId, $row);
			if (!$addResult->isSuccess())
			{
				return $addResult;
			}
		}

		return new Result();
	}

	private function hasAssessmentCalls(int $assessmentId): bool
	{
		$isEmpty = AiQualityAssessmentController::getInstance()->getList([
			'select' => ['ID'],
			'filter' => ['=ASSESSMENT_SETTING_ID' => $assessmentId],
			'limit' => 1,
		])->isEmpty();

		return !$isEmpty;
	}
}
