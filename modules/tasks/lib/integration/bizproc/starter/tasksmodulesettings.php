<?php

namespace Bitrix\Tasks\Integration\Bizproc\Starter;

use Bitrix\Bizproc\Starter\ModuleSettings;
use Bitrix\Tasks\Integration\Bizproc\Document\Task;

if (!\Bitrix\Main\Loader::includeModule('bizproc'))
{
	return;
}

final class TasksModuleSettings extends ModuleSettings
{
	public function isAutomationFeatureEnabled(): bool
	{
		return \Bitrix\Tasks\Integration\Bizproc\Automation\Factory::canUseAutomation();
	}

	public function isScriptFeatureEnabled(): bool
	{
		return false;
	}

	public function isAutomationLimited(): bool
	{
		return false;
	}

	public function isAutomationOverLimited(): bool
	{
		return false;
	}

	public function getDocumentStatusFieldName(): ?string
	{
		if (Task::isProjectTask($this->complexType[2]) || Task::isScrumProjectTask($this->complexType[2]))
		{
			return 'STAGE_ID';
		}

		return 'STATUS';
	}
}
