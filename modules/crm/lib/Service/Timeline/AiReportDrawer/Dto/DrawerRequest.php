<?php

namespace Bitrix\Crm\Service\Timeline\AiReportDrawer\Dto;

final readonly class DrawerRequest
{
	public function __construct(
		public int $activityId,
		public int $ownerTypeId,
		public int $ownerId,
		public ?int $jobId = null,
		public ?int $assessmentSettingsId = null,
		public ?string $targetBlock = null,
	)
	{
	}
}
