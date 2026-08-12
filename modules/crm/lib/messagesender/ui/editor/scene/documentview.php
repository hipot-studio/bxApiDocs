<?php

namespace Bitrix\Crm\MessageSender\UI\Editor\Scene;

use Bitrix\MessageService\Public\UI\MessageEditor\ContentProvider;
use Bitrix\MessageService\Public\UI\MessageEditor\Context;
use Bitrix\MessageService\Public\UI\MessageEditor\ViewChannel;
use Bitrix\MessageService\Public\UI\SenderCode;

final class DocumentView extends BaseScene
{
	public const ID = 'crm.document.view';

	public function getId(): string
	{
		return self::ID;
	}

	public function filterViewChannels(array $viewChannels, Context $context): array
	{
		$viewChannels = parent::filterViewChannels($viewChannels, $context);

		return array_filter($viewChannels, static function (ViewChannel $vc): bool {
			$backend = $vc->getBackend();
			$senderCode = $backend->getSenderCode();

			return !$backend->isTemplatesBased() && ($senderCode === SenderCode::BITRIX24 || $senderCode === SenderCode::SMS_PROVIDER);
		});
	}

	public function filterContentProviders(array $providers): array
	{
		return array_filter(
			$providers,
			static fn(ContentProvider $provider) => $provider->getId() === 'crmValues',
		);
	}
}
