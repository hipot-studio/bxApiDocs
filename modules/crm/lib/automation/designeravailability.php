<?php

namespace Bitrix\Crm\Automation;

use Bitrix\Bitrix24\Feature;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;

class DesignerAvailability
{
	public static function getInstance(): self
	{
		return ServiceLocator::getInstance()->get('crm.automation.designerAvailability');
	}

	public function isAvailable(): bool
	{
		return !$this->isBitrix24Installed() || $this->isFeatureEnabled();
	}

	protected function isBitrix24Installed(): bool
	{
		return Loader::includeModule('bitrix24');
	}

	protected function isFeatureEnabled(): bool
	{
		return Feature::isFeatureEnabled('crm_automation_designer');
	}
}
