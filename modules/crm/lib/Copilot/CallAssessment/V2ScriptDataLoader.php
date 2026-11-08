<?php

namespace Bitrix\Crm\Copilot\CallAssessment;

use Bitrix\Crm\Copilot\AiQualityAssessment\Controller\AiQualityAssessmentController;
use Bitrix\Crm\Copilot\CallAssessment\Controller\CopilotCallAssessmentController;
use Bitrix\Crm\Copilot\CallAssessment\Controller\CopilotCallAssessmentCriteriaController;
use Bitrix\Crm\Copilot\CallAssessment\Enum\CallType;
use Bitrix\Crm\Copilot\CallAssessment\Enum\ClientType;

final class V2ScriptDataLoader
{
	public function loadById(int $id): ?array
	{
		if ($id <= 0)
		{
			return null;
		}

		$entity = CopilotCallAssessmentController::getInstance()->getById($id);
		if (!$entity)
		{
			return null;
		}

		$data = CallAssessmentItem::createFromEntity($entity)->toArray();
		$data['criteria'] = $this->loadCriteria($id);
		$data['filters'] = $this->buildFilters($data);
		$data['updatedAt'] = $entity->getUpdatedAt()?->getTimestamp();
		$data['processedCallsCount'] = $this->countProcessedCalls($id);
		$data['isGeneratedByCopilot'] = $entity->getJobId() > 0;

		return $data;
	}

	public function buildEmpty(string $defaultTitle): array
	{
		$data = CallAssessmentItem::createFromArray(['title' => $defaultTitle])->toArray();
		$data['prompt'] = '';
		$data['isHintEnabled'] = false;
		$data['hasHint'] = false;
		$data['clientTypeIds'] = [ClientType::ANY->value];
		$data['callTypeId'] = CallType::ALL->value;
		$data['criteria'] = [];
		$data['filters'] = $this->buildFilters($data);
		$data['updatedAt'] = null;
		$data['processedCallsCount'] = 0;
		$data['isGeneratedByCopilot'] = false;

		return $data;
	}

	private function countProcessedCalls(int $assessmentId): int
	{
		$counts = AiQualityAssessmentController::getInstance()
			->countByAssessmentSettingIds([$assessmentId])
		;

		return $counts[$assessmentId] ?? 0;
	}

	private function loadCriteria(int $assessmentId): array
	{
		$rows = CopilotCallAssessmentCriteriaController::getInstance()->getList([
			'filter' => [
				'=ASSESSMENT_ID' => $assessmentId,
			],
			'order' => [
				'SORT' => 'ASC',
				'ID' => 'ASC',
			],
		]);

		return array_map(
			static fn(array $row): array => [
				'id' => (int)$row['ID'],
				'title' => (string)$row['TITLE'],
				'description' => (string)$row['DESCRIPTION'],
				'sort' => (int)($row['SORT'] ?? 0),
			],
			$rows,
		);
	}

	private function buildFilters(array $data): array
	{
		$clientOptions = [];
		foreach (ClientType::toArray() as $value => $title)
		{
			if ($title === null)
			{
				continue;
			}

			$clientOptions[] = [
				'value' => $value,
				'label' => $title,
			];
		}

		$callOptions = [];
		foreach (CallType::toArray() as $value => $title)
		{
			if ($title === null)
			{
				continue;
			}

			$callOptions[] = [
				'value' => $value,
				'label' => $title,
			];
		}

		$clientTypeIds = $data['clientTypeIds'] ?? [];
		$callValue = (int)($data['callTypeId'] ?? CallType::ALL->value);

		return [
			[
				'code' => 'clients',
				'multi' => true,
				'values' => $clientTypeIds,
				'options' => $clientOptions,
				'highlightValues' => [ClientType::ANY->value],
			],
			[
				'code' => 'calls',
				'multi' => false,
				'value' => $callValue,
				'options' => $callOptions,
			],
		];
	}
}
