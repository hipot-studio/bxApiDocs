<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\SettingsHub;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Settings\InvoiceSettings;

class BusinessProcessEntityResolver
{
	public function isAllowed(string $entityId): bool
	{
		if (in_array($entityId, ['CRM_LEAD', 'CRM_CONTACT', 'CRM_COMPANY', 'CRM_DEAL', 'CRM_QUOTE'], true))
		{
			return true;
		}

		if ($entityId === 'CRM_SMART_INVOICE')
		{
			return InvoiceSettings::getCurrent()->isSmartInvoiceEnabled();
		}

		if (preg_match('/^CRM_DYNAMIC_([1-9][0-9]*)$/D', $entityId, $matches) !== 1)
		{
			return false;
		}

		$entityTypeId = filter_var($matches[1], FILTER_VALIDATE_INT);
		if ($entityTypeId === false || !\CCrmOwnerType::isPossibleDynamicTypeId($entityTypeId))
		{
			return false;
		}

		$container = $this->getContainer();
		$type = $container->getTypeByEntityTypeId($entityTypeId);
		if ($type === null || !$type->get('IS_INITIALIZED'))
		{
			return false;
		}

		return $container->getFactory($entityTypeId)?->isBizProcEnabled() ?? false;
	}

	protected function getContainer(): Container
	{
		return Container::getInstance();
	}
}
