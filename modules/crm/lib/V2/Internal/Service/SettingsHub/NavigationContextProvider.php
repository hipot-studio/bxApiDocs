<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\SettingsHub;

use Bitrix\Crm\Automation;
use Bitrix\Crm\DbHelper;
use Bitrix\Crm\Exclusion;
use Bitrix\Crm\Service\Factory;
use Bitrix\Crm\Settings\InvoiceSettings;
use Bitrix\Main\Application;
use Bitrix\Main\DB\PgsqlConnection;
use Bitrix\Main\Loader;
use Bitrix\Main\Numerator\Numerator;

class NavigationContextProvider
{
	public function isAutomationAvailable(int $entityTypeId): bool
	{
		return Automation\Factory::isAutomationAvailable($entityTypeId);
	}

	public function isOrdersMode(): bool
	{
		return \CCrmSaleHelper::isWithOrdersMode();
	}

	public function isDocumentGeneratorAvailable(): bool
	{
		return Loader::includeModule('documentgenerator');
	}

	public function getSmartInvoiceNumerator(): ?array
	{
		return Numerator::getOneByType(Factory\SmartInvoice::NUMERATOR_TYPE);
	}

	public function getLegacyInvoiceNumerator(): ?array
	{
		return Numerator::getOneByType(REGISTRY_TYPE_CRM_INVOICE);
	}

	public function getQuoteNumerator(): ?array
	{
		return Numerator::getOneByType(REGISTRY_TYPE_CRM_QUOTE);
	}

	public function getInvoiceSettings(): InvoiceSettings
	{
		return InvoiceSettings::getCurrent();
	}

	public function getExclusionAccess(): Exclusion\Access
	{
		return Exclusion\Access::current();
	}

	public function getUserFieldEntityIds(): array
	{
		return array_values(array_column(\CCrmFields::GetEntityTypes(), 'ID'));
	}

	public function hasPgsqlConnection(): bool
	{
		return Application::getConnection() instanceof PgsqlConnection;
	}

	public function isPgsqlDb(): bool
	{
		return DbHelper::isPgSqlDb();
	}
}
