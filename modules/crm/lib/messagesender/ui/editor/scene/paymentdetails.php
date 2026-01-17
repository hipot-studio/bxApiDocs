<?php

namespace Bitrix\Crm\MessageSender\UI\Editor\Scene;

use Bitrix\Crm\MessageSender\UI\Editor\Scene;

final class PaymentDetails extends Scene
{
	public const ID = 'crm.payment.details';

	public function getId(): string
	{
		return self::ID;
	}

	public function filterContentProviders(array $providers): array
	{
		foreach ($providers as $provider)
		{
			if ($provider->getKey() === 'crmValues')
			{
				return [$provider];
			}
		}

		return [];
	}

	public function filterViewChannels(array $viewChannels): array
	{
		return array_filter(
			$viewChannels,
			static function (\Bitrix\Crm\MessageSender\UI\Editor\ViewChannel $channel): bool {
				$backend = $channel->getBackend();

				return (
					\Bitrix\Crm\MessageSender\UI\Taxonomy::isNotifications($backend)
					|| (\Bitrix\Crm\MessageSender\UI\Taxonomy::isSmsSender($backend) && !$backend->isTemplatesBased())
				);
			},
		);
	}
}
