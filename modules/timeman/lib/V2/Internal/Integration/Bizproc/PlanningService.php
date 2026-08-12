<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Internal\Integration\Bizproc;

use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;
use Bitrix\Main\Loader;

class PlanningService
{
	public static function existsSystemDayAgent(): bool
	{
		static $existsSystemDayAgent = null;

		if ($existsSystemDayAgent !== null)
		{
			return $existsSystemDayAgent;
		}

		if (!Loader::includeModule('bizproc'))
		{
			return $existsSystemDayAgent = false;
		}

		if (!class_exists(WorkflowTemplateTable::class))
		{
			return $existsSystemDayAgent = false;
		}

		$reportAgentsSystemCode = 'bitrix_ai_day_planner';

		return $existsSystemDayAgent = (bool)WorkflowTemplateTable::query()
			->setSelect(['ID'])
			->where('SYSTEM_CODE', $reportAgentsSystemCode)
			->setLimit(1)
			->fetch()
		;
	}
}
