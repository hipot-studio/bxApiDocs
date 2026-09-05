<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\Notifications;

use Bitrix\Bitrix24\Feature;
use Bitrix\Booking\Service\BookingFeature;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;

class AiCallAvailabilityService
{
	public function isAvailable(): bool
	{
		if ((bool)Option::get('booking', 'ai_call_message_sender_enabled', false) === true)
		{
			return true;
		}

		return (
			ModuleManager::isModuleInstalled('bizproc')
			&& ModuleManager::isModuleInstalled('aiassistant')
			&& (
				Loader::includeModule('voximplant')
				&& \CVoxImplantOutgoing::CanUseAiCall() === true
			)
			&& BookingFeature::isFeatureEnabled(BookingFeature::FEATURE_ID_NOTIFICATIONS_AI_CALL)
			&& (
				Loader::includeModule('bitrix24')
				&& Feature::isFeatureEnabled('crm_automation_designer')
			)
			&& (Application::getInstance()->getLicense()->getRegion() ?? 'en') === 'ru'
		);
	}
}
