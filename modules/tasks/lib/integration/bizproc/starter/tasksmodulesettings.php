<?php

namespace Bitrix\Tasks\Integration\Bizproc\Starter;

use Bitrix\Bizproc\Starter\Dto\TriggerDescriptorDto;
use Bitrix\Bizproc\Starter\ModuleSettings;
use Bitrix\Main\Localization\Loc;
use Bitrix\Tasks\Integration\Bizproc\Document\Task;
use Bitrix\Ui\Public\Enum\IconSet\Outline;

if (!\Bitrix\Main\Loader::includeModule('bizproc'))
{
	return;
}

final class TasksModuleSettings extends ModuleSettings
{
	private const CREATE_DOCUMENT_TRIGGER = 'TasksCreateTrigger';

	public function isAutomationFeatureEnabled(): bool
	{
		return \Bitrix\Tasks\Integration\Bizproc\Automation\Factory::canUseAutomation();
	}

	public function getCreateDocumentTrigger(): ?TriggerDescriptorDto
	{
		if (!TasksCreateTriggerFeature::isEnabled())
		{
			return null;
		}

		return new TriggerDescriptorDto(
			triggerType: self::CREATE_DOCUMENT_TRIGGER,
			title: Loc::getMessage('TASKS_BP_STARTER_CREATE_DOCUMENT_TRIGGER'),
			icon: Outline::TASK->name,
		);
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
