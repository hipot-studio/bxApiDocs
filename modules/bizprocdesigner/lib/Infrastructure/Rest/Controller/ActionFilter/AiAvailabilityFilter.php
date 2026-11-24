<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Rest\Controller\ActionFilter;

use Bitrix\BizprocDesigner\Internal\Config\Feature;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\AiAvailabilityService;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\DI\Exception\CircularDependencyException;
use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\BizprocDesigner\Infrastructure\Rest\Exception\FeatureUnavailableException;

final class AiAvailabilityFilter extends Base
{
	/**
	 * @throws FeatureUnavailableException
	 * @throws ArgumentException
	 * @throws CircularDependencyException
	 * @throws ObjectNotFoundException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function onBeforeAction(Event $event): ?EventResult
	{
		$userId = (int)CurrentUser::get()->getId();

		// Both branches answer about the feature and neither about rights on a template, so both carry
		// the code of the feature: a caller that cannot tell them apart keeps asking a portal that will
		// never answer {@see FeatureUnavailableException}.
		if (!(new AiAvailabilityService())->isAvailableForUser($userId))
		{
			throw new FeatureUnavailableException();
		}

		if (!Feature::instance()->isExternalAiAgentAccessible())
		{
			throw new FeatureUnavailableException();
		}

		return null;
	}
}
