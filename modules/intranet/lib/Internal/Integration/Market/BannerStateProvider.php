<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Market;

use Bitrix\Main\Loader;
use Bitrix\Main\Type\Date;
use Bitrix\Market\Subscription;
use Bitrix\Market\Subscription\Status;
use Bitrix\Market\Subscription\Trial;

class BannerStateProvider
{
	public function hasSubscription(): bool
	{
		$status = $this->getSubscriptionStatus();

		return $status !== null && $status !== Status::NOT_EXIST;
	}

	public function isSubscriptionActive(): bool
	{
		$status = $this->getSubscriptionStatus();

		return $status !== null && in_array($status, [Status::ACTIVE, Status::PRE_EXPIRED], true);
	}

	public function isTrialAvailable(): bool
	{
		return Loader::includeModule('market') && Trial::isAvailable();
	}

	public function getFinishDate(): ?Date
	{
		return Loader::includeModule('market') ? Subscription::getFinishDate() : null;
	}

	private function getSubscriptionStatus(): ?string
	{
		return Loader::includeModule('market') ? Status::get() : null;
	}
}
