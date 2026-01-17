<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Service;

use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\AiAssistantDraftConverterService;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\AiAssistantDraftCreatorService;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\AiAssistantWorkflowTemplateConverterService;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\LastWorkflowService;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\UserBlockService;
use Bitrix\BizprocDesigner\Internal\Integration\Pull\BizprocDesignerPullManager;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Repository\AiAssistantDraftRepository;
use Bitrix\Main\DI\Exception\CircularDependencyException;
use Bitrix\Main\DI\Exception\ServiceNotFoundException;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Diag\Logger;
use Bitrix\Main\ObjectNotFoundException;
use Psr\Container\NotFoundExceptionInterface;

class Container
{
	public static function instance(): Container
	{
		return self::getService('bizprocdesigner.container');
	}

	/**
	 * @throws NotFoundExceptionInterface
	 * @throws CircularDependencyException
	 * @throws ObjectNotFoundException
	 * @throws ServiceNotFoundException
	 */
	private static function getService(string $name): mixed
	{
		$prefix = 'bizprocdesigner.';
		if (mb_strpos($name, $prefix) !== 0)
		{
			$name = $prefix . $name;
		}
		$locator = ServiceLocator::getInstance();
		return $locator->has($name)
			? $locator->get($name)
			: null
		;
	}

	/**
	 * @throws NotFoundExceptionInterface
	 * @throws CircularDependencyException
	 * @throws ObjectNotFoundException
	 * @throws ServiceNotFoundException
	 */
	public static function getPullManager(): BizprocDesignerPullManager
	{
		return self::getService('bizprocdesigner.pull.manager');
	}

	/**
	 * @throws NotFoundExceptionInterface
	 * @throws CircularDependencyException
	 * @throws ObjectNotFoundException
	 * @throws ServiceNotFoundException
	 */
	public static function getAiAssistantDraftRepository(): AiAssistantDraftRepository
	{
		return self::getService('bizprocdesigner.draft.repository');
	}

	/**
	 * @throws NotFoundExceptionInterface
	 * @throws CircularDependencyException
	 * @throws ObjectNotFoundException
	 * @throws ServiceNotFoundException
	 */
	public static function getAiAssistantDraftCreatorService(): AiAssistantDraftCreatorService
	{
		return self::getService('bizprocdesigner.ai.assistant.draft.service');
	}

	/**
	 * @throws NotFoundExceptionInterface
	 * @throws CircularDependencyException
	 * @throws ObjectNotFoundException
	 * @throws ServiceNotFoundException
	 */
	public static function getAiAssistantDraftConverterService(): AiAssistantDraftConverterService
	{
		return self::getService('bizprocdesigner.ai.assistant.draft.converter.service');
	}

	/**
	 * @throws NotFoundExceptionInterface
	 * @throws CircularDependencyException
	 * @throws ObjectNotFoundException
	 * @throws ServiceNotFoundException
	 */
	public static function getDefaultLogger(): Logger
	{
		return self::getService('bizprocdesigner.default.logger');
	}

	public static function getAiAssistantWorkflowTemplateConverterService(): AiAssistantWorkflowTemplateConverterService
	{
		return self::getService('bizprocdesigner.ai.assistant.workflow.converter.service');
	}

	public static function getAiAssistantLastWorkflowService(): LastWorkflowService
	{
		return self::getService('bizprocdesigner.ai.assistant.last.workflow.service');
	}

	public static function getAiAssistantUserBlockService(): UserBlockService
	{
		return self::getService('bizprocdesigner.ai.assistant.user.block.service');
	}
}
