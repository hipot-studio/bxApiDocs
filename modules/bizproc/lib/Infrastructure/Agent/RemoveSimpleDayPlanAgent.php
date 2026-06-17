<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Infrastructure\Agent;

use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;

final class RemoveSimpleDayPlanAgent
{
	private const SYSTEM_CODE = 'bitrix_ai_day_plan_simple';
	private const OPTION_NAME = 'bitrix_ai_day_plan_simple_available';

	public static function getName(): string
	{
		return self::class . '::run();';
	}

	public static function run(): string
	{
		if (!Loader::includeModule('bizproc'))
		{
			return '';
		}

		$row = WorkflowTemplateTable::query()
			->setSelect(['ID'])
			->where('SYSTEM_CODE', self::SYSTEM_CODE)
			->setLimit(1)
			->fetch()
		;

		if ($row)
		{
			try
			{
				\CBPWorkflowTemplateLoader::delete((int)$row['ID']);
			}
			catch (\Throwable)
			{
			}
		}

		Option::delete('bizproc', ['name' => self::OPTION_NAME]);

		return '';
	}
}
