<?php

declare(strict_types=1);

namespace Bitrix\MailMobile\Public\Features;

use Bitrix\Mail\Helper\Config;
use Bitrix\Main\Config\Feature\AbstractFlag;
use Bitrix\Main\Config\Feature\Context;
use Bitrix\Main\Loader;

final class FavoritesFeature extends AbstractFlag
{
	public function getRequirements(): array
	{
		return [
			static fn (Context $context): bool => Loader::includeModule('mail')
				&& Config\Feature::isMailListImprovementsAvailable(),
		];
	}

	public function enabledByDefault(): bool
	{
		return true;
	}
}
