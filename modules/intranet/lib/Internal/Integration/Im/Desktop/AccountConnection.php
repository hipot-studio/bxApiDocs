<?php

declare(strict_types = 1);

namespace Bitrix\Intranet\Internal\Integration\Im\Desktop;

use Bitrix\Intranet\Composite\CacheProvider;
use Bitrix\Intranet\Internal\Integration\Im;
use Bitrix\Main\Application;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Page\Asset;
use Exception;

class AccountConnection
{
	public const CONNECTION_REQUIRED_SESSION_KEY = 'IM_DESKTOP_ACCOUNT_CONNECTION_REQUIRED';

	public function isAvailable(): bool
	{
		$context = new Im\Context();

		return $context->isDesktop() && !$context->isDesktopVersionOlderThan('20');
	}

	public function isRequired(): bool
	{
		return (bool)(Application::getInstance()->getKernelSession()[self::CONNECTION_REQUIRED_SESSION_KEY] ?? false);
	}

	public function handleAuthorizeUser(): void
	{
		if ($this->isAvailable())
		{
			$this->setRequired();
		}
	}

	/**
	 * @throws Exception
	 */
	public function addHeadScript(): void
	{
		Asset::getInstance()->addString($this->getScript());
		CacheProvider::deleteUserCache();
		$this->unsetRequired();
	}

	private function getScript(): string
	{
		$currentUser = CurrentUser::get();
		$login = \CUtil::JSEscape($currentUser->getLogin());
		$userLang = \CUtil::JSEscape(LANGUAGE_ID);

		return <<<HTML
			<script>
				if (typeof(BXDesktopSystem) !== 'undefined')
				{
					BXDesktopSystem.AccountConnect(location.hostname, "{$login}", 'https', "{$userLang}");
				}
			</script>
		HTML;
	}

	private function setRequired(): void
	{
		Application::getInstance()->getKernelSession()[self::CONNECTION_REQUIRED_SESSION_KEY] = true;
	}

	private function unsetRequired(): void
	{
		unset(Application::getInstance()->getKernelSession()[self::CONNECTION_REQUIRED_SESSION_KEY]);
	}
}
