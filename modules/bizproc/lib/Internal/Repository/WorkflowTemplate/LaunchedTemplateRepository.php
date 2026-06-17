<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Repository\WorkflowTemplate;

use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;
use Bitrix\Bizproc\Workflow\Template\WorkflowTemplateSettingsTable;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;

/**
 * Fetches IDs of "launched" (child) templates created from system templates.
 *
 * Relationship:
 *   System template (SYSTEM_CODE = 'bitrix_ai_day_plan')
 *     └── Child template (SYSTEM_CODE IS NULL)
 *           └── Setting: ORIGIN_SYSTEM_CODE = 'bitrix_ai_day_plan'
 */
class LaunchedTemplateRepository
{
	/**
	 * @return int[]
	 */
	public function getIdsBySystemTemplateId(int $systemTemplateId): array
	{
		$systemCode = $this->resolveSystemCode($systemTemplateId);
		if ($systemCode === null)
		{
			return [];
		}

		return $this->getIdsBySystemCodes([$systemCode]);
	}

	/**
	 * @param string[] $systemCodes e.g. ['bitrix_ai_day_plan', 'bitrix_ai_day_plan_simple']
	 * @return int[]
	 */
	public function getIdsBySystemCodes(array $systemCodes): array
	{
		$systemCodes = array_values(
			array_filter($systemCodes, static fn($code) => is_string($code) && $code !== ''),
		);

		if (empty($systemCodes))
		{
			return [];
		}

		$query = WorkflowTemplateTable::query()
			->setSelect(['ID'])
			->registerRuntimeField(
				'ORIGIN_SETTING',
				new Reference(
					'ORIGIN_SETTING',
					WorkflowTemplateSettingsTable::class,
					Join::on('this.ID', 'ref.TEMPLATE_ID'),
				),
			)
			->where('ORIGIN_SETTING.NAME', WorkflowTemplateSettingsTable::ORIGIN_SYSTEM_CODE)
			->whereNull('SYSTEM_CODE')
		;

		$query->whereIn('ORIGIN_SETTING.VALUE', $systemCodes);

		$result = [];
		$dbResult = $query->exec();
		while ($row = $dbResult->fetch())
		{
			$result[] = (int)$row['ID'];
		}

		return $result;
	}

	public function isLaunchedFrom(int $templateId, array $systemCodes): bool
	{
		$systemCodes = array_values(
			array_filter($systemCodes, static fn($code) => is_string($code) && $code !== ''),
		);

		if ($templateId <= 0 || empty($systemCodes))
		{
			return false;
		}

		$query = WorkflowTemplateSettingsTable::query()
			->setSelect(['ID'])
			->where('TEMPLATE_ID', $templateId)
			->where('NAME', WorkflowTemplateSettingsTable::ORIGIN_SYSTEM_CODE)
			->setLimit(1)
		;

		$query->whereIn('VALUE', $systemCodes);

		return $query->exec()->fetch() !== false;
	}

	private function resolveSystemCode(int $templateId): ?string
	{
		if ($templateId <= 0)
		{
			return null;
		}

		$row = WorkflowTemplateTable::query()
			->setSelect(['SYSTEM_CODE'])
			->where('ID', $templateId)
			->setLimit(1)
			->exec()
			->fetch()
		;

		$code = $row['SYSTEM_CODE'] ?? null;

		return is_string($code) && $code !== '' ? $code : null;
	}
}
