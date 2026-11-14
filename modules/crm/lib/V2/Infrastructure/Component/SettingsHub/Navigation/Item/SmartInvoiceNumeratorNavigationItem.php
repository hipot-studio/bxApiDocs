<?php

namespace Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\Item;

use Bitrix\Crm\Service\Router;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\SettingsHubUrlNavigationItem;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Web\Uri;

Loc::loadMessages(__FILE__);

final class SmartInvoiceNumeratorNavigationItem implements SettingsHubUrlNavigationItem
{
	private const NUMERATOR_TYPE = 'CRM_SMART_INVOICE';

	private int $numeratorId;

	public function __construct(
		private Router $router,
		?int $numeratorId,
		private bool $isSmartInvoiceEnabled,
	)
	{
		$this->numeratorId = max(0, $numeratorId ?? 0);
	}

	public function id(): string
	{
		return 'smart-invoice-numerator';
	}

	public function isVisible(UserPermissions $permissions): bool
	{
		return $permissions->isCrmAdmin() && $this->isSmartInvoiceEnabled;
	}

	public function checkAvailability(UserPermissions $permissions): Result
	{
		$url = $this->url();
		if ($url === null || $url->getUri() === '')
		{
			return (new Result())->addError(
				new Error(
					Loc::getMessage('CRM_SETTINGS_HUB_NAVIGATION_SMART_INVOICE_NUMERATOR_URL'),
					'CRM_SETTINGS_HUB_NAVIGATION_SMART_INVOICE_NUMERATOR_URL',
				),
			);
		}

		return new Result();
	}

	public function url(): ?Uri
	{
		return $this->router->getNumeratorSettingsUrl($this->numeratorId, self::NUMERATOR_TYPE);
	}
}
