<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Entity\Portal;

use Bitrix\Main;
use Bitrix\Rest\Marketplace\Client;

class MarketSubscription
{
	private bool $isCloud;

	public function __construct()
	{
		$this->isCloud = Main\Loader::includeModule('bitrix24');
	}

	public function isEnabled(): bool
	{
		$optionValue = $this->getOptionValue('~mp24_paid', 'N');

		return $optionValue === 'T' || $optionValue === 'Y';
	}

	public function isPaid(): bool
	{
		return $this->getOptionValue('~mp24_paid', 'N') === 'Y';
	}

	public function isTrial(): bool
	{
		return $this->getOptionValue('~mp24_paid', 'N') === 'T';
	}

	public function isTrialUsed(): bool
	{
		return $this->getOptionValue('~mp24_used_trial', 'N') === 'Y';
	}

	public function getStartDate(): ?Main\Type\Date
	{
		$timestamp = (int)$this->getOptionValue('~mp24_cur_period_date', 0);
		if ($timestamp > 0)
		{
			return Main\Type\Date::createFromTimestamp($timestamp);
		}

		return null;
	}

	public function getFinalDate(): ?Main\Type\Date
	{
		$timestamp = (int)$this->getOptionValue('~mp24_paid_date', 0);
		if ($timestamp > 0)
		{
			return Main\Type\Date::createFromTimestamp($timestamp);
		}

		return null;
	}

	public function isActive(): bool
	{
		return Client::isSubscriptionAvailable();
	}

	public function toArray(): array
	{
		return [
			'isEnabled' => $this->isEnabled(),
			'isPaid' => $this->isPaid(),
			'isDemo' => $this->isTrial(),
			'isDemoUsed' => $this->isTrialUsed(),
			'isActive' => $this->isActive(),
			'startDate' => $this->getStartDate()?->getTimestamp() ?? 0,
			'finalDate' => $this->getFinalDate()?->getTimestamp() ?? 0,
		];
	}

	private function getOptionValue(string $optionName, null|int|string $defaultValue = null): null|int|string
	{
		$module = $this->isCloud ? 'bitrix24' : 'main';

		return Main\Config\Option::get($module, $optionName, $defaultValue);
	}
}
