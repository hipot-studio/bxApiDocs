<?php

namespace Bitrix\Crm\MessageSender\UI\Editor\Scene;

use Bitrix\MessageService\Public\UI\MessageEditor\Context;
use Bitrix\MessageService\Public\UI\MessageEditor\ViewChannel;
use Bitrix\MessageService\Public\UI\SenderCode;

final class ItemDetails extends BaseScene
{
	public const ID = 'crm.item.details';

	public function getId(): string
	{
		return self::ID;
	}

	public function filterViewChannels(array $viewChannels, Context $context): array
	{
		$viewChannels = parent::filterViewChannels($viewChannels, $context);

		return array_filter(
			$viewChannels,
			static fn(ViewChannel $vc): bool => $vc->getBackend()->getSenderCode() === SenderCode::SMS_PROVIDER,
		);
	}
}
