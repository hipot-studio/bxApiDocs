<?php

namespace Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\Item;

use Bitrix\Crm\Service\Router\Component\Component;
use Bitrix\Crm\Service\Router\Contract;
use Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\SettingsHubWrappedNavigationItem;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Main\HttpRequest;

final class DocumentNumeratorsNavigationItem implements SettingsHubWrappedNavigationItem
{
	public function __construct(private bool $isDocumentGeneratorAvailable)
	{
	}

	public function id(): string
	{
		return 'document-numerators';
	}

	public function isVisible(UserPermissions $permissions): bool
	{
		return true;
	}

	public function canRead(UserPermissions $permissions): bool
	{
		return $this->isDocumentGeneratorAvailable
			&& $permissions->entityType()->canReadSomeItemsInCrm();
	}

	public function urlQueryParameters(): array
	{
		return [];
	}

	public function component(HttpRequest $request): Contract\Component
	{
		return new Component(
			'bitrix:crm.document_numerators.list',
			[],
			'.default',
		);
	}
}
