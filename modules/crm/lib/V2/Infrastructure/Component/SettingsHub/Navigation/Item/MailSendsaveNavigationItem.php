<?php

namespace Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\Item;

use Bitrix\Crm\Service\Router\Component\Component;
use Bitrix\Crm\Service\Router\Contract;
use Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\SettingsHubWrappedNavigationItem;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Web\Uri;

final class MailSendsaveNavigationItem implements SettingsHubWrappedNavigationItem
{
	public function __construct(
		private bool $isMailAvailable,
		private int $mailBoxId,
		private bool $mailBoxExists,
		private Uri $staticPageUrl,
	)
	{
	}

	public function id(): string
	{
		return 'mail-sendsave';
	}

	public function isVisible(UserPermissions $permissions): bool
	{
		return $permissions->isCrmAdmin()
			&& $this->isMailAvailable
			&& $this->mailBoxId > 0
			&& $this->mailBoxExists;
	}

	public function canRead(UserPermissions $permissions): bool
	{
		return true;
	}

	public function urlQueryParameters(): array
	{
		return [];
	}

	public function component(HttpRequest $request): Contract\Component
	{
		$returnUrl = clone $this->staticPageUrl;
		$returnUrl->addParams([
			'IFRAME' => 'Y',
			'IFRAME_TYPE' => 'SIDE_SLIDER',
		]);

		return new Component(
			'bitrix:crm.config.sendsave',
			[
				'ENABLE_CONTROL_PANEL' => false,
				'PATH_TO_SS_CONFIG' => $returnUrl->getUri(),
			],
			'.default',
		);
	}
}
