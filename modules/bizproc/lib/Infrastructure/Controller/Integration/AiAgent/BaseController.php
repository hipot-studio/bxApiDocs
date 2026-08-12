<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Infrastructure\Controller\Integration\AiAgent;

use Bitrix\Bizproc\Public\Service\AiAgent\RegionAvailabilityServiceInterface;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\Action;
use Bitrix\Main\Engine\JsonController;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;

abstract class BaseController extends JsonController
{
	public const ERROR_CODE_UNAVAILABLE_BY_REGION = 'AI_AGENTS_UNAVAILABLE_BY_REGION';

	protected function processBeforeAction(Action $action)
	{
		if (!ServiceLocator::getInstance()->get(RegionAvailabilityServiceInterface::class)->isAvailable())
		{
			$this->addError(new Error(
				Loc::getMessage('BIZPROC_AI_AGENT_REGION_AVAILABILITY_ERROR') ?? '',
				self::ERROR_CODE_UNAVAILABLE_BY_REGION,
			));

			return false;
		}

		return parent::processBeforeAction($action);
	}
}
