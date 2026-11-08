<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Catalog\Item;

use Bitrix\Vibecodeconnector\Internal\Config\ModuleOptions;

final class CatalogItemSettings
{
	private const OPTION_CLICK_OPENS_CHAT = 'catalog_item_click_opens_chat';

	public function __construct(private readonly ModuleOptions $options = new ModuleOptions())
	{
	}

	public function isClickOpensChatEnabled(): bool
	{
		return $this->options->get(self::OPTION_CLICK_OPENS_CHAT, 'N') === 'Y';
	}
}
