<?php
declare(strict_types=1);

namespace Bitrix\BizProcDesigner\Internal\Integration\AiAssistant\Install;

use Bitrix\AiAssistant\Trigger\Dto\ModuleTriggerDto;
use Bitrix\AiAssistant\Trigger\Dto\RegisterTriggerDto;
use Bitrix\AiAssistant\Trigger\Enum\ModulesEnum;
use Bitrix\AiAssistant\Trigger\Service\TriggerManagerService;
use Bitrix\BizprocDesigner\Internal\Config\Feature;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Trigger\Action\FirstTimeWorkflowEditorOpenAction;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;

class TriggerRegisterAgent
{
	public static function execute(): string
	{
		if (!Loader::includeModule('aiassistant'))
		{
			return '';
		}

		if (!enum_exists('\Bitrix\AiAssistant\Trigger\Enum\ModulesEnum'))
		{
			return '';
		}

		if (ModulesEnum::tryFrom('bizprocdesigner') === null)
		{
			return '';
		}

		if (!Feature::instance()->isAiAssistantAvailable())
		{
			return '';
		}

		$allTriggersRegistered = true;
		foreach (self::getRegisterTriggerList() as $registerTriggerDto)
		{
			if (!self::registerOrUpdate($registerTriggerDto))
			{
				$allTriggersRegistered = false;
			}
		}

		return $allTriggersRegistered ? '' : self::getAgentName();
	}

	private static function registerOrUpdate(RegisterTriggerDto $registerTriggerDto): bool
	{
		$triggerManagerService = ServiceLocator::getInstance()->get(TriggerManagerService::class);
		[$isSuccess, $triggerId] = $triggerManagerService->register($registerTriggerDto);
		if (!$isSuccess && $triggerId)
		{
			$isSuccess = $triggerManagerService->update($registerTriggerDto);
		}

		return $isSuccess;
	}

	public static function getAgentName(): string
	{
		return self::class . '::execute();';
	}

	/**
	 * @return list<RegisterTriggerDto>
	 */
	private static function getRegisterTriggerList(): array
	{
		return [
			new RegisterTriggerDto(
				[
					(new ModuleTriggerDto(
						module: ModulesEnum::BizprocDesigner,
						url: '/bizprocdesigner/editor/' /* @todo get from config */
					)),
				],
				FirstTimeWorkflowEditorOpenAction::class,
				'bizprocdesigner',
				['UA'],
				1000,
				userLockTime: 60
			),
		];
	}
}
