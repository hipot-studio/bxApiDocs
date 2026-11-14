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

Loc::loadMessages(__FILE__);

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
		return $permissions->isCrmAdmin() && $this->isDocumentGeneratorAvailable;
	}

	public function checkAvailability(UserPermissions $permissions): Result
	{
		if (!$permissions->entityType()->canReadSomeItemsInCrm())
		{
			return (new Result())->addError(
				new Error(
					Loc::getMessage('CRM_SETTINGS_HUB_DOCUMENTNUMERATORS_NO_ACCESS'),
					'DOCUMENTNUMERATORS_NO_ACCESS',
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
		return new Component(
			'bitrix:crm.document_numerators.list',
			[],
			'.default',
		);
	}
}
