<?php

declare(strict_types=1);

namespace Bitrix\StaffTrack\Internal\Integration\Bizproc;

use Bitrix\Bizproc\Public\Service\AiAgent\RegionAvailabilityService;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;
use Bitrix\Main\Loader;

class PlanningService
{
	private const SYSTEM_CODE = 'bitrix_ai_day_planner';

	public static function isAiAgentRegionAvailable(): bool
	{
		if (!Loader::includeModule('bizproc'))
		{
			return false;
		}

		return (new RegionAvailabilityService())->isAvailable();
	}

	public static function existsSystemDayAgent(): bool
	{
		static $cached = null;

		if ($cached !== null)
		{
			return $cached;
		}

		if (!Loader::includeModule('bizproc'))
		{
			return $cached = false;
		}

		if (!class_exists(WorkflowTemplateTable::class))
		{
			return $cached = false;
		}

		return $cached = (bool)WorkflowTemplateTable::query()
			->setSelect(['ID'])
			->where('SYSTEM_CODE', self::SYSTEM_CODE)
			->setLimit(1)
			->fetch()
		;
	}
}
