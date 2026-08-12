<?php

namespace Bitrix\Bizproc\Internal\Repository\WorkflowTemplate;

use Bitrix\Main\Type\DateTime;
use Bitrix\Main\ORM\Data\UpdateResult;

use Bitrix\Bizproc\Api\Enum\Template\WorkflowTemplateSection;
use Bitrix\Bizproc\Api\Enum\Template\WorkflowTemplateType;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateSectionTable;
use Bitrix\Bizproc\Workflow\Template\WorkflowTemplateSettingsTable;
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
		bool $isUserAdmin,
		int $userIdDeleteBy,
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

	/**
	 * Single source of truth ownership predicate for launched AI-agent templates.
	 * Mirrors the grid/delete criterion: launched copy (TYPE=Nodes, SYSTEM_CODE IS NULL),
	 * current user is the owner (ACTIVATED_BY) or an admin, optionally already started.
	 */
	public function canManageLaunchedTemplate(
		int $templateId,
		int $userId,
		bool $isUserAdmin,
		bool $requireStarted = false,
	): bool
	{
		if ($templateId <= 0)
		{
			return false;
		}

		$query = WorkflowTemplateTable::query()
			->where('ID', $templateId)
			->where('TYPE', WorkflowTemplateType::Nodes->value)
			->whereNull('SYSTEM_CODE')
			->setSelect(['ID'])
			->setLimit(1)
		;

		if (!$isUserAdmin)
		{
			$query->where('ACTIVATED_BY', $userId);
		}

		if ($requireStarted)
		{
			$query->whereNotNull('ACTIVATED_AT');
		}

		return !empty($query->fetch());
	}

	/**
	 * AI-agent template that may be used as a copyAndStart source: a Nodes template
	 * from the AI_AGENT section that is not a launched copy (ACTIVATED_AT IS NULL).
	 * Covers both a system AI-agent template (SYSTEM_CODE set) and a user-created one
	 * (SYSTEM_CODE IS NULL) added via the designer, and rejects launched copies, so the
	 * settings of an agent already activated by another user cannot be cloned by id.
	 *
	 * @return array{SYSTEM_CODE: ?string}|null null when $templateId is not a valid copy
	 *  source: a launched copy, a template of another type/section or a missing template.
	 */
	public function findCopyableAiAgentTemplate(int $templateId): ?array
	{
		if ($templateId <= 0)
		{
			return null;
		}

		$row = WorkflowTemplateSectionTable::query()
			->where('TEMPLATE_ID', $templateId)
			->where('SECTION_ID', WorkflowTemplateSection::AiAgent->value)
			->where('TEMPLATE.TYPE', WorkflowTemplateType::Nodes->value)
			->whereNull('TEMPLATE.ACTIVATED_AT')
			->setSelect(['ID', 'SYSTEM_CODE' => 'TEMPLATE.SYSTEM_CODE'])
			->setLimit(1)
			->fetch()
		;

		if (empty($row))
		{
			return null;
		}

		return ['SYSTEM_CODE' => $row['SYSTEM_CODE'] ?? null];
	}

	/**
	 * SYSTEM_CODE of a system AI-agent template (TYPE=Nodes, SYSTEM_CODE set),
	 * or null when it is not a system AI-agent template: a launched copy
	 * (SYSTEM_CODE IS NULL), a system template of another type (e.g.
	 * bitrix_bizproc_automation with TYPE robots) or a missing template.
	 * Mirror of canManageLaunchedTemplate on the SYSTEM_CODE side.
	 */
	public function getSystemAiAgentCode(int $templateId): ?string
	{
		if ($templateId <= 0)
		{
			return null;
		}

		return WorkflowTemplateTable::query()
			->where('ID', $templateId)
			->where('TYPE', WorkflowTemplateType::Nodes->value)
			->whereNotNull('SYSTEM_CODE')
			->setSelect(['SYSTEM_CODE'])
			->setLimit(1)
			->fetch()['SYSTEM_CODE'] ?? null
		;
	}

	public function saveOriginSystemCode(int $templateId, string $systemCode): void
	{
		WorkflowTemplateSettingsTable::add([
			'TEMPLATE_ID' => $templateId,
			'NAME' => WorkflowTemplateSettingsTable::ORIGIN_SYSTEM_CODE,
			'VALUE' => $systemCode,
		]);
	}

	public function updateActivationTimestamp(int $templateId, DateTime $dateTime): UpdateResult
	{
		$fieldsToUpdate = [
			'ACTIVATED_AT' => $dateTime,
		];

		return WorkflowTemplateTable::update($templateId, $fieldsToUpdate);
	}
}
