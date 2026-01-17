<?php

namespace Bitrix\Crm\MessageSender\UI\Editor\Scene;

use Bitrix\Crm\MessageSender\UI\Editor\Scene;
use Bitrix\Crm\MessageSender\UI\Editor\ViewChannel;
use Bitrix\Crm\MessageSender\UI\Taxonomy;

final class ItemDetails extends Scene
{
	public const ID = 'crm.item.details';

	public function getId(): string
	{
		return self::ID;
	}

	public function filterViewChannels(array $viewChannels): array
	{
		return array_filter($viewChannels, static fn(ViewChannel $vc) => Taxonomy::isSmsSender($vc->getBackend()));
	}
}
