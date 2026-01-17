<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die;
}

use Bitrix\Crm\Component\Base;
use Bitrix\Crm\Integration\Bitrix24\License;
use Bitrix\Crm\Integration\Bitrix24Manager;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Loader;

class CrmAutomatedSolutionNotifyComponent extends Base
{
	private const DAYS_LEFT = 20;
	private ?string $licenseType = null;
	private ?int $licenseDaysLeft = null;
	private bool $marketplaceIsDemo = false;
	private ?int $marketplaceDaysLeft = null;
	private ?int $daysLeft = null;
	private bool $isShowedToday = false;
	private ?string $priority = null;

	private const PRIORITY_LICENSE = 'license';
	private const PRIORITY_MARKETPLACE = 'marketplace';

	public function executeComponent(): void
	{
		if (!Loader::includeModule('bitrix24') || !$this->hasImportedAutomatedSolutions())
		{
			return;
		}

		$this->init();
		$this->initParams();

		if ($this->daysLeft === null || $this->daysLeft  > self::DAYS_LEFT || $this->isShowedToday)
		{
			return;
		}

		$this->arResult['daysLeft'] = $this->daysLeft;
		$this->arResult['priority'] = $this->priority;
		$this->arResult['licenseType'] = $this->licenseType;

		$this->includeComponentTemplate();
	}

	private function hasImportedAutomatedSolutions(): bool
	{
		return Container::getInstance()->getAutomatedSolutionManager()->hasImportedAutomatedSolutions();
	}

	protected function init(): void
	{
		$this->licenseType = CBitrix24::getLicenseType();
		$license = new License();
		$this->licenseDaysLeft = $license->getDaysLeft();

		$client = new Bitrix\Crm\Integration\Rest\Marketplace\Client();
		$this->marketplaceIsDemo = $client->isSubscriptionDemo();
		$this->marketplaceDaysLeft = $client->getDaysLeft();
	}

	private function initParams(): void
	{
		$isDemoLicense = Bitrix24Manager::hasDemoLicense();

		if ($isDemoLicense && $this->marketplaceIsDemo)
		{
			if ($this->licenseDaysLeft < $this->marketplaceDaysLeft)
			{
				$this->daysLeft = $this->licenseDaysLeft;
				$this->priority = self::PRIORITY_LICENSE;
			}
			else
			{
				$this->daysLeft = $this->marketplaceDaysLeft;
				$this->priority = self::PRIORITY_MARKETPLACE;
			}
		}
		elseif ($isDemoLicense)
		{
			$this->daysLeft = $this->licenseDaysLeft;
			$this->priority = self::PRIORITY_LICENSE;
		}
		elseif ($this->marketplaceIsDemo)
		{
			$this->daysLeft = $this->marketplaceDaysLeft;
			$this->priority = self::PRIORITY_MARKETPLACE;
		}

		$lastShowDateTimestamp = $this->getLastShowDate()?->getTimestamp() ?? 0;
		$this->isShowedToday = (new DateTime())->getTimestamp() - $lastShowDateTimestamp <= 86400;
	}

	private function getLastShowDate(): ?DateTime
	{
		$option = CUserOptions::GetOption('crm', 'automated_solution_imported', []);

		if (isset($option['lastShowDate']))
		{
			return (new DateTime())->setTimestamp($option['lastShowDate']);
		}

		return null;
	}
}
