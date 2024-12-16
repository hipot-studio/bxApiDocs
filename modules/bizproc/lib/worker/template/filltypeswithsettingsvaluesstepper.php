<?php

namespace Bitrix\Bizproc\Worker\Template;

use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;
use Bitrix\Main;

class FillTypesWithSettingsValuesStepper extends Main\Update\Stepper
{
	protected static $moduleId = 'bizproc';
	private static $delay = 0;
	private const STEP_ROWS_LIMIT = 100;

	public function execute(array &$option)
	{
		$found = false;
		$result = \CBPWorkflowTemplateLoader::getList(
			['SORT'=>'ASC','NAME'=>'ASC'],
			['SETTINGS' => false],
			false,
			['nTopCount' => self::STEP_ROWS_LIMIT],
			['ID', 'AUTO_EXECUTE', 'TEMPLATE', 'MODULE_ID', 'ENTITY', 'DOCUMENT_TYPE']
		);

		while ($row = $result->fetch())
		{
			$found = true;
			try
			{
				$loader = \CBPWorkflowTemplateLoader::GetLoader();
				$loader->setTypeWithSettingsBeforeAdd($row);
				WorkflowTemplateTable::update(
					$row['ID'],
					['TYPE' => $row['TYPE'], 'SETTINGS' => $row['SETTINGS']],
				);
			}
			catch (\Throwable $e)
			{

			}
		}

		if ($found)
		{
			return self::CONTINUE_EXECUTION;
		}

		return self::FINISH_EXECUTION;
	}
}