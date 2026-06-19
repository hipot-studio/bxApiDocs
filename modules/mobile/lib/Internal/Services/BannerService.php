<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Internal\Services;

use Bitrix\Main\Engine\CurrentUser;

class BannerService
{
	private const OPTION_CATEGORY = 'mobile_banner';

	public function isVisible(string $code): bool
	{
		return !$this->isDismissed($code);
	}

	public function dismiss(string $code): void
	{
		\CUserOptions::SetOption(
			self::OPTION_CATEGORY,
			$code,
			true,
			false,
			$this->getUserId(),
		);
	}

	private function isDismissed(string $code): bool
	{
		return (bool)\CUserOptions::GetOption(
			self::OPTION_CATEGORY,
			$code,
			false,
			$this->getUserId(),
		);
	}

	private function getUserId(): int
	{
		return (int)CurrentUser::get()->getId();
	}
}
