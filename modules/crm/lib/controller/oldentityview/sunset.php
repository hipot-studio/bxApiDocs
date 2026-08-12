<?php

namespace Bitrix\Crm\Controller\OldEntityView;

use Bitrix\Crm\Component\DisableHelpers\OldEntityViewDisableHelper;
use Bitrix\Crm\Controller\Base;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Settings\InvoiceSettings;
use Bitrix\Main\Engine\ActionFilter\Scope;
use Bitrix\Main\Error;

class Sunset extends Base
{
	public function enableNewCardLayoutAction(): bool
	{
		if (!Container::getInstance()->getUserPermissions()->isCrmAdmin())
		{
			$this->addError(new Error('You have no permission to perform this action'));

			return false;
		}

		OldEntityViewDisableHelper::migrateToNewLayout();

		return true;
	}

	public function disableOldInvoicesAction(): ?array
	{
		if (!Container::getInstance()->getUserPermissions()->isCrmAdmin())
		{
			$this->addError(new Error('You have no permission to perform this action'));

			return null;
		}

		InvoiceSettings::getCurrent()->setOldInvoicesEnabled(false);

		$redirectUri = Container::getInstance()->getRouter()
			->getItemListUrlInCurrentView(\CCrmOwnerType::SmartInvoice)
		;

		return [
			'redirectUrl' => $redirectUri !== null ? (string)$redirectUri : '',
		];
	}

	protected function getDefaultPreFilters(): array
	{
		$filters = parent::getDefaultPreFilters();
		$filters[] = new Scope(Scope::NOT_REST);

		return $filters;
	}
}
