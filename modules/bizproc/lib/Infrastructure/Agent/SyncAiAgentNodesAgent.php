<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Infrastructure\Agent;

use Bitrix\Bizproc\Public\Service\AiAgent\RegionAvailabilityServiceInterface;
use Bitrix\Main\DI\ServiceLocator;

use Bitrix\Bizproc\Api\Enum\Template\WorkflowTemplateSection;

final class SyncAiAgentNodesAgent extends SyncSystemNodesAgent
{
	public static function runAgent(): string
	{
		if (!ServiceLocator::getInstance()->get(RegionAvailabilityServiceInterface::class)->isAvailable())
		{
			return self::class . '::runAgent();';
		}

		return parent::runAgent();
	}

	protected static function getSectionId(): string
	{
		return WorkflowTemplateSection::AiAgent->value;
	}
}
