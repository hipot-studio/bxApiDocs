<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Settings;

use Bitrix\Crm\V2\Internal\Access\Settings\Composite\AnyOfPolicy;
use Bitrix\Crm\V2\Internal\Access\Settings\Policy;
use Bitrix\Crm\V2\Internal\Access\Settings\SettingAccessPolicyInterface;
use Bitrix\Crm\V2\Internal\Entity\Settings\SettingRegistration;
use Bitrix\Crm\V2\Internal\Exception\Settings\SettingNotFoundException;
use Bitrix\Main\DI\ServiceLocator;

/**
 * @internal
 */
final class SettingFactory implements SettingFactoryInterface
{
	/** @var ?list<SettingRegistration> */
	private ?array $definitions = null;

	/** @var ?array<class-string<SettingInterface>, SettingRegistration> */
	private ?array $registrationsByClass = null;

	public function getDefinitions(): iterable
	{
		if ($this->definitions !== null)
		{
			return $this->definitions;
		}

		$this->definitions = [
			$this->registration(
				Setting\Layout\SliderEnabledSetting::class,
				'crm.layout.slider.enabled',
				[
					new AnyOfPolicy([
						new Policy\SliderDisabledPolicy(),
						new Policy\ReturnToOldDetailPageEnabledPolicy(),
					]),
				],
			),
			$this->registration(
				Setting\Layout\FullCatalogEnabledSetting::class,
				'crm.layout.full_catalog.enabled',
				[new Policy\Bitrix24ModuleUnavailablePolicy()],
			),
			$this->registration(
				Setting\Layout\UserNameSortingEnabledSetting::class,
				'crm.layout.user_name_sorting.enabled',
			),
			$this->registration(
				Setting\Layout\FilePreviewerEnabledSetting::class,
				'crm.layout.file_previewer.enabled',
				[new Policy\FilePreviewerToggleEnabledPolicy()],
			),
			$this->registration(
				Setting\Product\EntityCommodityItemCreationEnabledSetting::class,
				'crm.product.entity_commodity_item_creation.enabled',
				[new Policy\CommonProductProcessingEnabledPolicy()],
			),
			$this->registration(Setting\Lead\OpenedSetting::class, 'crm.lead.opened'),
			$this->registration(Setting\Lead\DefaultListViewSetting::class, 'crm.lead.default_list_view'),
			$this->registration(
				Setting\Lead\ProductRowExportEnabledSetting::class,
				'crm.lead.product_rows_export.enabled',
			),
			$this->registration(
				Setting\Lead\AutoReturnCustomerGenerationEnabledSetting::class,
				'crm.lead.return_customer_auto_generation.enabled',
				[new Policy\LeadEnabledPolicy()],
			),
			$this->registration(
				Setting\Lead\FinishedLeadForReturnCustomerEnabledSetting::class,
				'crm.lead.finished_for_return_customer.enabled',
				[
					new Policy\LeadEnabledPolicy(),
					new Policy\ReturnCustomerAutoGenerationDisabledPolicy(),
				],
			),
			$this->registration(
				Setting\Copilot\LeadFillFieldsEnabledSetting::class,
				'crm.copilot.fill_fields.lead.enabled',
				[new Policy\AiCallProcessingEnabledPolicy()],
				[
					new Policy\AiCallProcessingEnabledPolicy(),
					new Policy\AiFillFieldsGlobalSettingEnabledPolicy(),
				],
			),
			$this->registration(Setting\Contact\OpenedSetting::class, 'crm.contact.opened'),
			$this->registration(
				Setting\Contact\DefaultListViewSetting::class,
				'crm.contact.default_list_view',
			),
			$this->registration(
				Setting\Copilot\ContactFillFieldsEnabledSetting::class,
				'crm.copilot.fill_fields.contact.enabled',
				[new Policy\AiCallProcessingEnabledPolicy()],
				[
					new Policy\AiCallProcessingEnabledPolicy(),
					new Policy\AiFillFieldsGlobalSettingEnabledPolicy(),
				],
			),
			$this->registration(Setting\Company\OpenedSetting::class, 'crm.company.opened'),
			$this->registration(
				Setting\Company\DefaultListViewSetting::class,
				'crm.company.default_list_view',
			),
			$this->registration(
				Setting\Copilot\CompanyFillFieldsEnabledSetting::class,
				'crm.copilot.fill_fields.company.enabled',
				[new Policy\AiCallProcessingEnabledPolicy()],
				[
					new Policy\AiCallProcessingEnabledPolicy(),
					new Policy\AiFillFieldsGlobalSettingEnabledPolicy(),
				],
			),
			$this->registration(Setting\Deal\OpenedSetting::class, 'crm.deal.opened'),
			$this->registration(Setting\Deal\DefaultListViewSetting::class, 'crm.deal.default_list_view'),
			$this->registration(
				Setting\Deal\CloseDateSyncEnabledSetting::class,
				'crm.deal.close_date_sync.enabled',
			),
			$this->registration(Setting\Deal\DaysBeforeCloseSetting::class, 'crm.deal.days_before_close'),
			$this->registration(
				Setting\Deal\ProductRowExportEnabledSetting::class,
				'crm.deal.product_rows_export.enabled',
			),
			$this->registration(
				Setting\Copilot\DealFillFieldsEnabledSetting::class,
				'crm.copilot.fill_fields.deal.enabled',
				[new Policy\AiCallProcessingEnabledPolicy()],
				[
					new Policy\AiCallProcessingEnabledPolicy(),
					new Policy\AiFillFieldsGlobalSettingEnabledPolicy(),
				],
			),
			$this->registration(
				Setting\Copilot\AutomaticCallProcessingEnabledSetting::class,
				'crm.copilot.call_processing.automatic.enabled',
				[new Policy\AiCallProcessingEnabledPolicy()],
			),
			$this->registration(
				Setting\Copilot\FillItemFromCallEnabledSetting::class,
				'crm.copilot.fill_item_from_call.enabled',
				[
					new Policy\AiCallProcessingEnabledPolicy(),
					ServiceLocator::getInstance()->get(Policy\AiSettingsAdminPolicy::class),
				],
			),
			$this->registration(
				Setting\Copilot\CallAssessmentEnabledSetting::class,
				'crm.copilot.call_assessment.enabled',
				[
					new Policy\AiCallProcessingEnabledPolicy(),
					ServiceLocator::getInstance()->get(Policy\AiSettingsAdminPolicy::class),
				],
			),
			$this->registration(
				Setting\Copilot\AnalyzeCommunicationEnabledSetting::class,
				'crm.copilot.analyze_communication.enabled',
				[
					new Policy\AiCallProcessingEnabledPolicy(),
					ServiceLocator::getInstance()->get(Policy\AiSettingsAdminPolicy::class),
				],
			),
			$this->registration(
				Setting\Copilot\SummarizeEnabledSetting::class,
				'crm.copilot.summarize.enabled',
				[
					new Policy\AiCallProcessingEnabledPolicy(),
					ServiceLocator::getInstance()->get(Policy\AiSettingsAdminPolicy::class),
				],
			),
			$this->registration(
				Setting\Copilot\RepeatSaleEnabledSetting::class,
				'crm.copilot.repeat_sale.enabled',
				[
					new Policy\AiCallProcessingEnabledPolicy(),
					new Policy\RepeatSaleAvailablePolicy(),
					ServiceLocator::getInstance()->get(Policy\AiSettingsAdminPolicy::class),
				],
			),
			$this->registration(
				Setting\Copilot\FillCrmTextEnabledSetting::class,
				'crm.copilot.fill_crm_text.enabled',
				[
					new Policy\AiTextEngineAvailablePolicy(),
					ServiceLocator::getInstance()->get(Policy\AiSettingsAdminPolicy::class),
				],
			),
			$this->registration(
				Setting\Copilot\MessageSenderEditorEnabledSetting::class,
				'crm.copilot.message_sender_editor.enabled',
				[
					new Policy\AiTextEngineAvailablePolicy(),
					ServiceLocator::getInstance()->get(Policy\AiSettingsAdminPolicy::class),
				],
			),
			$this->registration(
				Setting\Invoice\DefaultListViewSetting::class,
				'crm.invoice.default_list_view',
			),
			$this->registration(
				Setting\SmartInvoice\DaysBeforeCloseSetting::class,
				'crm.smart_invoice.days_before_close',
				[new Policy\SmartInvoiceEnabledPolicy()],
				[
					new Policy\SmartInvoiceEnabledPolicy(),
					new Policy\SmartInvoiceTypeExistsPolicy(),
				],
			),
			$this->registration(
				Setting\Invoice\PublicBitrix24SignatureEnabledSetting::class,
				'crm.invoice.public_bitrix24_signature.enabled',
				[new Policy\OldInvoicesEnabledPolicy()],
				[
					new Policy\OldInvoicesEnabledPolicy(),
					new Policy\InvoiceSignatureDisableAllowedPolicy(),
				],
			),
			$this->registration(
				Setting\Invoice\LegacyEnabledSetting::class,
				'crm.invoice.legacy.enabled',
				[new Policy\OldInvoicesEnablingPossiblePolicy()],
			),
			$this->registration(Setting\Quote\OpenedSetting::class, 'crm.quote.opened'),
			$this->registration(Setting\Quote\DaysBeforeCloseSetting::class, 'crm.quote.days_before_close'),
			$this->registration(
				Setting\Conversion\AutocreationEnabledSetting::class,
				'crm.conversion.autocreation.enabled',
				[],
				[new Policy\LeadEnabledPolicy()],
			),
			$this->registration(Setting\Notification\SenderSetting::class, 'crm.notifications.sender'),
			$this->registration(
				Setting\Rest\RequiredUserFieldCheckEnabledSetting::class,
				'crm.rest.required_user_field_check.enabled',
			),
			$this->registration(
				Setting\Activity\CallCreatesCalendarEventEnabledSetting::class,
				'crm.activity.call_creates_calendar_event.enabled',
				[new Policy\CalendarEventsSettingsEnabledPolicy()],
			),
			$this->registration(
				Setting\Activity\CompletedCallsDisplayedSetting::class,
				'crm.activity.calendar.completed_calls.displayed',
				[new Policy\CalendarEventsSettingsEnabledPolicy()],
			),
			$this->registration(
				Setting\Activity\CompletedMeetingsDisplayedSetting::class,
				'crm.activity.calendar.completed_meetings.displayed',
				[new Policy\CalendarEventsSettingsEnabledPolicy()],
			),
			$this->registration(
				Setting\Activity\ReassignedCallsKeptSetting::class,
				'crm.activity.calendar.reassigned_calls.kept',
			),
			$this->registration(
				Setting\Activity\ReassignedMeetingsKeptSetting::class,
				'crm.activity.calendar.reassigned_meetings.kept',
			),
			$this->registration(
				Setting\Activity\UnboundTasksKeptSetting::class,
				'crm.activity.unbound_tasks.kept',
			),
			$this->registration(
				Setting\Counter\ActivitylessItemsIncludedSetting::class,
				'crm.counter.activityless_items.included',
				[new Policy\UniversalActivityScenarioDisabledPolicy()],
			),
			$this->registration(
				Setting\Counter\ActivityResponsibleUsedSetting::class,
				'crm.counter.activity_responsible.used',
			),
			$this->registration(
				Setting\Lead\CompletedActivityProviderCodesSetting::class,
				'crm.lead.conversion.completed_activity_provider_codes',
			),
			$this->registration(
				Setting\Activity\DefaultListViewSetting::class,
				'crm.activity.default_list_view',
			),
			$this->registration(
				Setting\Activity\EntityBadgeRemovalDaysSetting::class,
				'crm.activity.entity_badge_removal_days',
			),
			$this->registration(
				Setting\Activity\ForwardedEmailAsOutgoingEnabledSetting::class,
				'crm.activity.forwarded_email_as_outgoing.enabled',
			),
			$this->registration(
				Setting\Activity\UnconnectedRecipientsEnabledSetting::class,
				'crm.activity.unconnected_recipients.enabled',
			),
			$this->registration(
				Setting\Activity\OutgoingEmailOwnerTypeSetting::class,
				'crm.activity.outgoing_email_owner_type',
				[new Policy\UnconnectedRecipientsEnabledPolicy()],
			),
			$this->registration(
				Setting\Email\ServiceCodeAllocationSetting::class,
				'crm.email.service_code_allocation',
			),
			$this->registration(
				Setting\Email\Bitrix24SignatureEnabledSetting::class,
				'crm.email.bitrix24_signature.enabled',
				[new Policy\Bitrix24EmailEnabledPolicy()],
				[
					new Policy\Bitrix24EmailEnabledPolicy(),
					new Policy\Bitrix24EmailSignatureDisableAllowedPolicy(),
				],
			),
			$this->registration(
				Setting\History\ExportEventEnabledSetting::class,
				'crm.history.export_event.enabled',
			),
			$this->registration(
				Setting\History\ViewEventEnabledSetting::class,
				'crm.history.view_event.enabled',
			),
			$this->registration(
				Setting\History\ViewEventGroupingIntervalSetting::class,
				'crm.history.view_event_grouping_interval',
			),
			$this->registration(
				Setting\History\LeadDeletionEventEnabledSetting::class,
				'crm.history.lead_deletion_event.enabled',
			),
			$this->registration(
				Setting\History\ContactDeletionEventEnabledSetting::class,
				'crm.history.contact_deletion_event.enabled',
			),
			$this->registration(
				Setting\History\CompanyDeletionEventEnabledSetting::class,
				'crm.history.company_deletion_event.enabled',
			),
			$this->registration(
				Setting\History\DealDeletionEventEnabledSetting::class,
				'crm.history.deal_deletion_event.enabled',
			),
			$this->registration(
				Setting\History\QuoteDeletionEventEnabledSetting::class,
				'crm.history.quote_deletion_event.enabled',
			),
			$this->registration(
				Setting\LiveFeed\MergeEnabledSetting::class,
				'crm.livefeed.merge.enabled',
				[new Policy\LiveFeedAvailablePolicy()],
			),
			$this->registration(Setting\Format\PersonNameSetting::class, 'crm.format.person_name'),
			$this->registration(Setting\Format\CallToSetting::class, 'crm.format.callto'),
			$this->registration(
				Setting\Format\SimpleTimeEnabledSetting::class,
				'crm.format.simple_time.enabled',
			),
			$this->registration(Setting\Format\EntityAddressSetting::class, 'crm.format.entity_address'),
			$this->registration(
				Setting\DuplicateControl\LeadEnabledSetting::class,
				'crm.duplicate_control.lead.enabled',
			),
			$this->registration(
				Setting\DuplicateControl\ContactEnabledSetting::class,
				'crm.duplicate_control.contact.enabled',
			),
			$this->registration(
				Setting\DuplicateControl\CompanyEnabledSetting::class,
				'crm.duplicate_control.company.enabled',
			),
			$this->registration(Setting\Status\DeprecatedEnabledSetting::class, 'crm.status.deprecated.enabled'),
			$this->registration(Setting\RecycleBin\LeadEnabledSetting::class, 'crm.recycle_bin.lead.enabled'),
			$this->registration(
				Setting\RecycleBin\ContactEnabledSetting::class,
				'crm.recycle_bin.contact.enabled',
			),
			$this->registration(
				Setting\RecycleBin\CompanyEnabledSetting::class,
				'crm.recycle_bin.company.enabled',
			),
			$this->registration(Setting\RecycleBin\DealEnabledSetting::class, 'crm.recycle_bin.deal.enabled'),
			$this->registration(
				Setting\RecycleBin\TtlDaysSetting::class,
				'crm.recycle_bin.ttl_days',
				[new Policy\Bitrix24ModuleNotInstalledPolicy()],
			),
		];
		$this->registrationsByClass = [];
		foreach ($this->definitions as $registration)
		{
			$this->registrationsByClass[$registration->settingClass] = $registration;
		}

		return $this->definitions;
	}

	public function create(string $settingClass): SettingInterface
	{
		$this->getDefinitions();

		if (!isset($this->registrationsByClass[$settingClass]))
		{
			throw new SettingNotFoundException($settingClass);
		}

		return ServiceLocator::getInstance()->get($settingClass);
	}

	/**
	 * @param class-string<SettingInterface> $settingClass
	 * @param list<SettingAccessPolicyInterface> $readPolicies
	 * @param ?list<SettingAccessPolicyInterface> $writePolicies
	 */
	private function registration(
		string $settingClass,
		string $code,
		array $readPolicies = [],
		?array $writePolicies = null,
	): SettingRegistration
	{
		$writePolicies ??= $readPolicies;

		return new SettingRegistration(
			settingClass: $settingClass,
			code: $code,
			readPolicies: [new Policy\CrmAdminPolicy(), ...$readPolicies],
			writePolicies: [new Policy\CrmAdminPolicy(), ...$writePolicies],
		);
	}
}
