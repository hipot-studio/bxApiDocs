<?php

namespace Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\Item;

use Bitrix\Crm\Service\Router\Component\Component;
use Bitrix\Crm\Service\Router\Contract;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\SettingsHubWrappedNavigationItem;
use Bitrix\Main\Error;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Web\Uri;

Loc::loadMessages(__FILE__);

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
		return $permissions->isCrmAdmin() && $this->mailBoxId > 0;
	}

	public function checkAvailability(UserPermissions $permissions): Result
	{
		if (!$this->isMailAvailable)
		{
			return (new Result())->addError(
				new Error(
					Loc::getMessage('CRM_SETTINGS_HUB_NAVIGATION_MAIL_SENDSAVE_MAIL'),
					'CRM_SETTINGS_HUB_NAVIGATION_MAIL_SENDSAVE_MAIL',
				),
			);
		}

		if (!$this->mailBoxExists)
		{
			return (new Result())->addError(
				new Error(
					Loc::getMessage('CRM_SETTINGS_HUB_NAVIGATION_MAIL_SENDSAVE_MAILBOX'),
					'CRM_SETTINGS_HUB_NAVIGATION_MAIL_SENDSAVE_MAILBOX',
				),
			);
		}

		return new Result();
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
