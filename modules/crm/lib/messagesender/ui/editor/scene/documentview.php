<?php

namespace Bitrix\Crm\MessageSender\UI\Editor\Scene;

use Bitrix\Crm\MessageSender\UI\Editor\ContentProvider;
use Bitrix\Crm\MessageSender\UI\Editor\Scene;
use Bitrix\Crm\MessageSender\UI\Editor\ViewChannel;
use Bitrix\Crm\MessageSender\UI\Taxonomy;

final class DocumentView extends Scene
{
	public const ID = 'crm.document.view';

	public function getId(): string
	{
		return self::ID;
	}

	public function filterViewChannels(array $viewChannels): array
	{
		return array_filter($viewChannels, static fn(ViewChannel $vc) =>
			!$vc->getBackend()->isTemplatesBased()
			&& (
				Taxonomy::isNotifications(...)
				|| Taxonomy::isSmsSender($vc->getBackend()))
		);
	}

	public function filterContentProviders(array $providers): array
	{
		return array_filter(
			$providers,
			static fn(ContentProvider $provider) => $provider->getKey() === 'crmValues',
		);
	}
}
