<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Digest\Provider;

use Bitrix\BIConnector\Configuration\Feature;
use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;
use Bitrix\BIConnector\Superset\Selfhost\SupersetHostMode;
use Bitrix\Bizproc\Public\Dto\AiAgent\SystemTemplateSetupAvailability;
use Bitrix\Bizproc\Public\Service\AiAgent\SystemTemplateSetupAvailabilityService;
use Bitrix\Main\Loader;

/**
 * Portal conditions the setup of a digest depends on, apart from the report. Every answer is cheap: the
 * surfaces offering the setup ask on each render, and the full resolution of the system template is left
 * to the launch.
 * Left open for extension so tests can replace it with a stub.
 */
class DigestSetupPortalConditions
{
	public function isCloudPortal(): bool
	{
		return Loader::includeModule('bitrix24') && SupersetHostMode::isCloud();
	}

	public function isBuilderEnabled(): bool
	{
		return Feature::isBuilderEnabled();
	}

	public function isAiEnabled(): bool
	{
		return Feature::isBitrixGptBiConstructorEnabled();
	}

	public function isBiReady(): bool
	{
		return SupersetInitializer::isSupersetReady();
	}

	public function isSystemTemplateAvailable(string $systemCode, int $userId): bool
	{
		if (!Loader::includeModule('bizproc'))
		{
			return false;
		}

		return (new SystemTemplateSetupAvailabilityService())->check($systemCode, $userId)->state
			=== SystemTemplateSetupAvailability::READY
		;
	}
}
