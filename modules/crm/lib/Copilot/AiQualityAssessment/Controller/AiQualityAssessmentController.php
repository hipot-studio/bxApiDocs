<?php

namespace Bitrix\Crm\Copilot\AiQualityAssessment\Controller;

use Bitrix\Crm\Copilot\AiQualityAssessment\Entity\AiQualityAssessmentItem;
use Bitrix\Crm\Copilot\AiQualityAssessment\Entity\AiQualityAssessmentTable;
use Bitrix\Crm\Copilot\AiQualityAssessment\RatingCalculator;
use Bitrix\Crm\Copilot\CallAssessment\Entity\CopilotCallAssessmentTable;
use Bitrix\Crm\Traits\Singleton;
use Bitrix\Main\Entity\ReferenceField;
use Bitrix\Main\ORM\Data\AddResult;
use Bitrix\Main\ORM\Data\UpdateResult;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Objectify\Collection;
use Bitrix\Main\ORM\Objectify\EntityObject;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Type\DateTime;

final class AiQualityAssessmentController
{
	use Singleton;

	private const HISTORY_LIMIT = 100;

	public function add(AiQualityAssessmentItem $item): AddResult
	{
		return AiQualityAssessmentTable::add($this->getFields($item));
	}

	public function update(int $id, AiQualityAssessmentItem $item): UpdateResult
	{
		return AiQualityAssessmentTable::update($id, $this->getFields($item));
	}

	public function getList(array $params = []): Collection
	{
		$select = $params['select'] ?? ['*'];
		$filter = $params['filter'] ?? [];
		$order = $params['order'] ?? [
			'ID' => 'DESC',
		];

		$offset = $params['offset'] ?? 0;
		$limit = $params['limit'] ?? 10;

		$cacheTtl = $params['cache']['ttl'] ?? 0;

		$query = AiQualityAssessmentTable::query()
			->setSelect($select)
			->setFilter($filter)
			->setOrder($order)
			->setOffset($offset)
			->setLimit($limit)
			->setCacheTtl($cacheTtl)
		;

		return $query->exec()->fetchCollection();
	}

	public function getById(int $id): ?EntityObject
	{
		return AiQualityAssessmentTable::getById($id)?->fetchObject();
	}

	public function getByActivityIdAndJobId(int $activityId, ?int $jobId = null): ?array
	{
		if ($activityId <= 0)
		{
			return null;
		}

		$select = $this->getCallQualitySelect();
		$filter = [
			'=ACTIVITY_TYPE' => AiQualityAssessmentTable::ACTIVITY_TYPE_CALL,
			'=ACTIVITY_ID' => $activityId,
		];

		if (isset($jobId))
		{
			$filter['=JOB_ID'] = $jobId;
		}
		else
		{
			$filter['=USE_IN_RATING'] = true;
		}

		$result = AiQualityAssessmentTable::getList([
			'select' => $select,
			'filter' => $filter,
			'runtime' => $this->getCallQualityRuntime(),
			'order' => ['ID' => 'DESC'],
			'limit' => 1,
		])->fetch();

		return is_array($result) ? $result : null;
	}

	public function getHistoryByActivityId(int $activityId): array
	{
		if ($activityId <= 0)
		{
			return [];
		}

		return AiQualityAssessmentTable::getList([
			'select' => $this->getCallQualityHistorySelect(),
			'filter' => [
				'=ACTIVITY_TYPE' => AiQualityAssessmentTable::ACTIVITY_TYPE_CALL,
				'=ACTIVITY_ID' => $activityId,
			],
			'runtime' => $this->getCallQualityRuntime(),
			'order' => [
				'CREATED_AT' => 'DESC',
			],
			'limit' => self::HISTORY_LIMIT,
		])->fetchAll();
	}

	public function getCountByFilter(array $filter = []): ?int
	{
		return (int)(AiQualityAssessmentTable::query()
			->setFilter($filter)
			->queryCountTotal())
		;
	}

	/**
	 * @param int[] $assessmentSettingIds
	 * @return array<int, int>
	 */
	public function countByAssessmentSettingIds(array $assessmentSettingIds): array
	{
		$assessmentSettingIds = array_values(array_filter(
			array_map('intval', $assessmentSettingIds),
			static fn(int $id) => $id > 0,
		));
		if (empty($assessmentSettingIds))
		{
			return [];
		}

		$rows = AiQualityAssessmentTable::query()
			->setSelect(['ASSESSMENT_SETTING_ID'])
			->addSelect(new ExpressionField('CNT', 'COUNT(*)'))
			->whereIn('ASSESSMENT_SETTING_ID', $assessmentSettingIds)
			->where('USE_IN_RATING', true)
			->where('ACTIVITY_TYPE', AiQualityAssessmentTable::ACTIVITY_TYPE_CALL)
			->setGroup(['ASSESSMENT_SETTING_ID'])
			->fetchAll()
		;

		$result = [];
		foreach ($rows as $row)
		{
			$result[(int)$row['ASSESSMENT_SETTING_ID']] = (int)$row['CNT'];
		}

		return $result;
	}

	/**
	 * @return list<array{ID:int, ASSESSMENT:int, ASSESSMENT_SETTING_ID:int}>
	 */
	public function getRecentForManager(int $managerId, int $limit): array
	{
		if ($managerId <= 0 || $limit <= 0)
		{
			return [];
		}

		return $this->ratedCallsQuery($managerId)
			->setSelect(['ID', 'ASSESSMENT', 'ASSESSMENT_SETTING_ID'])
			->setOrder(['ID' => 'DESC'])
			->setLimit($limit)
			->fetchAll()
		;
	}

	public function getAssessmentIdsForManager(
		int $managerId,
		array $order,
		int $limit,
		?DateTime $since = null,
	): array
	{
		if ($managerId <= 0 || $limit <= 0)
		{
			return [];
		}

		$query = $this->ratedCallsQuery($managerId)
			->setSelect(['ID'])
			->setOrder($order)
			->setLimit($limit)
		;

		if ($since !== null)
		{
			$query->where('CREATED_AT', '>', $since);
		}

		return array_map(static fn(array $row): int => (int)$row['ID'], $query->fetchAll());
	}

	private function ratedCallsQuery(int $managerId): Query
	{
		return AiQualityAssessmentTable::query()
			->where('RATED_USER_ID', $managerId)
			->where('USE_IN_RATING', true)
			->where('ACTIVITY_TYPE', AiQualityAssessmentTable::ACTIVITY_TYPE_CALL)
		;
	}

	public function getNewAvgAssessmentValue(int $userId, int $assessment): int
	{
		return (new RatingCalculator())->calculateRating($userId, $assessment);
	}

	public function getPrevAvgAssessmentValue(int $userId): int
	{
		return (new RatingCalculator())->getPrevRating($userId);
	}

	private function getFields(AiQualityAssessmentItem $item): array
	{
		return [
			'ACTIVITY_TYPE' => $item->getActivityType(),
			'ACTIVITY_ID' => $item->getActivityId(),
			'ASSESSMENT_SETTING_ID' => $item->getAssessmentSettingId(),
			'JOB_ID' => $item->getJobId(),
			'PROMPT' => $item->getPrompt(),
			'ASSESSMENT' => $item->getAssessment(),
			'ASSESSMENT_AVG' => $item->getAssessmentAvg(),
			'USE_IN_RATING' => $item->isUseInRating(),
			'RATED_USER_ID' => $item->getRatedUserId(),
			'MANAGER_USER_ID' => $item->getManagerUserId(),
			'RATED_USER_CHAT_ID' => $item->getRatedUserChatId(),
			'MANAGER_USER_CHAT_ID' => $item->getManagerUserChatId(),
			'CRITERIA_DATA' => $item->getCriteriaData(),
		];
	}

	private function getCallQualitySelect(): array
	{
		return [
			'ID',
			'CREATED_AT',
			'ASSESSMENT_SETTING_ID',
			'JOB_ID',
			'PROMPT',
			'ASSESSMENT',
			'ASSESSMENT_AVG',
			'RATED_USER_ID',
			'USE_IN_RATING',
			'LOW_BORDER' => 'SETTINGS.LOW_BORDER',
			'HIGH_BORDER' => 'SETTINGS.HIGH_BORDER',
			'ASSESSMENT_SETTINGS_STATUS' => 'SETTINGS.STATUS',
			'TITLE' => 'SETTINGS.TITLE',
			'IS_ENABLED' => 'SETTINGS.IS_ENABLED',
			'ACTUAL_PROMPT' => 'SETTINGS.PROMPT',
			'SOURCE_SETTINGS_ID' => 'SETTINGS.ID',
			'SOURCE_CODE' => 'SETTINGS.CODE',
			'PROMPT_UPDATED_AT' => 'SETTINGS.UPDATED_AT',
		];
	}

	private function getCallQualityHistorySelect(): array
	{
		return [
			...$this->getCallQualitySelect(),
			'CRITERIA_DATA',
		];
	}

	private function getCallQualityRuntime(): array
	{
		return [
			new ReferenceField(
				'SETTINGS',
				CopilotCallAssessmentTable::class,
				['=ref.ID' => 'this.ASSESSMENT_SETTING_ID'],
				['join_type' => 'LEFT'],
			),
		];
	}
}
