<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Profile\View;

use Bitrix\Main\Localization\Loc;

final class ProfileView
{
	private function __construct(
		private readonly bool $systemUser,
		private readonly bool $reducedFields,
		private readonly bool $infoBanner,
		private readonly ?string $secondaryBadge,
	)
	{
	}

	public static function default(): self
	{
		return new self(
			systemUser: false,
			reducedFields: false,
			infoBanner: false,
			secondaryBadge: null,
		);
	}

	public static function systemUser(): self
	{
		return new self(
			systemUser: true,
			reducedFields: true,
			infoBanner: true,
			secondaryBadge: Loc::getMessage('INTRANET_PROFILE_VIEW_BADGE_SYSTEM_USER'),
		);
	}

	public function isSystemUser(): bool
	{
		return $this->systemUser;
	}

	public function hasReducedFields(): bool
	{
		return $this->reducedFields;
	}

	public function hasInfoBanner(): bool
	{
		return $this->infoBanner;
	}

	public function getSecondaryBadge(): ?string
	{
		return $this->secondaryBadge;
	}
}
