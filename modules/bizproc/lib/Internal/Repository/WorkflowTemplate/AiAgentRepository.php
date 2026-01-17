<?php

namespace Bitrix\Bizproc\Internal\Repository\WorkflowTemplate;

use Bitrix\Main\Type\DateTime;
use Bitrix\Main\ORM\Data\UpdateResult;

use Bitrix\Bizproc\Api\Enum\Template\WorkflowTemplateType;
use Bitrix\Bizproc\WorkflowTemplateTable;

class AiAgentRepository
{
	/**
	 * @param list<int> $ids
	 *
	 * @return list<int>
	 */
	public function getOnlyExistAndAllowedToDeleteTemplateIds(
		array $ids,
		bool  $isUserAdmin,
		int   $userIdDeleteBy,
	): array
	{
		$ids = array_filter(array_map(static fn($id) => (int)$id, $ids));
		if (empty($ids))
		{
			return [];
		}

		$query = WorkflowTemplateTable::query()
			->whereIn('ID', $ids)
			->where('TYPE', WorkflowTemplateType::Nodes->value)
			->whereNull('SYSTEM_CODE')
			->setSelect(['ID'])
		;

		if (!$isUserAdmin)
		{
			$query
				->where('ACTIVATED_BY', $userIdDeleteBy)
			;
		}

		$rows = $query->fetchAll();

		return array_map(
			static fn($id) => (int)$id,
			array_column($rows, 'ID'),
		);
	}

	public function updateActivationTimestamp(int $templateId, DateTime $dateTime): UpdateResult
	{
		$fieldsToUpdate = [
			'ACTIVATED_AT' => $dateTime,
		];

		return WorkflowTemplateTable::update($templateId, $fieldsToUpdate);
	}
}