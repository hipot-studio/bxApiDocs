<?php

declare(strict_types=1);

namespace Bitrix\Intranet\User\Widget\Content;

use Bitrix\Intranet\User;
use Bitrix\Intranet\User\Widget\BaseContent;
use Bitrix\Main\Loader;

class PerfReview extends BaseContent
{
	public function getName(): string
	{
		return 'perfReview';
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
			'title' => 'Performance review',
		];
	}

	public static function isAvailable(): bool
	{
		if (!Loader::includeModule('stafftools'))
		{
			return false;
		}

		$isExtranetSite = Loader::includeModule('extranet') && \CExtranet::IsExtranetSite();
		$user = new User();

		return !$isExtranetSite && $user->isIntranet();
	}
}
