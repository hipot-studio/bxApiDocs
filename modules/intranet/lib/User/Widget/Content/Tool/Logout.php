<?php

declare(strict_types=1);

namespace Bitrix\Intranet\User\Widget\Content\Tool;

use Bitrix\Intranet\User;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

class Logout extends BaseTool
{
	public static function isAvailable(User $user): bool
	{
		return true;
	}

	public function getConfiguration(): array
	{
		$authPage = $this->isExtranetSite() ? 'auth.php' : 'auth/';
		$logoutPath = SITE_DIR . $authPage . '?logout=yes';

		return [
			'title' => Loc::getMessage('INTRANET_USER_WIDGET_CONTENT_TOOL_LOGOUT_TITLE'),
			'path' => $logoutPath,
			'removeQueryParam' => ['logout', 'login', 'back_url_pub', 'user_lang'],
		];
	}

	public function getName(): string
	{
		return 'logout';
	}

	protected function isExtranetSite(): bool
	{
		return Loader::includeModule('extranet') && \CExtranet::IsExtranetSite();
	}
}
