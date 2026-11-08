<?php

namespace Bitrix\Crm\Service\Timeline\AiReportDrawer\Dto;

use Bitrix\Crm\Activity\Provider\OpenLine;

final readonly class ActivityContext
{
	public DrawerRequest $originalRequest;

	public function __construct(
		public DrawerRequest $request,
		public array $activity,
		public ?array $clientData,
		public ?array $responsibleData,
		public int $currentUserId = 0,
		?DrawerRequest $originalRequest = null,
	)
	{
		$this->originalRequest = $originalRequest ?? $request;
	}

	public function isOpenLineActivity(): bool
	{
		return ($this->activity['PROVIDER_ID'] ?? null) === OpenLine::getId();
	}
}
