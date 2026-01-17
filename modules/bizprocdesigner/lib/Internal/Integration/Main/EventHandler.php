<?php

namespace Bitrix\BizprocDesigner\Internal\Integration\Main;


use Bitrix\BizProcDesigner\Internal\Integration\AiAssistant\Install\TriggerRegisterAgent;
use Bitrix\Main\Event;

class EventHandler
{
	public static function onAfterRegisterModule(Event $event): void
	{
		$moduleId = $event->getParameters()[0] ?? null;
		match ($moduleId)
		{
			'aiassistant' => self::handleAiAssistantModuleInstall(),
			default => null,
		};
	}

	private static function handleAiAssistantModuleInstall(): void
	{
		$agentName = TriggerRegisterAgent::execute();
		if ($agentName)
		{
			\CAgent::AddAgent(
				$agentName,
				module: 'bizprocdesigner',
				interval: 60,
				next_exec: ConvertTimeStamp(time() + \CTimeZone::GetOffset() + 60, 'FULL'),
				existError: false,
			);
		}
	}
}