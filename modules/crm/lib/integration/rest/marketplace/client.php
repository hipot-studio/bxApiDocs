<?php

namespace Bitrix\Crm\Integration\Rest\Marketplace;

use Bitrix\Crm\Integration;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use Bitrix\Rest\Marketplace;

final class Client
{
	private bool $isAvailable;

	public function __construct()
	{
		$this->isAvailable = Loader::includeModule('rest');
	}

	public function isSubscriptionDemo(): bool
	{
		return $this->isAvailable && Marketplace\Client::isSubscriptionDemo();
	}

	public function isSubscriptionAvailable(): bool
	{
		return $this->isAvailable && Marketplace\Client::isSubscriptionAvailable();
	}

	public function getDaysLeft(): ?int
	{
		$finalDate = $this->getSubscriptionFinalDate();
		$currentDate = (new DateTime())->getTimestamp();

		if (!$finalDate)
		{
			return null;
		}

		return (int)ceil(($finalDate->getTimestamp() - $currentDate) / 60 / 60 / 24);
	}

	public function getSubscriptionFinalDate(): ?\Bitrix\Main\Type\Date
	{
		if (!$this->isAvailable)
		{
			return null;
		}

		return Marketplace\Client::getSubscriptionFinalDate();
	}
}
