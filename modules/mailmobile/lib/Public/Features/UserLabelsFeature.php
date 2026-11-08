<?php

declare(strict_types=1);

namespace Bitrix\MailMobile\Public\Features;

use Bitrix\Mail\Helper\Label\LabelsFeature;
use Bitrix\Main\Config\Feature\AbstractFlag;
use Bitrix\Main\Config\Feature\Context;
use Bitrix\Main\Loader;

/**
 * Mobile view of the user labels of the mail module. The flag has no switch of its own: the option of
 * the mail module stays the single source of truth for the web and the mobile alike. It asks mail
 * directly, the same way FavoritesFeature does, so the flag carries no adapter of its own.
 */
final class UserLabelsFeature extends AbstractFlag
{
	public function getRequirements(): array
	{
		return [
			static fn (Context $context): bool => Loader::includeModule('mail')
				&& class_exists(LabelsFeature::class)
				&& LabelsFeature::isEnabled(),
		];
	}

	public function enabledByDefault(): bool
	{
		return true;
	}
}
