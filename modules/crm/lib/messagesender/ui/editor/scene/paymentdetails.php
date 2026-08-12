<?php

namespace Bitrix\Crm\MessageSender\UI\Editor\Scene;

use Bitrix\MessageService\Public\UI\MessageEditor\Context;
use Bitrix\MessageService\Public\UI\SenderCode;

final class PaymentDetails extends BaseScene
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
			if ($provider->getId() === 'crmValues')
			{
				return [$provider];
			}
		}

		return [];
	}

	public function filterViewChannels(array $viewChannels, Context $context): array
	{
		$viewChannels = parent::filterViewChannels($viewChannels, $context);

		return array_filter(
			$viewChannels,
			static function (\Bitrix\MessageService\Public\UI\MessageEditor\ViewChannel $channel): bool {
				$backend = $channel->getBackend();
				$senderCode = $backend->getSenderCode();

				return (
					$senderCode === SenderCode::BITRIX24
					|| ($senderCode === SenderCode::SMS_PROVIDER && !$backend->isTemplatesBased())
				);
			},
		);
	}
}
