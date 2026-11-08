<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation;

use Bitrix\Crm\Security\Role\Manage\Manager\AllSelection;
use Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\SettingsHubFactory;
use Bitrix\Crm\V2\Internal\Access\Currency\CurrencyAdminAccess;
use Bitrix\Crm\V2\Internal\Access\MailTemplate\MailTemplateEditorAccess;
use Bitrix\Crm\V2\Internal\Access\Measure\MeasureAccess;
use Bitrix\Crm\V2\Internal\Integration\Bizproc\RuntimeGateway;
use Bitrix\Crm\V2\Internal\Integration\Catalog\SettingsAccessGateway;
use Bitrix\Crm\V2\Internal\Integration\Intranet\ToolsGateway;
use Bitrix\Crm\V2\Internal\Integration\Mail\SettingsHubGateway;
use Bitrix\Crm\V2\Internal\Integration\Market\MarketplaceGateway;
use Bitrix\Crm\V2\Internal\Integration\MessageService\SmsSettingsGateway;
use Bitrix\Crm\V2\Internal\Integration\Rest;
use Bitrix\Crm\V2\Internal\Service\SettingsHub\BusinessProcessEntityResolver;
use Bitrix\Crm\V2\Internal\Service\SettingsHub\NavigationContextProvider;

class SettingsHubNavigationFactory
{
	public function __construct(
		private readonly SettingsHubFactory $settingsHubFactory,
		private readonly NavigationContextProvider $context,
		private readonly BusinessProcessEntityResolver $businessProcessEntityResolver,
		private readonly RuntimeGateway $bizprocRuntime,
		private readonly SettingsAccessGateway $catalogSettings,
		private readonly ToolsGateway $intranetTools,
		private readonly SettingsHubGateway $mailSettings,
		private readonly MarketplaceGateway $marketplace,
		private readonly SmsSettingsGateway $smsSettings,
		private readonly Rest\MarketplaceGateway $restMarketplace,
	)
	{
	}

	public function register(SettingsHubNavigationRegistry $registry): void
	{
		$registry->register('automation-lead', function(): Item\AutomationLeadNavigationItem {
			$router = $this->settingsHubFactory->getRouter();
			$isRobotsAvailable = $this->intranetTools->isRobotsToolAvailable();

			return new Item\AutomationLeadNavigationItem(
				$router,
				$isRobotsAvailable,
				$this->context->isAutomationAvailable(\CCrmOwnerType::Lead),
			);
		});
		$registry->register('automation-deal', function(): Item\AutomationDealNavigationItem {
			$router = $this->settingsHubFactory->getRouter();
			$isRobotsAvailable = $this->intranetTools->isRobotsToolAvailable();

			return new Item\AutomationDealNavigationItem(
				$router,
				$isRobotsAvailable,
				$this->context->isAutomationAvailable(\CCrmOwnerType::Deal),
			);
		});
		$registry->register('automation-order', function(): Item\AutomationOrderNavigationItem {
			$router = $this->settingsHubFactory->getRouter();
			$isRobotsAvailable = $this->intranetTools->isRobotsToolAvailable();

			return new Item\AutomationOrderNavigationItem(
				$router,
				$isRobotsAvailable,
				$this->context->isAutomationAvailable(\CCrmOwnerType::Order),
				$this->context->isOrdersMode(),
			);
		});
		$registry->register('business-processes', function(): Item\BusinessProcessesNavigationItem {
			$router = $this->settingsHubFactory->getRouter();

			return new Item\BusinessProcessesNavigationItem(
				$this->intranetTools->isBizprocToolAvailable(),
				$this->bizprocRuntime->isFeatureEnabled(),
				$this->businessProcessEntityResolver->isAllowed(...),
				$router->getSettingsHubNavigationUrl('business-processes'),
			);
		});
		$registry->register('crm-permissions', function(): Item\CrmPermissionsNavigationItem {
			$router = $this->settingsHubFactory->getRouter();

			return new Item\CrmPermissionsNavigationItem($router, new AllSelection());
		});
		$registry->register('currencies', function(): Item\CurrenciesNavigationItem {
			return new Item\CurrenciesNavigationItem(new CurrencyAdminAccess());
		});
		$registry->register('taxes', function(): Item\TaxesNavigationItem {
			$router = $this->settingsHubFactory->getRouter();
			$isCatalogAvailable = $this->catalogSettings->isCatalogAvailable();
			$isCurrencyAvailable = $this->catalogSettings->isCurrencyAvailable();
			$isSaleAvailable = $this->catalogSettings->isSaleAvailable();

			return new Item\TaxesNavigationItem(
				$isCatalogAvailable,
				$isCatalogAvailable
					&& $this->catalogSettings->canEditVat(),
				$isCurrencyAvailable && $isCatalogAvailable && $isSaleAvailable,
				$isCurrencyAvailable && $isCatalogAvailable && $isSaleAvailable && \CCrmTax::isVatMode(),
				$router->getSettingsHubNavigationUrl('taxes'),
			);
		});
		$registry->register('measures', function(): Item\MeasuresNavigationItem {
			return new Item\MeasuresNavigationItem(new MeasureAccess());
		});
		$registry->register('product-properties', function(): Item\ProductPropertiesNavigationItem {
			$router = $this->settingsHubFactory->getRouter();
			$isCatalogAvailable = $this->catalogSettings->isCatalogAvailable();
			$isCurrencyAvailable = $this->catalogSettings->isCurrencyAvailable();
			$isSaleAvailable = $this->catalogSettings->isSaleAvailable();

			return new Item\ProductPropertiesNavigationItem(
				$isCatalogAvailable,
				$isCatalogAvailable
					&& $this->catalogSettings->canReadCatalog(),
				$isCurrencyAvailable && $isCatalogAvailable && $isSaleAvailable,
				$router->getSettingsHubNavigationUrl('product-properties'),
			);
		});
		$registry->register('contact-center', function(): Item\ContactCenterNavigationItem {
			$router = $this->settingsHubFactory->getRouter();

			return new Item\ContactCenterNavigationItem($router);
		});
		$registry->register('mail-client', function(): Item\MailClientNavigationItem {
			$isIntranetAvailable = $this->intranetTools->isIntranetAvailable();
			$isMailAvailable = $this->mailSettings->isMailAvailable();

			return new Item\MailClientNavigationItem(
				$isIntranetAvailable,
				$isIntranetAvailable && $this->intranetTools->isExternalMailAvailable(),
				$isMailAvailable,
				$isIntranetAvailable
					&& $isMailAvailable
					&& $this->mailSettings->isMailToolAvailable(),
				$this->mailSettings->getMailClientUrl(),
			);
		});
		$registry->register('mail-sendsave', function(): Item\MailSendsaveNavigationItem {
			$router = $this->settingsHubFactory->getRouter();
			$isMailAvailable = $this->mailSettings->isMailAvailable();
			$mailBoxId = $this->mailSettings->getCrmMailboxId();
			$mailBoxExists = false;
			if ($isMailAvailable && $mailBoxId > 0)
			{
				$mailBoxExists = $this->mailSettings->mailboxExists($mailBoxId);
			}

			return new Item\MailSendsaveNavigationItem(
				$isMailAvailable,
				$mailBoxId,
				$mailBoxExists,
				$router->getSettingsHubNavigationUrl('mail-sendsave'),
			);
		});
		$registry->register('mail-templates', function(): Item\MailTemplatesNavigationItem {
			return new Item\MailTemplatesNavigationItem(new MailTemplateEditorAccess());
		});
		$registry->register('sms-limits', function(): Item\SmsLimitsNavigationItem {
			$isMessageServiceAvailable = $this->smsSettings->isMessageServiceAvailable();
			$hasConfigurableSmsSenders = $this->smsSettings->hasConfigurableSenders();

			return new Item\SmsLimitsNavigationItem(
				$hasConfigurableSmsSenders,
				$this->smsSettings->hasLimitationClass(),
				$isMessageServiceAvailable,
				$isMessageServiceAvailable && $this->smsSettings->isAdmin(),
				$this->smsSettings->getManageUrl(),
			);
		});
		$registry->register('sms-marketplace', function(): Item\SmsMarketplaceNavigationItem {
			$isMarketAvailable = $this->marketplace->isMarketAvailable();
			$isRestAvailable = $this->restMarketplace->isRestAvailable();
			$isMarketOrRestAvailable = $isMarketAvailable || $isRestAvailable;

			return new Item\SmsMarketplaceNavigationItem(
				$this->smsSettings->isSmsAvailable(),
				$isMarketOrRestAvailable,
				$this->marketplace,
			);
		});
		$registry->register('document-numerators', function(): Item\DocumentNumeratorsNavigationItem {
			$isDocumentGeneratorAvailable = $this->context->isDocumentGeneratorAvailable();

			return new Item\DocumentNumeratorsNavigationItem($isDocumentGeneratorAvailable);
		});
		$registry->register('smart-invoice-numerator', function(): Item\SmartInvoiceNumeratorNavigationItem {
			$router = $this->settingsHubFactory->getRouter();
			$smartInvoiceNumerator = $this->context->getSmartInvoiceNumerator();
			$invoiceSettings = $this->context->getInvoiceSettings();

			return new Item\SmartInvoiceNumeratorNavigationItem(
				$router,
				isset($smartInvoiceNumerator['id']) ? (int)$smartInvoiceNumerator['id'] : null,
				$invoiceSettings->isSmartInvoiceEnabled(),
			);
		});
		$registry->register('legacy-invoice-numerator', function(): Item\LegacyInvoiceNumeratorNavigationItem {
			$router = $this->settingsHubFactory->getRouter();
			$legacyInvoiceNumerator = $this->context->getLegacyInvoiceNumerator();
			$invoiceSettings = $this->context->getInvoiceSettings();

			return new Item\LegacyInvoiceNumeratorNavigationItem(
				$router,
				isset($legacyInvoiceNumerator['id']) ? (int)$legacyInvoiceNumerator['id'] : null,
				$invoiceSettings->isOldInvoicesEnabled(),
			);
		});
		$registry->register('quote-numerator', function(): Item\QuoteNumeratorNavigationItem {
			$router = $this->settingsHubFactory->getRouter();
			$quoteNumerator = $this->context->getQuoteNumerator();

			return new Item\QuoteNumeratorNavigationItem(
				$router,
				isset($quoteNumerator['id']) ? (int)$quoteNumerator['id'] : null,
			);
		});
		$registry->register('my-companies', function(): Item\MyCompaniesNavigationItem {
			return new Item\MyCompaniesNavigationItem();
		});
		$registry->register('payment-systems', function(): Item\PaymentSystemsNavigationItem {
			$router = $this->settingsHubFactory->getRouter();
			$isCatalogAvailable = $this->catalogSettings->isCatalogAvailable();
			$isCurrencyAvailable = $this->catalogSettings->isCurrencyAvailable();
			$isSaleAvailable = $this->catalogSettings->isSaleAvailable();

			return new Item\PaymentSystemsNavigationItem(
				$isCurrencyAvailable && $isCatalogAvailable && $isSaleAvailable,
				$router->getSettingsHubNavigationUrl('payment-systems'),
			);
		});
		$registry->register('payment-marketplace', function(): Item\PaymentMarketplaceNavigationItem {
			$isMarketAvailable = $this->marketplace->isMarketAvailable();
			$isRestAvailable = $this->restMarketplace->isRestAvailable();
			$isMarketOrRestAvailable = $isMarketAvailable || $isRestAvailable;

			return new Item\PaymentMarketplaceNavigationItem($isMarketOrRestAvailable, $this->marketplace);
		});
		$registry->register('requisite-templates', function(): Item\RequisiteTemplatesNavigationItem {
			$router = $this->settingsHubFactory->getRouter();

			return new Item\RequisiteTemplatesNavigationItem(
				$router->getSettingsHubNavigationUrl('requisite-templates'),
			);
		});
		$registry->register('crm-marketplace', function(): Item\CrmMarketplaceNavigationItem {
			$isMarketAvailable = $this->marketplace->isMarketAvailable();
			$isRestAvailable = $this->restMarketplace->isRestAvailable();
			$isMarketOrRestAvailable = $isMarketAvailable || $isRestAvailable;

			return new Item\CrmMarketplaceNavigationItem($isMarketOrRestAvailable, $this->marketplace);
		});
		$registry->register('app-configuration', function(): Item\AppConfigurationNavigationItem {
			$isRestAvailable = $this->restMarketplace->isRestAvailable();

			return new Item\AppConfigurationNavigationItem(
				$isRestAvailable,
				$isRestAvailable
					&& $this->restMarketplace->hasConfigurationPlacementUrl(),
				$this->restMarketplace,
			);
		});
		$registry->register('crm-migration', function(): Item\CrmMigrationNavigationItem {
			$isMarketAvailable = $this->marketplace->isMarketAvailable();
			$isRestAvailable = $this->restMarketplace->isRestAvailable();
			$isCloud = $this->marketplace->isCloud();

			return new Item\CrmMigrationNavigationItem(
				$isCloud,
				$isRestAvailable,
				$isMarketAvailable,
				$this->marketplace,
			);
		});
		$registry->register('external-sale', function(): Item\ExternalSaleNavigationItem {
			$router = $this->settingsHubFactory->getRouter();
			$isCatalogAvailable = $this->catalogSettings->isCatalogAvailable();
			$isCurrencyAvailable = $this->catalogSettings->isCurrencyAvailable();
			$isIblockAvailable = $this->catalogSettings->isIblockAvailable();
			$isSaleAvailable = $this->catalogSettings->isSaleAvailable();

			return new Item\ExternalSaleNavigationItem(
				LANGUAGE_ID,
				$isIblockAvailable && $isCurrencyAvailable && $isSaleAvailable && $isCatalogAvailable,
				$router->getSettingsHubNavigationUrl('external-sale'),
			);
		});
		$registry->register('exchange-1c', function(): Item\Exchange1cNavigationItem {
			$isCatalogAvailable = $this->catalogSettings->isCatalogAvailable();
			$isCurrencyAvailable = $this->catalogSettings->isCurrencyAvailable();
			$isIblockAvailable = $this->catalogSettings->isIblockAvailable();
			$isSaleAvailable = $this->catalogSettings->isSaleAvailable();

			return new Item\Exchange1cNavigationItem(
				LANGUAGE_ID,
				$isIblockAvailable,
				$isCurrencyAvailable,
				$isSaleAvailable,
				$isCatalogAvailable,
			);
		});
		$registry->register('sms-integrations', function(): Item\SmsIntegrationsNavigationItem {
			$isMessageServiceAvailable = $this->smsSettings->isMessageServiceAvailable();
			$hasConfigurableSmsSenders = $this->smsSettings->hasConfigurableSenders();

			return new Item\SmsIntegrationsNavigationItem(
				$hasConfigurableSmsSenders,
				$isMessageServiceAvailable,
				$isMessageServiceAvailable && $this->smsSettings->isAdmin(),
				$this->smsSettings->getManageUrl(),
			);
		});
		$registry->register('locations', function(): Item\LocationsNavigationItem {
			$isCatalogAvailable = $this->catalogSettings->isCatalogAvailable();
			$isCurrencyAvailable = $this->catalogSettings->isCurrencyAvailable();
			$isSaleAvailable = $this->catalogSettings->isSaleAvailable();

			return new Item\LocationsNavigationItem(
				$isSaleAvailable,
				$isCurrencyAvailable,
				$isCatalogAvailable,
			);
		});
		$registry->register('directories', function(): Item\DirectoriesNavigationItem {
			return new Item\DirectoriesNavigationItem();
		});
		$registry->register('pipelines', function(): Item\PipelinesNavigationItem {
			$router = $this->settingsHubFactory->getRouter();

			return new Item\PipelinesNavigationItem($router);
		});
		$registry->register('stop-list', function(): Item\StopListNavigationItem {
			return new Item\StopListNavigationItem($this->context->getExclusionAccess());
		});
		$registry->register('user-fields', function(): Item\UserFieldsNavigationItem {
			$router = $this->settingsHubFactory->getRouter();

			return new Item\UserFieldsNavigationItem(
				$this->context->getUserFieldEntityIds(),
				$router->getSettingsHubNavigationUrl('user-fields'),
			);
		});
		$registry->register('analytics-reports', function(): Item\AnalyticsReportsNavigationItem {
			return new Item\AnalyticsReportsNavigationItem();
		});
		$registry->register('crm-volume', function(): Item\CrmVolumeNavigationItem {
			$router = $this->settingsHubFactory->getRouter();

			return new Item\CrmVolumeNavigationItem(
				$this->context->hasPgsqlConnection(),
				$this->context->isPgsqlDb(),
				$router->getSettingsHubNavigationUrl('crm-volume'),
			);
		});

	}
}
