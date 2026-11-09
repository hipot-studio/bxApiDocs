<?php

namespace Bitrix\BIConnector\Access\Model;

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\Bitrix24\Feature;
use Bitrix\Main\Access\Event\EventDictionary;
use Bitrix\Main\Config\Option;
use Bitrix\Main\EventManager;
use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;

/** @internal */
final class DashboardAdmissionEnvironment
{
	public function hasAccessHandlers(): bool
	{
		$events = EventManager::getInstance();

		return
			(bool)$events->findEventHandlers(AccessController::class, EventDictionary::EVENT_ON_BEFORE_CHECK)
			|| (bool)$events->findEventHandlers(AccessController::class, EventDictionary::EVENT_ON_AFTER_CHECK)
		;
	}

	/** Call only after the real rule or system-plan callback has read its features. */
	public function hasSupportedFeatures(array $featureIds): bool
	{
		if (!ModuleManager::isModuleInstalled('bitrix24'))
		{
			return true;
		}
		if (!class_exists(Feature::class, false))
		{
			return false;
		}

		$filters = Feature::getFeaturesFilters();
		if ($filters === [])
		{
			return false;
		}
		foreach ($filters as $featureId => $featureFilters)
		{
			if (!is_string($featureId) || !is_array($featureFilters))
			{
				return false;
			}
		}
		foreach ($featureIds as $featureId)
		{
			if (array_key_exists($featureId, $filters) && $filters[$featureId] !== [])
			{
				return false;
			}
		}

		return true;
	}

	public function hasMarketTrialExtensions(): bool
	{
		if (ModuleManager::isModuleInstalled('bitrix24'))
		{
			return false;
		}

		$path = Loader::getLocal('modules/main/option_triggers.php');
		if ($path !== false && $path !== Loader::getDocumentRoot() . '/bitrix/modules/main/option_triggers.php')
		{
			return true;
		}

		$events = EventManager::getInstance();

		return
			(bool)$events->findEventHandlers('main', 'OnAfterSetOption_~mp24_used_trial')
			|| (bool)$events->findEventHandlers('main', 'OnAfterSetOption')
		;
	}

	/** Call only with a receipt of the original Client::isSubscriptionAvailable branch. */
	public function canRepeatMarketTrialRead(): bool
	{
		if (ModuleManager::isModuleInstalled('bitrix24'))
		{
			return true;
		}

		return
			Option::get('main', '~mp24_paid', 'N') !== 'T'
			|| Option::get('main', '~mp24_used_trial', 'N') === 'Y'
		;
	}
}
