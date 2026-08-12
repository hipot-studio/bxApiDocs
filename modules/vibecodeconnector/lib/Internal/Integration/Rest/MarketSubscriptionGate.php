<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Rest;

use Bitrix\Main;
use Bitrix\Rest\Engine\Access;
use Bitrix\Rest\Marketplace\Client;
use Bitrix\Vibecodeconnector\Internal\Exception\ProvisioningFailedException;

class MarketSubscriptionGate
{
	protected const SUBSCRIPTION_REQUIRED_REGION = ['ru', 'by'];
	protected Main\License $license;

	public function __construct(?Main\License $license = null)
	{
		$this->license = $license ?? Main\Application::getInstance()->getLicense();
	}

	public function ensureAvailable(): void
	{
		if (!Main\Loader::includeModule('rest'))
		{
			throw new ProvisioningFailedException('REST module is unavailable', 'REST_UNAVAILABLE');
		}

		if (!Access::isFeatureEnabled())
		{
			throw new ProvisioningFailedException('REST is unavailable on the current tariff', 'REST_UNAVAILABLE');
		}

		if (in_array($this->license->getRegion(), self::SUBSCRIPTION_REQUIRED_REGION, true)
			&& !Client::isSubscriptionAvailable())
		{
			throw new ProvisioningFailedException('Marketplace subscription required', 'SUBSCRIPTION_REQUIRED');
		}
	}
}
