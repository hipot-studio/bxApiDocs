<?php

declare(strict_types=1);

namespace Bitrix\Intranet\User\Widget\Content;

use Bitrix\Intranet;
use Bitrix\Intranet\User\Widget\BaseContent;
use Bitrix\Main\Loader;

class Extension extends BaseContent
{
	public function getName(): string
	{
		return 'extension';
	}

	public function getConfiguration(): array
	{
		if (!self::isAvailable())
		{
			return [
				'isAvailable' => false,
			];
		}

		return [
			'isAvailable' => true,
			...Intranet\Binding\Menu::getMenuItems(
				'top_panel',
				'user_menu',
				[
					'inline' => true,
					'context' => ['USER_ID' => $this->user->getId()],
				],
			),
		];
	}

	public static function isAvailable(): bool
	{
		$isExtranetSite = Loader::includeModule('extranet') && \CExtranet::IsExtranetSite();
		$user = new Intranet\User();

		return !$isExtranetSite && $user->isIntranet();
	}
}
