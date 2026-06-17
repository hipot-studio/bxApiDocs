<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Repository\DayPlanBot;

use Bitrix\Bizproc\Api\Enum\Template\WorkflowTemplateType;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTriggerTable;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateUserDataTable;

class DayPlanBotRepository
{
	private const USER_DATA_TYPE = 'day_plan_bot';

	public function findTriggerByType(int $templateId, string $triggerType): ?array
	{
		$row = WorkflowTemplateTriggerTable::query()
			->setSelect(['TEMPLATE_ID', 'APPLY_RULES', 'MODULE_ID', 'ENTITY', 'DOCUMENT_TYPE'])
			->where('TEMPLATE_ID', $templateId)
			->where('TRIGGER_TYPE', $triggerType)
			->setLimit(1)
			->exec()
			->fetch()
		;

		return $row ?: null;
	}

	public function findActiveTemplateConstants(int $templateId): ?array
	{
		$row = WorkflowTemplateTable::query()
			->setSelect(['ID', 'CONSTANTS'])
			->where('ID', $templateId)
			->where('ACTIVE', 'Y')
			->where('TYPE', WorkflowTemplateType::Nodes->value)
			->whereNull('SYSTEM_CODE')
			->exec()
			->fetch()
		;

		if (!$row)
		{
			return null;
		}

		$constants = $row['CONSTANTS'] ?? [];

		return is_array($constants) ? $constants : null;
	}

	public function findUserDataByEntityIds(array $entityIds): ?array
	{
		$row = WorkflowTemplateUserDataTable::query()
			->setSelect(['ID', 'TEMPLATE_ID', 'VALUE'])
			->where('TYPE', self::USER_DATA_TYPE)
			->whereIn('ENTITY_ID', $entityIds)
			->where('NAME', 'bot_id')
			->setOrder(['TEMPLATE_ID' => 'DESC'])
			->setLimit(1)
			->exec()
			->fetch()
		;

		return $row ?: null;
	}

	public function updateUserDataValue(int $id, string $value): void
	{
		WorkflowTemplateUserDataTable::update($id, ['VALUE' => $value]);
	}

	public function deleteUserData(int $templateId): void
	{
		WorkflowTemplateUserDataTable::deleteByFilter([
			'=TEMPLATE_ID' => $templateId,
			'=TYPE' => self::USER_DATA_TYPE,
		]);
	}

	public function addUserData(array $rows): void
	{
		if (!empty($rows))
		{
			WorkflowTemplateUserDataTable::addMulti($rows, true);
		}
	}
}
