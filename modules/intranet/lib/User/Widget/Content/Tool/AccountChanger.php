<?php

declare(strict_types=1);

namespace Bitrix\Intranet\User\Widget\Content\Tool;

use Bitrix\Intranet\Internal\Integration;
use Bitrix\Intranet\User;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\Loader;
use CSocServBitrix24Net;

class AccountChanger extends BaseTool
{
	public static function isAvailable(User $user): bool
	{
		return ModuleManager::isModuleInstalled('bitrix24') || (new Integration\Im\Context())->isDesktop();
	}

	public function getConfiguration(): array
	{
		$paths = $this->getPaths();

		return [
			'type' => $this->getType(),
			'title' => Loc::getMessage('INTRANET_USER_WIDGET_CONTENT_TOOL_ACCOUNT_CHANGER_TITLE'),
			'path' => $paths['passportPath'],
			'loginPath' => $paths['loginPath'],
		];
	}

	public function getName(): string
	{
		return 'accountChanger';
	}

	private function getType(): string
	{
		if ((new Integration\Im\Context())->isDesktop())
		{
			return 'desktop';
		}

		if (ModuleManager::isModuleInstalled('bitrix24'))
		{
			return 'network';
		}

		return 'unavailable';
	}

	private function getPaths(): array
	{
		if ($this->getType() === 'network' && Loader::includeModule('socialservices'))
		{
			$networkPath = rtrim(CSocServBitrix24Net::NETWORK_URL, '/');

			return [
				'passportPath' => $networkPath . '/passport/view/',
				'loginPath' => $networkPath . '/portal/list/',
			];
		}

		return [
			'passportPath' => '',
			'loginPath' => '',
		];
	}
}
