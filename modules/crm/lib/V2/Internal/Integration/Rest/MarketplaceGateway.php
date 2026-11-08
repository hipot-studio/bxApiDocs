<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\Rest;

use Bitrix\Main\Loader;
use Bitrix\Main\Web\Uri;
use Bitrix\Rest\Marketplace\Url;

class MarketplaceGateway
{
	public function isRestAvailable(): bool
	{
		return Loader::includeModule('rest');
	}

	public function hasConfigurationPlacementUrl(): bool
	{
		return method_exists(Url::class, 'getConfigurationPlacementUrl');
	}
	public function getConfigurationPlacementUrl(): Uri
	{
		return new Uri(Url::getConfigurationPlacementUrl('crm_settings'));
	}
}
