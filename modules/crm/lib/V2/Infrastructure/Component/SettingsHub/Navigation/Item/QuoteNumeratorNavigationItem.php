<?php

namespace Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\Item;

use Bitrix\Crm\Service\Router;
use Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\SettingsHubUrlNavigationItem;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Main\Web\Uri;

final class QuoteNumeratorNavigationItem implements SettingsHubUrlNavigationItem
{
	private const NUMERATOR_TYPE = 'CRM_QUOTE';

	private int $numeratorId;

	public function __construct(private Router $router, ?int $numeratorId)
	{
		$this->numeratorId = max(0, $numeratorId ?? 0);
	}

	public function id(): string
	{
		return 'quote-numerator';
	}

	public function isVisible(UserPermissions $permissions): bool
	{
		return true;
	}

	public function canRead(UserPermissions $permissions): bool
	{
		return $permissions->isCrmAdmin();
	}

	public function url(): ?Uri
	{
		return $this->router->getNumeratorSettingsUrl($this->numeratorId, self::NUMERATOR_TYPE);
	}
}
