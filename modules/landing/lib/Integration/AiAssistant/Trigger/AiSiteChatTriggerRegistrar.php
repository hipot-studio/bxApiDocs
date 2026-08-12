<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Trigger;

use Bitrix\AiAssistant\Trigger\Dto\ModuleTriggerDto;
use Bitrix\AiAssistant\Trigger\Dto\TriggerRegistration;
use Bitrix\AiAssistant\Trigger\Enum\ModulesEnum;
use Bitrix\AiAssistant\Trigger\TriggerRegistry;
use Bitrix\Landing\Integration\AiAssistant\Trigger\Action\AiSiteChatTrigger;
use Bitrix\Main\Loader;

final class AiSiteChatTriggerRegistrar
{
	public static function register(): bool
	{
		if (
			!Loader::includeModule('landing')
			|| !Loader::includeModule('aiassistant')
			|| !class_exists(TriggerRegistry::class)
			|| !class_exists(TriggerRegistration::class)
			|| !class_exists(ModuleTriggerDto::class)
			|| !class_exists(AiSiteChatTrigger::class)
			|| !enum_exists(ModulesEnum::class)
		)
		{
			return false;
		}

		TriggerRegistry::register(
			new TriggerRegistration(
				className: AiSiteChatTrigger::class,
				moduleName: 'landing',
				modulesForStart: [new ModuleTriggerDto(ModulesEnum::ALL)],
				priority: 300,
				userLockTime: 60,
			),
		);

		return true;
	}

	public static function registerAgent(): string
	{
		if (!self::register())
		{
			return __METHOD__ . '();';
		}

		return '';
	}
}
