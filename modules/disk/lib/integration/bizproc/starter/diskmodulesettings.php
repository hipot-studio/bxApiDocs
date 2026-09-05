<?php

declare(strict_types=1);

namespace Bitrix\Disk\Integration\Bizproc\Starter;

use Bitrix\Bizproc\Starter\Dto\TriggerDescriptorDto;
use Bitrix\Bizproc\Starter\ModuleSettings;
use Bitrix\Disk\BizProcDocument;

final class DiskModuleSettings extends ModuleSettings
{
	public function isAutomationFeatureEnabled(): bool
	{
		return false;
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

	public function getCreateDocumentTrigger(): ?TriggerDescriptorDto
	{
		[, , $documentType] = $this->complexType;

		$normalized = ['disk', BizProcDocument::class, $documentType];

		$description = \CBPRuntime::getRuntime()->getActivityDescription('DiskFileCreateTrigger');
		$title = is_string($description['NAME'] ?? null) ? $description['NAME'] : null;

		return new TriggerDescriptorDto(
			triggerType: 'DiskFileCreateTrigger',
			title: $title,
			properties: ['Document' => implode('@', $normalized)],
		);
	}
}
