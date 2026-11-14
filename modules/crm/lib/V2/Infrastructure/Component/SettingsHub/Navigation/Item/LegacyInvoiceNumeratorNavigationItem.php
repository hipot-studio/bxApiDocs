<?php

namespace Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\Item;

use Bitrix\Crm\Service\Router;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\SettingsHubUrlNavigationItem;
use Bitrix\Main\Result;
use Bitrix\Main\Web\Uri;

final class LegacyInvoiceNumeratorNavigationItem implements SettingsHubUrlNavigationItem
{
	private const NUMERATOR_TYPE = 'CRM_INVOICE';

	private int $numeratorId;

	public function __construct(
		private Router $router,
		?int $numeratorId,
		private bool $isOldInvoicesEnabled,
	)
	{
		$this->numeratorId = max(0, $numeratorId ?? 0);
	}

	public function id(): string
	{
		return 'legacy-invoice-numerator';
	}

	public function isVisible(UserPermissions $permissions): bool
	{
		return $permissions->isCrmAdmin() && $this->isOldInvoicesEnabled;
	}

	public function checkAvailability(UserPermissions $permissions): Result
	{
		return new Result();
	}

	public function url(): ?Uri
	{
		return $this->router->getNumeratorSettingsUrl($this->numeratorId, self::NUMERATOR_TYPE);
	}
}
