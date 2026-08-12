<?php

namespace Bitrix\Seo\Region;

use Bitrix\Seo\Analytics;
use Bitrix\Seo\LeadAds;
use Bitrix\Seo\Retargeting;

/**
 * Agent that shuts down the external VK integration contour (Lead Ads callback servers and
 * cloud-adv tokens) for portals moved out of the allowed region, keeping user CRM settings intact.
 */
class VkExternalDeactivationAgent
{
	public static function run(): string
	{
		if (VkAvailability::isAvailable())
		{
			return '';
		}

		if (!self::deactivateExternalCallbackServers())
		{
			return static::class . '::run();';
		}

		if (!self::revokeCloudAuthorizations())
		{
			return static::class . '::run();';
		}

		return '';
	}

	private static function deactivateExternalCallbackServers(): bool
	{
		$success = true;
		foreach (self::getRegisteredVkGroupIds() as $groupId)
		{
			try
			{
				if (!LeadAds\Service::unRegisterGroup(LeadAds\Service::TYPE_VKONTAKTE, (string)$groupId))
				{
					$success = false;
				}
			}
			catch (\Throwable)
			{
				$success = false;
			}
		}

		return $success;
	}

	private static function revokeCloudAuthorizations(): bool
	{
		$operations = [
			static fn() => LeadAds\Service::getInstance()->getGroupAuth(LeadAds\Service::TYPE_VKONTAKTE)?->removeAuth(),
			static fn() => Analytics\Service::removeAuth(Analytics\Service::TYPE_VKONTAKTE),
			static fn() => Analytics\Service::removeAuth(Analytics\Service::TYPE_VKADS),
			static fn() => Retargeting\Service::getAuthAdapter(Retargeting\Service::TYPE_VKONTAKTE)->removeAuth(),
		];

		$success = true;
		foreach ($operations as $operation)
		{
			$success = self::runExternalOperation($operation) && $success;
		}

		return $success;
	}

	private static function getRegisteredVkGroupIds(): array
	{
		$rows = LeadAds\Internals\CallbackSubscriptionTable::getList([
			'select' => ['GROUP_ID'],
			'filter' => ['=TYPE' => LeadAds\Service::TYPE_VKONTAKTE],
		])->fetchAll();

		return array_column($rows, 'GROUP_ID');
	}

	private static function runExternalOperation(callable $operation): bool
	{
		try
		{
			$operation();

			return true;
		}
		catch (\Throwable)
		{
			return false;
		}
	}
}
