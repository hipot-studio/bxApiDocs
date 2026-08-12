<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Service\AiAgent;

use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

class NodeAvailabilityService implements NodeAvailabilityServiceInterface
{
	private const REQUIRED_MODULE_ID = 'aiassistant';
	private const UNAVAILABLE_ERROR_CODE = 'BIZPROC_AI_AGENT_NODE_UNAVAILABLE';

	public function __construct(
		private readonly RegionAvailabilityServiceInterface $regionAvailabilityService,
	)
	{
	}

	public function isAvailable(): bool
	{
		return $this->isRequiredModuleAvailable()
			&& $this->regionAvailabilityService->isAvailable();
	}

	public function getUnavailableError(): Error
	{
		return new Error(
			Loc::getMessage('BIZPROC_AI_AGENT_NODE_UNAVAILABLE_ERROR') ?? '',
			self::UNAVAILABLE_ERROR_CODE,
		);
	}

	protected function isRequiredModuleAvailable(): bool
	{
		return Loader::includeModule(self::REQUIRED_MODULE_ID);
	}
}
