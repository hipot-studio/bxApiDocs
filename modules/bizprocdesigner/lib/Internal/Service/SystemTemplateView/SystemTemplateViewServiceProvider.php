<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Service\SystemTemplateView;

use Bitrix\Bizproc\Public\Service\AiAgent\SystemTemplateViewService;
use Bitrix\Main\DI\ServiceLocator;

/**
 * The admission service of the read-only view of a system AI-agent template, or null while the installed
 * bizproc does not have it yet: the two modules ship in separate updates, and a consumer answers null with
 * the same refusal as any other one.
 */
class SystemTemplateViewServiceProvider
{
	public function get(): ?SystemTemplateViewService
	{
		if (!$this->isAvailable())
		{
			return null;
		}

		return ServiceLocator::getInstance()->get(SystemTemplateViewService::class);
	}

	protected function isAvailable(): bool
	{
		return class_exists(SystemTemplateViewService::class);
	}
}
