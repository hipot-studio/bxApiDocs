<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\CustomController;

use Bitrix\Crm\Multifield\Type;
use Bitrix\Crm\V2\Infrastructure\Rest\Access\ActivityFieldAccessProvider;
use Bitrix\Crm\V2\Infrastructure\Rest\Controller;
use Bitrix\Crm\V2\Infrastructure\Rest\Controller\Availability\ActivityEnabledProvider;
use Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Activity\Mail;
use Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Address\Address as OwnerAddressController;
use Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Category\RouteBuilder;
use Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Dictionary\AddressType;
use Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Dictionary\StageSemantic;
use Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Field\Custom;
use Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Field\Field;
use Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Field\Requirement;
use Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Field\Custom\Enum as CustomEnum;
use Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Item\Item;
use Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Item\MyCompany;
use Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Item\ProductRow\Field as ProductRowField;
use Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Item\ProductRow\ProductRow;
use Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Lead\Address;
use Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Product\Product;
use Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Requisite\Address as RequisiteAddress;
use Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Requisite\BankRequisite;
use Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Requisite\ClientRequisite;
use Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Requisite\OwnerRequisite;
use Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Requisite\Preset;
use Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Requisite\Requisite;
use Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Timeline\Activity\Email\Email;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Activity\ActivityDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Address\OwnerAddressDtoGenerator;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Dictionary\AddressTypeDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Dictionary\CommunicationTypeDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Dictionary\DictionaryValueDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Dictionary\EntityTypeDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Dictionary\StageSemanticDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Field\CrmFieldMetadataDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Field\FieldRequirementDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Field\CustomFieldConfigDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Field\Custom\EnumDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Form\Configuration\ConfigurationDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Form\Dictionary\EntityFieldDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Form\Dictionary\ScenarioCategoryDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Form\Dictionary\ScenarioDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Form\Embedding\EmbeddingDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Form\Embedding\OpenLineDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Form\Embedding\SiteButtonDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Form\FormDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\DeleteItemDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\ItemDtoGenerator;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\ProductRow\ProductRowDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Address\Lead\AddressDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Product\ProductDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Address\Requisite\RequisiteAddressDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Requisite\BankRequisiteDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Requisite\ClientRequisiteDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Requisite\OwnerRequisiteDtoGenerator;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Requisite\PresetDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Requisite\RequisiteDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Timeline\Activity\Email\EmailActivityDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Tracking\TraceDto;
use Bitrix\Crm\V2\Internal\Entity\Dictionary\DictionaryType;
use Bitrix\Crm\V2\Internal\Repository\ProductRow\ProductEnabledTypeRepository;
use Bitrix\Crm\V2\Internal\Service\Requisite\EntityLinkService;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\OwnerType;
use Bitrix\Crm\Feature;
use Bitrix\Crm\Feature\RestV3CrudDeal;
use Bitrix\Crm\Feature\RestV3Tracking;
use Bitrix\Crm\Feature\RestV3WebForm;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Application;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoField;
use Bitrix\Rest\V3\Mcp\McpToolMetadata;
use Bitrix\Rest\V3\Realisation\Controller\Field as DtoFieldController;
use Bitrix\Rest\V3\Realisation\Dto\DtoFieldDto;
use Bitrix\Rest\V3\Schema\ControllerData;
use Bitrix\Rest\V3\Schema\GeneratedDto;
use Bitrix\Rest\V3\Schema\MethodDescription;
use Bitrix\Rest\V3\Structure\Structure;

class SchemaProvider extends \Bitrix\Rest\V3\Schema\SchemaProvider
{
	public const CONTACT_CATEGORY_ID = 0;
	public const COMPANY_CATEGORY_ID = 0;

	public static function getItemRouteCategoryId(EntityType $entityType): ?int
	{
		return match ($entityType->getId())
		{
			OwnerType::CONTACT => self::CONTACT_CATEGORY_ID,
			OwnerType::COMPANY => self::COMPANY_CATEGORY_ID,
			default => null,
		};
	}

	protected const MODULE = 'crm';

	/** The namespace the controllers of the module are found by: the `defaultNamespace` of `.settings.php`. */
	protected const CONTROLLER_NAMESPACE = '\\Bitrix\\Crm\\V2\\Infrastructure\\Rest\\Controller';

	protected const MCP_SMART_PROCESS_TOOL_SET_CODE = 'crm_rest_v3_smart_process';

	protected const MCP_REQUISITE_ADDRESS_TOOL_SET_CODE = 'crm_rest_v3_requisite_address';

	protected const MCP_PRODUCT_ROW_TOOL_SET_CODE = 'crm_rest_v3_product_row';

	protected const MCP_PRODUCT_TOOL_SET_CODE = 'crm_rest_v3_product';

	protected const MCP_DICTIONARY_TOOL_SET_CODE = 'crm_rest_v3_dictionary';

	protected const MCP_FORM_TOOL_SET_CODE = 'crm_rest_v3_form';
	protected const MCP_TRACKING_TOOL_SET_CODE = 'crm_rest_v3_tracking';
	protected const MCP_FIELD_REQUIREMENT_TOOL_SET_CODE = 'crm_rest_v3_field_requirement';

	protected const LEAD_CONTROLLER_METHODS = ['isEnabled', 'enable', 'disable'];
	protected const MY_COMPANY_BASIC_CONTROLLER_METHODS = ['get', 'list'];
	protected const CUSTOM_FIELD_ACTIONS = ['list', 'get', 'add', 'update', 'delete'];
	protected const CUSTOM_FIELD_STATIC_ENTITY_SEGMENTS = [
		OwnerType::DEAL => 'deal',
		OwnerType::CONTACT => 'contact',
		OwnerType::LEAD => 'lead',
		OwnerType::COMPANY => 'company',
		OwnerType::QUOTE => 'quote',
		OwnerType::SMART_INVOICE => 'smartInvoice',
	];
	protected const CUSTOM_FIELD_TOOL_SET_CODES = [
		OwnerType::DEAL => 'crm_rest_v3_deal',
		OwnerType::CONTACT => 'crm_rest_v3_contact',
		OwnerType::LEAD => 'crm_rest_v3_lead',
		OwnerType::COMPANY => 'crm_rest_v3_company',
		OwnerType::QUOTE => 'crm_rest_v3_quote',
		OwnerType::SMART_INVOICE => 'crm_rest_v3_smart_invoice',
	];
	private const CUSTOM_FIELD_OWNER_NAMES = [
		OwnerType::DEAL => 'CRM deal',
		OwnerType::CONTACT => 'CRM contact',
		OwnerType::LEAD => 'CRM lead',
		OwnerType::COMPANY => 'CRM company',
		OwnerType::QUOTE => 'CRM quote',
		OwnerType::SMART_INVOICE => 'CRM smart invoice',
	];
	protected const ROUTES_WITHOUT_NORMALIZATION = [
		'crm.activity.mail.getContent',
		'crm.activity.mail.getThread',
		'crm.activity.mail.reply',
		'crm.deal.timeline.activity.email.list',
		'crm.deal.timeline.activity.email.send',
		'crm.lead.timeline.activity.email.list',
		'crm.lead.timeline.activity.email.send',
		'crm.contact.timeline.activity.email.list',
		'crm.contact.timeline.activity.email.send',
		'crm.company.timeline.activity.email.list',
		'crm.company.timeline.activity.email.send',
	];

	/** @var array<string, class-string<Feature\BaseFeature|Feature\AbstractCrmFlag>> */
	protected array $featureByRoute = [
		'crm.deal.field.required.get' => RestV3CrudDeal::class,
		'crm.lead.field.required.get' => RestV3CrudDeal::class,
		'crm.contact.field.required.get' => RestV3CrudDeal::class,
		'crm.company.field.required.get' => RestV3CrudDeal::class,
		'crm.quote.field.required.get' => RestV3CrudDeal::class,
		'crm.smartInvoice.field.required.get' => RestV3CrudDeal::class,
		'crm.deal.add' => RestV3CrudDeal::class,
		'crm.contact.add' => RestV3CrudDeal::class,
		'crm.lead.add' => RestV3CrudDeal::class,
		'crm.company.add' => RestV3CrudDeal::class,
		'crm.mycompany.add' => RestV3CrudDeal::class,
		'crm.quote.add' => RestV3CrudDeal::class,
		'crm.smartInvoice.add' => RestV3CrudDeal::class,
		'crm.deal.addFieldValue' => RestV3CrudDeal::class,
		'crm.lead.addFieldValue' => RestV3CrudDeal::class,
		'crm.quote.addFieldValue' => RestV3CrudDeal::class,
		'crm.smartInvoice.addFieldValue' => RestV3CrudDeal::class,
		'crm.contact.addFieldValue' => RestV3CrudDeal::class,
		'crm.company.addFieldValue' => RestV3CrudDeal::class,
		'crm.mycompany.addFieldValue' => RestV3CrudDeal::class,
		'crm.deal.update' => RestV3CrudDeal::class,
		'crm.contact.update' => RestV3CrudDeal::class,
		'crm.lead.update' => RestV3CrudDeal::class,
		'crm.company.update' => RestV3CrudDeal::class,
		'crm.mycompany.update' => RestV3CrudDeal::class,
		'crm.quote.update' => RestV3CrudDeal::class,
		'crm.smartInvoice.update' => RestV3CrudDeal::class,
		'crm.deal.delete' => RestV3CrudDeal::class,
		'crm.lead.delete' => RestV3CrudDeal::class,
		'crm.quote.delete' => RestV3CrudDeal::class,
		'crm.smartInvoice.delete' => RestV3CrudDeal::class,
		'crm.deal.deleteFieldValue' => RestV3CrudDeal::class,
		'crm.lead.deleteFieldValue' => RestV3CrudDeal::class,
		'crm.quote.deleteFieldValue' => RestV3CrudDeal::class,
		'crm.smartInvoice.deleteFieldValue' => RestV3CrudDeal::class,
		'crm.deal.get' => RestV3CrudDeal::class,
		'crm.contact.get' => RestV3CrudDeal::class,
		'crm.company.get' => RestV3CrudDeal::class,
		'crm.mycompany.get' => RestV3CrudDeal::class,
		'crm.lead.get' => RestV3CrudDeal::class,
		'crm.quote.get' => RestV3CrudDeal::class,
		'crm.smartInvoice.get' => RestV3CrudDeal::class,
		'crm.deal.list' => RestV3CrudDeal::class,
		'crm.contact.list' => RestV3CrudDeal::class,
		'crm.company.list' => RestV3CrudDeal::class,
		'crm.mycompany.list' => RestV3CrudDeal::class,
		'crm.lead.list' => RestV3CrudDeal::class,
		'crm.quote.list' => RestV3CrudDeal::class,
		'crm.smartInvoice.list' => RestV3CrudDeal::class,
		'crm.deal.aggregate' => RestV3CrudDeal::class,
		'crm.contact.aggregate' => RestV3CrudDeal::class,
		'crm.company.aggregate' => RestV3CrudDeal::class,
		'crm.mycompany.aggregate' => RestV3CrudDeal::class,
		'crm.lead.aggregate' => RestV3CrudDeal::class,
		'crm.quote.aggregate' => RestV3CrudDeal::class,
		'crm.smartInvoice.aggregate' => RestV3CrudDeal::class,
		'crm.deal.field.list' => RestV3CrudDeal::class,
		'crm.deal.field.get' => RestV3CrudDeal::class,
		'crm.contact.field.list' => RestV3CrudDeal::class,
		'crm.contact.field.get' => RestV3CrudDeal::class,
		'crm.lead.field.list' => RestV3CrudDeal::class,
		'crm.lead.field.get' => RestV3CrudDeal::class,
		'crm.company.field.list' => RestV3CrudDeal::class,
		'crm.company.field.get' => RestV3CrudDeal::class,
		'crm.quote.field.list' => RestV3CrudDeal::class,
		'crm.quote.field.get' => RestV3CrudDeal::class,
		'crm.smartInvoice.field.list' => RestV3CrudDeal::class,
		'crm.smartInvoice.field.get' => RestV3CrudDeal::class,
		'crm.dictionary.entityType.get' => RestV3CrudDeal::class,
		'crm.dictionary.entityType.list' => RestV3CrudDeal::class,
		'crm.contact.delete' => RestV3CrudDeal::class,
		'crm.company.delete' => RestV3CrudDeal::class,
		'crm.mycompany.delete' => RestV3CrudDeal::class,
		'crm.contact.deleteFieldValue' => RestV3CrudDeal::class,
		'crm.company.deleteFieldValue' => RestV3CrudDeal::class,
		'crm.mycompany.deleteFieldValue' => RestV3CrudDeal::class,
		'crm.dictionary.stageSemantic.get' => RestV3CrudDeal::class,
		'crm.dictionary.stageSemantic.list' => RestV3CrudDeal::class,
		'crm.dictionary.addressType.get' => RestV3CrudDeal::class,
		'crm.dictionary.addressType.list' => RestV3CrudDeal::class,
		'crm.dictionary.emailType.get' => RestV3CrudDeal::class,
		'crm.dictionary.emailType.list' => RestV3CrudDeal::class,
		'crm.dictionary.phoneType.get' => RestV3CrudDeal::class,
		'crm.dictionary.phoneType.list' => RestV3CrudDeal::class,
		'crm.dictionary.imType.get' => RestV3CrudDeal::class,
		'crm.dictionary.imType.list' => RestV3CrudDeal::class,
		'crm.dictionary.webType.get' => RestV3CrudDeal::class,
		'crm.dictionary.webType.list' => RestV3CrudDeal::class,
		'crm.contact.address.add' => RestV3CrudDeal::class,
		'crm.contact.address.update' => RestV3CrudDeal::class,
		'crm.contact.address.get' => RestV3CrudDeal::class,
		'crm.contact.address.delete' => RestV3CrudDeal::class,
		'crm.contact.address.list' => RestV3CrudDeal::class,
		'crm.company.address.add' => RestV3CrudDeal::class,
		'crm.company.address.update' => RestV3CrudDeal::class,
		'crm.company.address.get' => RestV3CrudDeal::class,
		'crm.company.address.delete' => RestV3CrudDeal::class,
		'crm.company.address.list' => RestV3CrudDeal::class,
		'crm.lead.address.get' => RestV3CrudDeal::class,
		'crm.lead.address.set' => RestV3CrudDeal::class,
		'crm.lead.address.delete' => RestV3CrudDeal::class,
		'crm.lead.productRow.add' => RestV3CrudDeal::class,
		'crm.lead.productRow.update' => RestV3CrudDeal::class,
		'crm.lead.productRow.get' => RestV3CrudDeal::class,
		'crm.lead.productRow.delete' => RestV3CrudDeal::class,
		'crm.lead.productRow.list' => RestV3CrudDeal::class,
		'crm.lead.productRow.replace' => RestV3CrudDeal::class,
		'crm.lead.productRow.getAvailableForPayment' => RestV3CrudDeal::class,
		'crm.deal.productRow.add' => RestV3CrudDeal::class,
		'crm.deal.productRow.update' => RestV3CrudDeal::class,
		'crm.deal.productRow.get' => RestV3CrudDeal::class,
		'crm.deal.productRow.delete' => RestV3CrudDeal::class,
		'crm.deal.productRow.list' => RestV3CrudDeal::class,
		'crm.deal.productRow.replace' => RestV3CrudDeal::class,
		'crm.deal.productRow.getAvailableForPayment' => RestV3CrudDeal::class,
		'crm.quote.productRow.add' => RestV3CrudDeal::class,
		'crm.quote.productRow.update' => RestV3CrudDeal::class,
		'crm.quote.productRow.get' => RestV3CrudDeal::class,
		'crm.quote.productRow.delete' => RestV3CrudDeal::class,
		'crm.quote.productRow.list' => RestV3CrudDeal::class,
		'crm.quote.productRow.replace' => RestV3CrudDeal::class,
		'crm.quote.productRow.getAvailableForPayment' => RestV3CrudDeal::class,
		'crm.lead.productRow.field.list' => RestV3CrudDeal::class,
		'crm.lead.productRow.field.get' => RestV3CrudDeal::class,
		'crm.deal.productRow.field.list' => RestV3CrudDeal::class,
		'crm.deal.productRow.field.get' => RestV3CrudDeal::class,
		'crm.quote.productRow.field.list' => RestV3CrudDeal::class,
		'crm.quote.productRow.field.get' => RestV3CrudDeal::class,
		'crm.product.list' => RestV3CrudDeal::class,
		'crm.contact.requisite.bankRequisite.add' => RestV3CrudDeal::class,
		'crm.contact.requisite.bankRequisite.update' => RestV3CrudDeal::class,
		'crm.contact.requisite.bankRequisite.get' => RestV3CrudDeal::class,
		'crm.contact.requisite.bankRequisite.list' => RestV3CrudDeal::class,
		'crm.contact.requisite.bankRequisite.delete' => RestV3CrudDeal::class,
		'crm.contact.bankRequisite.list' => RestV3CrudDeal::class,
		'crm.company.requisite.bankRequisite.add' => RestV3CrudDeal::class,
		'crm.company.requisite.bankRequisite.update' => RestV3CrudDeal::class,
		'crm.company.requisite.bankRequisite.get' => RestV3CrudDeal::class,
		'crm.company.requisite.bankRequisite.list' => RestV3CrudDeal::class,
		'crm.company.requisite.bankRequisite.delete' => RestV3CrudDeal::class,
		'crm.company.bankRequisite.list' => RestV3CrudDeal::class,
		'crm.requisite.list' => RestV3CrudDeal::class,
		'crm.contact.requisite.add' => RestV3CrudDeal::class,
		'crm.contact.requisite.update' => RestV3CrudDeal::class,
		'crm.contact.requisite.get' => RestV3CrudDeal::class,
		'crm.contact.requisite.delete' => RestV3CrudDeal::class,
		'crm.contact.requisite.list' => RestV3CrudDeal::class,
		'crm.contact.requisite.preset.get' => RestV3CrudDeal::class,
		'crm.contact.requisite.preset.list' => RestV3CrudDeal::class,
		'crm.company.requisite.add' => RestV3CrudDeal::class,
		'crm.company.requisite.update' => RestV3CrudDeal::class,
		'crm.company.requisite.get' => RestV3CrudDeal::class,
		'crm.company.requisite.delete' => RestV3CrudDeal::class,
		'crm.company.requisite.list' => RestV3CrudDeal::class,
		'crm.company.requisite.preset.get' => RestV3CrudDeal::class,
		'crm.company.requisite.preset.list' => RestV3CrudDeal::class,
		'crm.contact.requisite.address.add' => RestV3CrudDeal::class,
		'crm.contact.requisite.address.get' => RestV3CrudDeal::class,
		'crm.contact.requisite.address.list' => RestV3CrudDeal::class,
		'crm.contact.requisite.address.update' => RestV3CrudDeal::class,
		'crm.contact.requisite.address.delete' => RestV3CrudDeal::class,
		'crm.company.requisite.address.add' => RestV3CrudDeal::class,
		'crm.company.requisite.address.get' => RestV3CrudDeal::class,
		'crm.company.requisite.address.list' => RestV3CrudDeal::class,
		'crm.company.requisite.address.update' => RestV3CrudDeal::class,
		'crm.company.requisite.address.delete' => RestV3CrudDeal::class,
		'crm.deal.clientRequisite.get' => RestV3CrudDeal::class,
		'crm.deal.clientRequisite.set' => RestV3CrudDeal::class,
		'crm.quote.clientRequisite.get' => RestV3CrudDeal::class,
		'crm.quote.clientRequisite.set' => RestV3CrudDeal::class,
		'crm.smartInvoice.clientRequisite.get' => RestV3CrudDeal::class,
		'crm.smartInvoice.clientRequisite.set' => RestV3CrudDeal::class,
		'crm.form.get' => RestV3WebForm::class,
		'crm.form.list' => RestV3WebForm::class,
		'crm.form.add' => RestV3WebForm::class,
		'crm.form.update' => RestV3WebForm::class,
		'crm.form.delete' => RestV3WebForm::class,
		'crm.form.validate' => RestV3WebForm::class,
		'crm.form.activate' => RestV3WebForm::class,
		'crm.form.deactivate' => RestV3WebForm::class,
		'crm.form.configuration.get' => RestV3WebForm::class,
		'crm.form.field.get' => RestV3WebForm::class,
		'crm.form.field.list' => RestV3WebForm::class,
		'crm.form.embedding.get' => RestV3WebForm::class,
		'crm.form.embedding.update' => RestV3WebForm::class,
		'crm.form.sitebutton.list' => RestV3WebForm::class,
		'crm.form.openline.list' => RestV3WebForm::class,
		'crm.form.scenario.list' => RestV3WebForm::class,
		'crm.form.scenariocategory.list' => RestV3WebForm::class,
		'crm.form.entityfield.list' => RestV3WebForm::class,
		'crm.tracking.trace.get' => RestV3Tracking::class,
		'crm.tracking.trace.list' => RestV3Tracking::class,
		'crm.tracking.trace.add' => RestV3Tracking::class,
		'crm.tracking.trace.delete' => RestV3Tracking::class,
	];

	/**
	 * The seven actions of `crm.{entity}.productRow.*`, in the order of {@see ProductRow}. Kept here for
	 * the routes generated per type; the ones of lead, deal and quote are written out below literally.
	 */
	protected const PRODUCT_ROW_ACTIONS = [
		'add',
		'update',
		'get',
		'delete',
		'list',
		'replace',
		'getAvailableForPayment',
	];

	/**
	 * The two help actions of `crm.{entity}.productRow.field.*`, in the order of {@see ProductRowField}.
	 * Kept apart from the actions above because another controller serves them: the name of the method is
	 * a name of the group either way, but one implementation cannot answer both.
	 */
	protected const PRODUCT_ROW_FIELD_ACTIONS = [
		'list',
		'get',
	];

	/**
	 * The scope of the address group of a contact and a company. Written out because the scopes built
	 * from the segments of the name would offer `crm.contact.address` and `crm.contact.address.add` as
	 * scopes of the portal, and an address is no resource of its own: the right it follows is the right
	 * on its owner, and the module has one scope for that.
	 */
	protected const ADDRESS_SCOPES = ['crm'];

	/**
	 * The two actions of `crm.{entity}.clientRequisite.*`, in the order of {@see ClientRequisite}. Kept
	 * here for the routes built per type; the ones of deal, quote and smart invoice are written out
	 * below literally.
	 */
	protected const CLIENT_REQUISITE_ACTIONS = [
		'get',
		'set',
	];

	/**
	 * The scope of the group of the client requisite. Written out for the same reason the addresses
	 * write theirs out: the scopes built from the segments of the name would offer
	 * `crm.deal.clientRequisite` and `crm.deal.clientRequisite.get` as scopes of the portal, and the
	 * choice of a requisite is no resource of its own - the right it follows is the right on its owner.
	 */
	protected const CLIENT_REQUISITE_SCOPES = ['crm'];

	/**
	 * The scope of the global activity methods. Written out for the reason the addresses write theirs out:
	 * the scopes built from the segments of the name would offer `crm.activity`, `crm.activity.get` and
	 * `crm.activity.field` as scopes of the portal, and an activity is a resource of CRM rather than a
	 * portal scope of its own.
	 */
	protected const ACTIVITY_SCOPES = ['crm'];
	protected const ACTIVITY_MCP_TOOL_SET_CODE = 'crm_rest_v3_activity';

	/**
	 * The global methods of {@see Controller\Activity}: the name a client calls => the action serving it.
	 * They are routed by the namespace of the module like any ordinary controller, and this map exists to
	 * describe them, not to route them - see {@see self::buildActivityControllerData()}.
	 */
	protected const ACTIVITY_ACTIONS = [
		'crm.activity.get' => 'get',
	];

	/**
	 * The field methods of {@see ActivityDto}: the name a client calls => the action of the system controller
	 * of REST serving it. REST builds a pair like this for every controller that carries a DTO, and builds it
	 * unconditionally - only a route the module declares itself is left alone. So the pair is declared here,
	 * and the group answers by one switch of the wave rather than by two.
	 */
	protected const ACTIVITY_FIELD_ACTIONS = [
		'crm.activity.field.list' => 'list',
		'crm.activity.field.get' => 'get',
	];

	/**
	 * The five actions of the values of a basic dictionary, in the order of {@see Dictionary\DictionaryValue}.
	 * The last segment of the name of a method and the action serving it are the same word for every one of
	 * them, so there is nothing to map here.
	 */
	protected const DICTIONARY_VALUE_ACTIONS = ['get', 'list', 'add', 'update', 'delete'];

	/**
	 * The segment of the name of a method that says which of the seven basic dictionaries it serves => the
	 * dictionary it names. Spelled out rather than derived from the names of the cases of
	 * {@see DictionaryType}: these segments are a published contract, and a case renamed for the sake of the
	 * domain must not rename `crm.dictionary.dealType.*` with it.
	 *
	 * @var array<string, DictionaryType>
	 */
	protected const DICTIONARY_TYPE_BY_SEGMENT = [
		'dealType' => DictionaryType::DealType,
		'contactType' => DictionaryType::ContactType,
		'honorific' => DictionaryType::Honorific,
		'companyType' => DictionaryType::CompanyType,
		'employees' => DictionaryType::Employees,
		'industry' => DictionaryType::Industry,
		'source' => DictionaryType::Source,
	];

	protected array $routesConfig = [
		'crm.deal.field.required.get' => [
			'controller' => Requirement::class,
			'method' => 'get',
			'dtoFqcn' => FieldRequirementDto::class,
			'entityTypeId' => OwnerType::DEAL,
			'scopes' => ['crm'],
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_FIELD_REQUIREMENT_TOOL_SET_CODE,
				'descriptionOverride' => 'Get field requirement settings for a deal.',
			],
		],
		'crm.lead.field.required.get' => [
			'controller' => Requirement::class,
			'method' => 'get',
			'dtoFqcn' => FieldRequirementDto::class,
			'entityTypeId' => OwnerType::LEAD,
			'scopes' => ['crm'],
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_FIELD_REQUIREMENT_TOOL_SET_CODE,
				'descriptionOverride' => 'Get field requirement settings for a lead.',
			],
		],
		'crm.contact.field.required.get' => [
			'controller' => Requirement::class,
			'method' => 'get',
			'dtoFqcn' => FieldRequirementDto::class,
			'entityTypeId' => OwnerType::CONTACT,
			'scopes' => ['crm'],
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_FIELD_REQUIREMENT_TOOL_SET_CODE,
				'descriptionOverride' => 'Get field requirement settings for a contact.',
			],
		],
		'crm.company.field.required.get' => [
			'controller' => Requirement::class,
			'method' => 'get',
			'dtoFqcn' => FieldRequirementDto::class,
			'entityTypeId' => OwnerType::COMPANY,
			'scopes' => ['crm'],
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_FIELD_REQUIREMENT_TOOL_SET_CODE,
				'descriptionOverride' => 'Get field requirement settings for a company.',
			],
		],
		'crm.quote.field.required.get' => [
			'controller' => Requirement::class,
			'method' => 'get',
			'dtoFqcn' => FieldRequirementDto::class,
			'entityTypeId' => OwnerType::QUOTE,
			'scopes' => ['crm'],
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_FIELD_REQUIREMENT_TOOL_SET_CODE,
				'descriptionOverride' => 'Get field requirement settings for a quote.',
			],
		],
		'crm.smartInvoice.field.required.get' => [
			'controller' => Requirement::class,
			'method' => 'get',
			'dtoFqcn' => FieldRequirementDto::class,
			'entityTypeId' => OwnerType::SMART_INVOICE,
			'scopes' => ['crm'],
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_FIELD_REQUIREMENT_TOOL_SET_CODE,
				'descriptionOverride' => 'Get field requirement settings for a smart invoice.',
			],
		],
		'crm.contact.requisite.bankRequisite.add' => [
			'controller' => BankRequisite::class,
			'method' => 'add',
			'dtoFqcn' => BankRequisiteDto::class,
			'entityTypeId' => OwnerType::CONTACT,
			'categoryId' => self::CONTACT_CATEGORY_ID,
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_REQUISITE_ADDRESS_TOOL_SET_CODE,
				'descriptionOverride' => 'Create bank details of a CRM contact.',
			],
		],
		'crm.contact.requisite.bankRequisite.update' => [
			'controller' => BankRequisite::class,
			'method' => 'update',
			'dtoFqcn' => BankRequisiteDto::class,
			'entityTypeId' => OwnerType::CONTACT,
			'categoryId' => self::CONTACT_CATEGORY_ID,
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_REQUISITE_ADDRESS_TOOL_SET_CODE,
				'descriptionOverride' => 'Update bank details of a CRM contact.',
			],
		],
		'crm.contact.requisite.bankRequisite.get' => [
			'controller' => BankRequisite::class,
			'method' => 'get',
			'dtoFqcn' => BankRequisiteDto::class,
			'entityTypeId' => OwnerType::CONTACT,
			'categoryId' => self::CONTACT_CATEGORY_ID,
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_REQUISITE_ADDRESS_TOOL_SET_CODE,
				'descriptionOverride' => 'Get bank details of a CRM contact by ID.',
			],
		],
		'crm.contact.requisite.bankRequisite.list' => [
			'controller' => BankRequisite::class,
			'method' => 'listByRequisite',
			'dtoFqcn' => BankRequisiteDto::class,
			'entityTypeId' => OwnerType::CONTACT,
			'categoryId' => self::CONTACT_CATEGORY_ID,
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_REQUISITE_ADDRESS_TOOL_SET_CODE,
				'descriptionOverride' => 'List bank details attached to CRM contact details.',
			],
		],
		'crm.contact.requisite.bankRequisite.delete' => [
			'controller' => BankRequisite::class,
			'method' => 'delete',
			'dtoFqcn' => BankRequisiteDto::class,
			'entityTypeId' => OwnerType::CONTACT,
			'categoryId' => self::CONTACT_CATEGORY_ID,
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_REQUISITE_ADDRESS_TOOL_SET_CODE,
				'descriptionOverride' => 'Delete bank details of a CRM contact.',
			],
		],
		'crm.contact.bankRequisite.list' => [
			'controller' => BankRequisite::class,
			'method' => 'listByContact',
			'dtoFqcn' => BankRequisiteDto::class,
			'entityTypeId' => OwnerType::CONTACT,
			'categoryId' => self::CONTACT_CATEGORY_ID,
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_REQUISITE_ADDRESS_TOOL_SET_CODE,
				'descriptionOverride' => 'List bank details of a CRM contact.',
			],
		],
		'crm.company.requisite.bankRequisite.add' => [
			'controller' => BankRequisite::class,
			'method' => 'add',
			'dtoFqcn' => BankRequisiteDto::class,
			'entityTypeId' => OwnerType::COMPANY,
			'categoryId' => self::COMPANY_CATEGORY_ID,
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_REQUISITE_ADDRESS_TOOL_SET_CODE,
				'descriptionOverride' => 'Create bank details of a CRM company.',
			],
		],
		'crm.company.requisite.bankRequisite.update' => [
			'controller' => BankRequisite::class,
			'method' => 'update',
			'dtoFqcn' => BankRequisiteDto::class,
			'entityTypeId' => OwnerType::COMPANY,
			'categoryId' => self::COMPANY_CATEGORY_ID,
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_REQUISITE_ADDRESS_TOOL_SET_CODE,
				'descriptionOverride' => 'Update bank details of a CRM company.',
			],
		],
		'crm.company.requisite.bankRequisite.get' => [
			'controller' => BankRequisite::class,
			'method' => 'get',
			'dtoFqcn' => BankRequisiteDto::class,
			'entityTypeId' => OwnerType::COMPANY,
			'categoryId' => self::COMPANY_CATEGORY_ID,
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_REQUISITE_ADDRESS_TOOL_SET_CODE,
				'descriptionOverride' => 'Get bank details of a CRM company by ID.',
			],
		],
		'crm.company.requisite.bankRequisite.list' => [
			'controller' => BankRequisite::class,
			'method' => 'listByRequisite',
			'dtoFqcn' => BankRequisiteDto::class,
			'entityTypeId' => OwnerType::COMPANY,
			'categoryId' => self::COMPANY_CATEGORY_ID,
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_REQUISITE_ADDRESS_TOOL_SET_CODE,
				'descriptionOverride' => 'List bank details attached to CRM company details.',
			],
		],
		'crm.company.requisite.bankRequisite.delete' => [
			'controller' => BankRequisite::class,
			'method' => 'delete',
			'dtoFqcn' => BankRequisiteDto::class,
			'entityTypeId' => OwnerType::COMPANY,
			'categoryId' => self::COMPANY_CATEGORY_ID,
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_REQUISITE_ADDRESS_TOOL_SET_CODE,
				'descriptionOverride' => 'Delete bank details of a CRM company.',
			],
		],
		'crm.company.bankRequisite.list' => [
			'controller' => BankRequisite::class,
			'method' => 'listByCompany',
			'dtoFqcn' => BankRequisiteDto::class,
			'entityTypeId' => OwnerType::COMPANY,
			'categoryId' => self::COMPANY_CATEGORY_ID,
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_REQUISITE_ADDRESS_TOOL_SET_CODE,
				'descriptionOverride' => 'List bank details of a CRM company.',
			],
		],
		'crm.deal.add' => [
			'controller' => Item::class,
			'method' => 'add',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::DEAL,
			'mcp' => [
				'export' => true,
				'toolSetCode' => 'crm_rest_v3_deal',
				'descriptionOverride' => 'Create a deal.',
			],
		],
		'crm.contact.add' => [
			'controller' => Item::class,
			'method' => 'add',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::CONTACT,
			'categoryId' => self::CONTACT_CATEGORY_ID,
			'mcp' => [
				'export' => true,
				'toolSetCode' => 'crm_rest_v3_contact',
				'descriptionOverride' => 'Create a contact.',
			],
		],
		'crm.lead.add' => [
			'controller' => Item::class,
			'method' => 'add',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::LEAD,
			'mcp' => [
				'export' => true,
				'toolSetCode' => 'crm_rest_v3_lead',
				'descriptionOverride' => 'Create a lead.',
			],
		],
		'crm.company.add' => [
			'controller' => Item::class,
			'method' => 'add',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::COMPANY,
			'categoryId' => self::COMPANY_CATEGORY_ID,
			'mcp' => [
				'export' => true,
				'toolSetCode' => 'crm_rest_v3_company',
				'descriptionOverride' => 'Create a company.',
			],
		],
		'crm.mycompany.add' => [
			'controller' => MyCompany::class,
			'method' => 'add',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::COMPANY,
			'dtoGeneratorArgs' => ['isMyCompany' => true],
			'mcp' => [
				'export' => true,
				'toolSetCode' => 'crm_rest_v3_my_company',
				'descriptionOverride' => 'Create an own company.',
			],
		],
		'crm.quote.add' => [
			'controller' => Item::class,
			'method' => 'add',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::QUOTE,
			'mcp' => [
				'export' => true,
				'toolSetCode' => 'crm_rest_v3_quote',
				'descriptionOverride' => 'Create a quote.',
			],
		],
		'crm.smartInvoice.add' => [
			'controller' => Item::class,
			'method' => 'add',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::SMART_INVOICE,
			'mcp' => [
				'export' => true,
				'toolSetCode' => 'crm_rest_v3_smart_invoice',
				'descriptionOverride' => 'Create a smart invoice.',
			],
		],
		'crm.deal.addFieldValue' => [
			'controller' => Item::class,
			'method' => 'addFieldValue',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::DEAL,
			'mcp' => [
				'export' => true,
				'toolSetCode' => 'crm_rest_v3_deal',
				'descriptionOverride' => 'Add a value to a multiple-value field of a deal.',
			],
		],
		'crm.lead.addFieldValue' => [
			'controller' => Item::class,
			'method' => 'addFieldValue',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::LEAD,
			'mcp' => [
				'export' => true,
				'toolSetCode' => 'crm_rest_v3_lead',
				'descriptionOverride' => 'Add a value to a multiple-value field of a lead.',
			],
		],
		'crm.quote.addFieldValue' => [
			'controller' => Item::class,
			'method' => 'addFieldValue',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::QUOTE,
			'mcp' => [
				'export' => true,
				'toolSetCode' => 'crm_rest_v3_quote',
				'descriptionOverride' => 'Add a value to a multiple-value field of a quote.',
			],
		],
		'crm.smartInvoice.addFieldValue' => [
			'controller' => Item::class,
			'method' => 'addFieldValue',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::SMART_INVOICE,
			'mcp' => [
				'export' => true,
				'toolSetCode' => 'crm_rest_v3_smart_invoice',
				'descriptionOverride' => 'Add a value to a multiple-value field of a smart invoice.',
			],
		],
		'crm.contact.addFieldValue' => [
			'controller' => Item::class,
			'method' => 'addFieldValue',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::CONTACT,
			'categoryId' => self::CONTACT_CATEGORY_ID,
			'mcp' => [
				'export' => true,
				'toolSetCode' => 'crm_rest_v3_contact',
				'descriptionOverride' => 'Add a value to a multiple-value field of a contact.',
			],
		],
		'crm.company.addFieldValue' => [
			'controller' => Item::class,
			'method' => 'addFieldValue',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::COMPANY,
			'categoryId' => self::COMPANY_CATEGORY_ID,
			'mcp' => [
				'export' => true,
				'toolSetCode' => 'crm_rest_v3_company',
				'descriptionOverride' => 'Add a value to a multiple-value field of a company.',
			],
		],
		'crm.mycompany.addFieldValue' => [
			'controller' => MyCompany::class,
			'method' => 'addFieldValue',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::COMPANY,
			'dtoGeneratorArgs' => ['isMyCompany' => true],
			'mcp' => [
				'export' => true,
				'toolSetCode' => 'crm_rest_v3_my_company',
				'descriptionOverride' => 'Add a value to a multiple-value field of an own company.',
			],
		],
		'crm.deal.update' => [
			'controller' => Item::class,
			'method' => 'update',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::DEAL,
			'mcp' => [
				'export' => true,
				'toolSetCode' => 'crm_rest_v3_deal',
				'descriptionOverride' => 'Update a deal.',
			],
		],
		'crm.contact.update' => [
			'controller' => Item::class,
			'method' => 'update',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::CONTACT,
			'categoryId' => self::CONTACT_CATEGORY_ID,
			'mcp' => [
				'export' => true,
				'toolSetCode' => 'crm_rest_v3_contact',
				'descriptionOverride' => 'Update a contact.',
			],
		],
		'crm.lead.update' => [
			'controller' => Item::class,
			'method' => 'update',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::LEAD,
			'mcp' => [
				'export' => true,
				'toolSetCode' => 'crm_rest_v3_lead',
				'descriptionOverride' => 'Update a lead.',
			],
		],
		'crm.company.update' => [
			'controller' => Item::class,
			'method' => 'update',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::COMPANY,
			'categoryId' => self::COMPANY_CATEGORY_ID,
			'mcp' => [
				'export' => true,
				'toolSetCode' => 'crm_rest_v3_company',
				'descriptionOverride' => 'Update a company.',
			],
		],
		'crm.mycompany.update' => [
			'controller' => MyCompany::class,
			'method' => 'update',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::COMPANY,
			'dtoGeneratorArgs' => ['isMyCompany' => true],
			'mcp' => [
				'export' => true,
				'toolSetCode' => 'crm_rest_v3_my_company',
				'descriptionOverride' => 'Update an own company.',
			],
		],
		'crm.quote.update' => [
			'controller' => Item::class,
			'method' => 'update',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::QUOTE,
			'mcp' => [
				'export' => true,
				'toolSetCode' => 'crm_rest_v3_quote',
				'descriptionOverride' => 'Update a quote.',
			],
		],
		'crm.smartInvoice.update' => [
			'controller' => Item::class,
			'method' => 'update',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::SMART_INVOICE,
			'mcp' => [
				'export' => true,
				'toolSetCode' => 'crm_rest_v3_smart_invoice',
				'descriptionOverride' => 'Update a smart invoice.',
			],
		],
		'crm.deal.delete' => [
			'controller' => Item::class,
			'method' => 'delete',
			'dtoFqcn' => DeleteItemDto::class,
			'entityTypeId' => OwnerType::DEAL,
			'mcp' => [
				'export' => true,
				'toolSetCode' => 'crm_rest_v3_deal',
				'descriptionOverride' => 'Delete a deal.',
			],
		],
		'crm.lead.delete' => [
			'controller' => Item::class,
			'method' => 'delete',
			'dtoFqcn' => DeleteItemDto::class,
			'entityTypeId' => OwnerType::LEAD,
			'mcp' => [
				'export' => true,
				'toolSetCode' => 'crm_rest_v3_lead',
				'descriptionOverride' => 'Delete a lead.',
			],
		],
		'crm.quote.delete' => [
			'controller' => Item::class,
			'method' => 'delete',
			'dtoFqcn' => DeleteItemDto::class,
			'entityTypeId' => OwnerType::QUOTE,
			'mcp' => [
				'export' => true,
				'toolSetCode' => 'crm_rest_v3_quote',
				'descriptionOverride' => 'Delete a quote.',
			],
		],
		'crm.smartInvoice.delete' => [
			'controller' => Item::class,
			'method' => 'delete',
			'dtoFqcn' => DeleteItemDto::class,
			'entityTypeId' => OwnerType::SMART_INVOICE,
			'mcp' => [
				'export' => true,
				'toolSetCode' => 'crm_rest_v3_smart_invoice',
				'descriptionOverride' => 'Delete a smart invoice.',
			],
		],
		'crm.deal.deleteFieldValue' => [
			'controller' => Item::class,
			'method' => 'deleteFieldValue',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::DEAL,
			'mcp' => [
				'export' => true,
				'toolSetCode' => 'crm_rest_v3_deal',
				'descriptionOverride' => 'Remove a value from a multiple-value field of a deal.',
			],
		],
		'crm.lead.deleteFieldValue' => [
			'controller' => Item::class,
			'method' => 'deleteFieldValue',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::LEAD,
			'mcp' => [
				'export' => true,
				'toolSetCode' => 'crm_rest_v3_lead',
				'descriptionOverride' => 'Remove a value from a multiple-value field of a lead.',
			],
		],
		'crm.quote.deleteFieldValue' => [
			'controller' => Item::class,
			'method' => 'deleteFieldValue',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::QUOTE,
			'mcp' => [
				'export' => true,
				'toolSetCode' => 'crm_rest_v3_quote',
				'descriptionOverride' => 'Remove a value from a multiple-value field of a quote.',
			],
		],
		'crm.smartInvoice.deleteFieldValue' => [
			'controller' => Item::class,
			'method' => 'deleteFieldValue',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::SMART_INVOICE,
			'mcp' => [
				'export' => true,
				'toolSetCode' => 'crm_rest_v3_smart_invoice',
				'descriptionOverride' => 'Remove a value from a multiple-value field of a smart invoice.',
			],
		],
		'crm.contact.delete' => [
			'controller' => Item::class,
			'method' => 'delete',
			'dtoFqcn' => DeleteItemDto::class,
			'entityTypeId' => OwnerType::CONTACT,
			'categoryId' => self::CONTACT_CATEGORY_ID,
			'mcp' => [
				'export' => true,
				'toolSetCode' => 'crm_rest_v3_contact',
				'descriptionOverride' => 'Delete a contact.',
			],
		],
		'crm.company.delete' => [
			'controller' => Item::class,
			'method' => 'delete',
			'dtoFqcn' => DeleteItemDto::class,
			'entityTypeId' => OwnerType::COMPANY,
			'categoryId' => self::COMPANY_CATEGORY_ID,
			'mcp' => [
				'export' => true,
				'toolSetCode' => 'crm_rest_v3_company',
				'descriptionOverride' => 'Delete a company.',
			],
		],
		'crm.mycompany.delete' => [
			'controller' => MyCompany::class,
			'method' => 'delete',
			'dtoFqcn' => DeleteItemDto::class,
			'entityTypeId' => OwnerType::COMPANY,
			'mcp' => [
				'export' => true,
				'toolSetCode' => 'crm_rest_v3_my_company',
				'descriptionOverride' => 'Delete an own company.',
			],
		],
		'crm.contact.deleteFieldValue' => [
			'controller' => Item::class,
			'method' => 'deleteFieldValue',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::CONTACT,
			'categoryId' => self::CONTACT_CATEGORY_ID,
			'mcp' => [
				'export' => true,
				'toolSetCode' => 'crm_rest_v3_contact',
				'descriptionOverride' => 'Remove a value from a multiple-value field of a contact.',
			],
		],
		'crm.company.deleteFieldValue' => [
			'controller' => Item::class,
			'method' => 'deleteFieldValue',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::COMPANY,
			'categoryId' => self::COMPANY_CATEGORY_ID,
			'mcp' => [
				'export' => true,
				'toolSetCode' => 'crm_rest_v3_company',
				'descriptionOverride' => 'Remove a value from a multiple-value field of a company.',
			],
		],
		'crm.mycompany.deleteFieldValue' => [
			'controller' => MyCompany::class,
			'method' => 'deleteFieldValue',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::COMPANY,
			'dtoGeneratorArgs' => ['isMyCompany' => true],
			'mcp' => [
				'export' => true,
				'toolSetCode' => 'crm_rest_v3_my_company',
				'descriptionOverride' => 'Remove a value from a multiple-value field of an own company.',
			],
		],
		'crm.deal.get' => [
			'controller' => Item::class,
			'method' => 'get',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::DEAL,
			'mcp' => [
				'export' => true,
				'toolSetCode' => 'crm_rest_v3_deal',
				'descriptionOverride' => 'Get a deal by ID.',
			],
		],
		'crm.contact.get' => [
			'controller' => Item::class,
			'method' => 'get',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::CONTACT,
			'categoryId' => self::CONTACT_CATEGORY_ID,
			'mcp' => [
				'export' => true,
				'toolSetCode' => 'crm_rest_v3_contact',
				'descriptionOverride' => 'Get a contact by ID.',
			],
		],
		'crm.company.get' => [
			'controller' => Item::class,
			'method' => 'get',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::COMPANY,
			'categoryId' => self::COMPANY_CATEGORY_ID,
			'mcp' => [
				'export' => true,
				'toolSetCode' => 'crm_rest_v3_company',
				'descriptionOverride' => 'Get a company by ID.',
			],
		],
		'crm.mycompany.get' => [
			'controller' => MyCompany::class,
			'method' => 'get',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::COMPANY,
			'dtoGeneratorArgs' => ['isMyCompany' => true],
			'mcp' => [
				'export' => true,
				'toolSetCode' => 'crm_rest_v3_my_company',
				'descriptionOverride' => 'Get an own company by ID.',
			],
		],
		'crm.lead.get' => [
			'controller' => Item::class,
			'method' => 'get',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::LEAD,
			'mcp' => [
				'export' => true,
				'toolSetCode' => 'crm_rest_v3_lead',
				'descriptionOverride' => 'Get a lead by ID.',
			],
		],
		'crm.quote.get' => [
			'controller' => Item::class,
			'method' => 'get',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::QUOTE,
			'mcp' => [
				'export' => true,
				'toolSetCode' => 'crm_rest_v3_quote',
				'descriptionOverride' => 'Get a quote by ID.',
			],
		],
		'crm.smartInvoice.get' => [
			'controller' => Item::class,
			'method' => 'get',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::SMART_INVOICE,
			'mcp' => [
				'export' => true,
				'toolSetCode' => 'crm_rest_v3_smart_invoice',
				'descriptionOverride' => 'Get a smart invoice by ID.',
			],
		],
		'crm.deal.list' => [
			'controller' => Item::class,
			'method' => 'list',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::DEAL,
			'mcp' => [
				'export' => true,
				'toolSetCode' => 'crm_rest_v3_deal',
				'descriptionOverride' => 'List deals.',
			],
		],
		'crm.contact.list' => [
			'controller' => Item::class,
			'method' => 'list',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::CONTACT,
			'categoryId' => self::CONTACT_CATEGORY_ID,
			'mcp' => [
				'export' => true,
				'toolSetCode' => 'crm_rest_v3_contact',
				'descriptionOverride' => 'List contacts.',
			],
		],
		'crm.company.list' => [
			'controller' => Item::class,
			'method' => 'list',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::COMPANY,
			'categoryId' => self::COMPANY_CATEGORY_ID,
			'mcp' => [
				'export' => true,
				'toolSetCode' => 'crm_rest_v3_company',
				'descriptionOverride' => 'List companies.',
			],
		],
		'crm.mycompany.list' => [
			'controller' => MyCompany::class,
			'method' => 'list',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::COMPANY,
			'dtoGeneratorArgs' => ['isMyCompany' => true],
			'mcp' => [
				'export' => true,
				'toolSetCode' => 'crm_rest_v3_my_company',
				'descriptionOverride' => 'List own companies.',
			],
		],
		'crm.lead.list' => [
			'controller' => Item::class,
			'method' => 'list',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::LEAD,
			'mcp' => [
				'export' => true,
				'toolSetCode' => 'crm_rest_v3_lead',
				'descriptionOverride' => 'List leads.',
			],
		],
		'crm.quote.list' => [
			'controller' => Item::class,
			'method' => 'list',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::QUOTE,
			'mcp' => [
				'export' => true,
				'toolSetCode' => 'crm_rest_v3_quote',
				'descriptionOverride' => 'List quotes.',
			],
		],
		'crm.smartInvoice.list' => [
			'controller' => Item::class,
			'method' => 'list',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::SMART_INVOICE,
			'mcp' => [
				'export' => true,
				'toolSetCode' => 'crm_rest_v3_smart_invoice',
				'descriptionOverride' => 'List smart invoices.',
			],
		],
		'crm.deal.aggregate' => [
			'controller' => Item::class,
			'method' => 'aggregate',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::DEAL,
			'mcp' => [
				'export' => true,
				'toolSetCode' => 'crm_rest_v3_deal',
				'descriptionOverride' => 'Calculate aggregates for deals.',
			],
		],
		'crm.contact.aggregate' => [
			'controller' => Item::class,
			'method' => 'aggregate',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::CONTACT,
			'categoryId' => self::CONTACT_CATEGORY_ID,
			'mcp' => [
				'export' => true,
				'toolSetCode' => 'crm_rest_v3_contact',
				'descriptionOverride' => 'Calculate aggregates for contacts.',
			],
		],
		'crm.company.aggregate' => [
			'controller' => Item::class,
			'method' => 'aggregate',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::COMPANY,
			'categoryId' => self::COMPANY_CATEGORY_ID,
			'mcp' => [
				'export' => true,
				'toolSetCode' => 'crm_rest_v3_company',
				'descriptionOverride' => 'Calculate aggregates for companies.',
			],
		],
		'crm.mycompany.aggregate' => [
			'controller' => MyCompany::class,
			'method' => 'aggregate',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::COMPANY,
			'dtoGeneratorArgs' => ['isMyCompany' => true],
			'mcp' => [
				'export' => true,
				'toolSetCode' => 'crm_rest_v3_my_company',
				'descriptionOverride' => 'Calculate aggregates for own companies.',
			],
		],
		'crm.lead.aggregate' => [
			'controller' => Item::class,
			'method' => 'aggregate',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::LEAD,
			'mcp' => [
				'export' => true,
				'toolSetCode' => 'crm_rest_v3_lead',
				'descriptionOverride' => 'Calculate aggregates for leads.',
			],
		],
		'crm.quote.aggregate' => [
			'controller' => Item::class,
			'method' => 'aggregate',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::QUOTE,
			'mcp' => [
				'export' => true,
				'toolSetCode' => 'crm_rest_v3_quote',
				'descriptionOverride' => 'Calculate aggregates for quotes.',
			],
		],
		'crm.smartInvoice.aggregate' => [
			'controller' => Item::class,
			'method' => 'aggregate',
			'dtoGenerator' => ItemDtoGenerator::class,
			'entityTypeId' => OwnerType::SMART_INVOICE,
			'mcp' => [
				'export' => true,
				'toolSetCode' => 'crm_rest_v3_smart_invoice',
				'descriptionOverride' => 'Calculate aggregates for smart invoices.',
			],
		],
		'crm.deal.field.list' => [
			'controller' => Field::class,
			'method' => 'list',
			'dtoFqcn' => CrmFieldMetadataDto::class,
			'entityTypeId' => OwnerType::DEAL,
			'mcp' => [
				'export' => true,
				'toolSetCode' => 'crm_rest_v3_deal',
			],
		],
		'crm.deal.field.get' => [
			'controller' => Field::class,
			'method' => 'get',
			'dtoFqcn' => CrmFieldMetadataDto::class,
			'entityTypeId' => OwnerType::DEAL,
			'mcp' => [
				'export' => true,
				'toolSetCode' => 'crm_rest_v3_deal',
			],
		],
		'crm.contact.field.list' => [
			'controller' => Field::class,
			'method' => 'list',
			'dtoFqcn' => CrmFieldMetadataDto::class,
			'entityTypeId' => OwnerType::CONTACT,
			'mcp' => [
				'export' => true,
				'toolSetCode' => 'crm_rest_v3_contact',
			],
		],
		'crm.contact.field.get' => [
			'controller' => Field::class,
			'method' => 'get',
			'dtoFqcn' => CrmFieldMetadataDto::class,
			'entityTypeId' => OwnerType::CONTACT,
			'mcp' => [
				'export' => true,
				'toolSetCode' => 'crm_rest_v3_contact',
			],
		],
		'crm.lead.field.list' => [
			'controller' => Field::class,
			'method' => 'list',
			'dtoFqcn' => CrmFieldMetadataDto::class,
			'entityTypeId' => OwnerType::LEAD,
			'mcp' => [
				'export' => true,
				'toolSetCode' => 'crm_rest_v3_lead',
			],
		],
		'crm.lead.field.get' => [
			'controller' => Field::class,
			'method' => 'get',
			'dtoFqcn' => CrmFieldMetadataDto::class,
			'entityTypeId' => OwnerType::LEAD,
			'mcp' => [
				'export' => true,
				'toolSetCode' => 'crm_rest_v3_lead',
			],
		],
		'crm.company.field.list' => [
			'controller' => Field::class,
			'method' => 'list',
			'dtoFqcn' => CrmFieldMetadataDto::class,
			'entityTypeId' => OwnerType::COMPANY,
			'mcp' => [
				'export' => true,
				'toolSetCode' => 'crm_rest_v3_company',
			],
		],
		'crm.company.field.get' => [
			'controller' => Field::class,
			'method' => 'get',
			'dtoFqcn' => CrmFieldMetadataDto::class,
			'entityTypeId' => OwnerType::COMPANY,
			'mcp' => [
				'export' => true,
				'toolSetCode' => 'crm_rest_v3_company',
			],
		],
		'crm.quote.field.list' => [
			'controller' => Field::class,
			'method' => 'list',
			'dtoFqcn' => CrmFieldMetadataDto::class,
			'entityTypeId' => OwnerType::QUOTE,
			'mcp' => [
				'export' => true,
				'toolSetCode' => 'crm_rest_v3_quote',
			],
		],
		'crm.quote.field.get' => [
			'controller' => Field::class,
			'method' => 'get',
			'dtoFqcn' => CrmFieldMetadataDto::class,
			'entityTypeId' => OwnerType::QUOTE,
			'mcp' => [
				'export' => true,
				'toolSetCode' => 'crm_rest_v3_quote',
			],
		],
		'crm.smartInvoice.field.list' => [
			'controller' => Field::class,
			'method' => 'list',
			'dtoFqcn' => CrmFieldMetadataDto::class,
			'entityTypeId' => OwnerType::SMART_INVOICE,
			'mcp' => [
				'export' => true,
				'toolSetCode' => 'crm_rest_v3_smart_invoice',
			],
		],
		'crm.smartInvoice.field.get' => [
			'controller' => Field::class,
			'method' => 'get',
			'dtoFqcn' => CrmFieldMetadataDto::class,
			'entityTypeId' => OwnerType::SMART_INVOICE,
			'mcp' => [
				'export' => true,
				'toolSetCode' => 'crm_rest_v3_smart_invoice',
			],
		],
		'crm.dictionary.entityType.get' => [
			'controller' => Dictionary\EntityType::class,
			'method' => 'get',
			'dtoFqcn' => EntityTypeDto::class,
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_DICTIONARY_TOOL_SET_CODE,
			],
		],
		'crm.dictionary.entityType.list' => [
			'controller' => Dictionary\EntityType::class,
			'method' => 'list',
			'dtoFqcn' => EntityTypeDto::class,
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_DICTIONARY_TOOL_SET_CODE,
			],
		],
		'crm.dictionary.stageSemantic.get' => [
			'controller' => StageSemantic::class,
			'method' => 'get',
			'dtoFqcn' => StageSemanticDto::class,
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_DICTIONARY_TOOL_SET_CODE,
			],
		],
		'crm.dictionary.stageSemantic.list' => [
			'controller' => StageSemantic::class,
			'method' => 'list',
			'dtoFqcn' => StageSemanticDto::class,
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_DICTIONARY_TOOL_SET_CODE,
			],
		],
		'crm.dictionary.addressType.get' => [
			'controller' => AddressType::class,
			'method' => 'get',
			'dtoFqcn' => AddressTypeDto::class,
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_DICTIONARY_TOOL_SET_CODE,
			],
		],
		'crm.dictionary.addressType.list' => [
			'controller' => AddressType::class,
			'method' => 'list',
			'dtoFqcn' => AddressTypeDto::class,
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_DICTIONARY_TOOL_SET_CODE,
			],
		],
		// The four dictionaries of communication value types share one controller, and which of them a
		// method answers about is a service parameter of the route - the client has no say in it. A
		// segment of mixed case like `emailType` is out of reach of the scanning of controllers anyway,
		// so registering these by configuration is the only way.
		'crm.dictionary.emailType.get' => [
			'controller' => Dictionary\CommunicationType::class,
			'method' => 'get',
			'dtoFqcn' => CommunicationTypeDto::class,
			'queryParams' => ['multifieldTypeId' => Type\Email::ID],
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_DICTIONARY_TOOL_SET_CODE,
				'descriptionOverride' => 'Get an email address type by ID.',
			],
		],
		'crm.dictionary.emailType.list' => [
			'controller' => Dictionary\CommunicationType::class,
			'method' => 'list',
			'dtoFqcn' => CommunicationTypeDto::class,
			'queryParams' => ['multifieldTypeId' => Type\Email::ID],
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_DICTIONARY_TOOL_SET_CODE,
				'descriptionOverride' => 'List email address types.',
			],
		],
		'crm.dictionary.phoneType.get' => [
			'controller' => Dictionary\CommunicationType::class,
			'method' => 'get',
			'dtoFqcn' => CommunicationTypeDto::class,
			'queryParams' => ['multifieldTypeId' => Type\Phone::ID],
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_DICTIONARY_TOOL_SET_CODE,
				'descriptionOverride' => 'Get a phone number type by ID.',
			],
		],
		'crm.dictionary.phoneType.list' => [
			'controller' => Dictionary\CommunicationType::class,
			'method' => 'list',
			'dtoFqcn' => CommunicationTypeDto::class,
			'queryParams' => ['multifieldTypeId' => Type\Phone::ID],
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_DICTIONARY_TOOL_SET_CODE,
				'descriptionOverride' => 'List phone number types.',
			],
		],
		'crm.dictionary.imType.get' => [
			'controller' => Dictionary\CommunicationType::class,
			'method' => 'get',
			'dtoFqcn' => CommunicationTypeDto::class,
			'queryParams' => ['multifieldTypeId' => Type\Im::ID],
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_DICTIONARY_TOOL_SET_CODE,
				'descriptionOverride' => 'Get an instant messenger address type by ID.',
			],
		],
		'crm.dictionary.imType.list' => [
			'controller' => Dictionary\CommunicationType::class,
			'method' => 'list',
			'dtoFqcn' => CommunicationTypeDto::class,
			'queryParams' => ['multifieldTypeId' => Type\Im::ID],
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_DICTIONARY_TOOL_SET_CODE,
				'descriptionOverride' => 'List instant messenger address types.',
			],
		],
		'crm.dictionary.webType.get' => [
			'controller' => Dictionary\CommunicationType::class,
			'method' => 'get',
			'dtoFqcn' => CommunicationTypeDto::class,
			'queryParams' => ['multifieldTypeId' => Type\Web::ID],
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_DICTIONARY_TOOL_SET_CODE,
				'descriptionOverride' => 'Get a website address type by ID.',
			],
		],
		'crm.dictionary.webType.list' => [
			'controller' => Dictionary\CommunicationType::class,
			'method' => 'list',
			'dtoFqcn' => CommunicationTypeDto::class,
			'queryParams' => ['multifieldTypeId' => Type\Web::ID],
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_DICTIONARY_TOOL_SET_CODE,
				'descriptionOverride' => 'List website address types.',
			],
		],
		// The address group of a contact and a company: one controller, the entity type of the method
		// name as its trusted invariant, and the name the owner is published under as the argument of
		// the generator of the contract - `entityTypeId` the schema adds to those arguments itself.
		'crm.contact.address.add' => [
			'controller' => OwnerAddressController::class,
			'method' => 'add',
			'dtoGenerator' => OwnerAddressDtoGenerator::class,
			'entityTypeId' => OwnerType::CONTACT,
			'categoryId' => self::CONTACT_CATEGORY_ID,
			'dtoGeneratorArgs' => [
				'ownerFieldName' => OwnerAddressController::OWNER_FIELD_NAME_BY_ENTITY_TYPE_ID[OwnerType::CONTACT],
			],
			'scopes' => self::ADDRESS_SCOPES,
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_REQUISITE_ADDRESS_TOOL_SET_CODE,
				'descriptionOverride' => 'Create a contact address.',
			],
		],
		'crm.contact.address.update' => [
			'controller' => OwnerAddressController::class,
			'method' => 'update',
			'dtoGenerator' => OwnerAddressDtoGenerator::class,
			'entityTypeId' => OwnerType::CONTACT,
			'categoryId' => self::CONTACT_CATEGORY_ID,
			'dtoGeneratorArgs' => [
				'ownerFieldName' => OwnerAddressController::OWNER_FIELD_NAME_BY_ENTITY_TYPE_ID[OwnerType::CONTACT],
			],
			'scopes' => self::ADDRESS_SCOPES,
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_REQUISITE_ADDRESS_TOOL_SET_CODE,
				'descriptionOverride' => 'Update a contact address.',
			],
		],
		'crm.contact.address.get' => [
			'controller' => OwnerAddressController::class,
			'method' => 'get',
			'dtoGenerator' => OwnerAddressDtoGenerator::class,
			'entityTypeId' => OwnerType::CONTACT,
			'categoryId' => self::CONTACT_CATEGORY_ID,
			'dtoGeneratorArgs' => [
				'ownerFieldName' => OwnerAddressController::OWNER_FIELD_NAME_BY_ENTITY_TYPE_ID[OwnerType::CONTACT],
			],
			'scopes' => self::ADDRESS_SCOPES,
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_REQUISITE_ADDRESS_TOOL_SET_CODE,
				'descriptionOverride' => 'Get a contact address by ID.',
			],
		],
		'crm.contact.address.delete' => [
			'controller' => OwnerAddressController::class,
			'method' => 'delete',
			'dtoGenerator' => OwnerAddressDtoGenerator::class,
			'entityTypeId' => OwnerType::CONTACT,
			'categoryId' => self::CONTACT_CATEGORY_ID,
			'dtoGeneratorArgs' => [
				'ownerFieldName' => OwnerAddressController::OWNER_FIELD_NAME_BY_ENTITY_TYPE_ID[OwnerType::CONTACT],
			],
			'scopes' => self::ADDRESS_SCOPES,
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_REQUISITE_ADDRESS_TOOL_SET_CODE,
				'descriptionOverride' => 'Delete a contact address.',
			],
		],
		'crm.contact.address.list' => [
			'controller' => OwnerAddressController::class,
			'method' => 'list',
			'dtoGenerator' => OwnerAddressDtoGenerator::class,
			'entityTypeId' => OwnerType::CONTACT,
			'categoryId' => self::CONTACT_CATEGORY_ID,
			'dtoGeneratorArgs' => [
				'ownerFieldName' => OwnerAddressController::OWNER_FIELD_NAME_BY_ENTITY_TYPE_ID[OwnerType::CONTACT],
			],
			'scopes' => self::ADDRESS_SCOPES,
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_REQUISITE_ADDRESS_TOOL_SET_CODE,
				'descriptionOverride' => 'List contact addresses.',
			],
		],
		'crm.company.address.add' => [
			'controller' => OwnerAddressController::class,
			'method' => 'add',
			'dtoGenerator' => OwnerAddressDtoGenerator::class,
			'entityTypeId' => OwnerType::COMPANY,
			'categoryId' => self::COMPANY_CATEGORY_ID,
			'dtoGeneratorArgs' => [
				'ownerFieldName' => OwnerAddressController::OWNER_FIELD_NAME_BY_ENTITY_TYPE_ID[OwnerType::COMPANY],
			],
			'scopes' => self::ADDRESS_SCOPES,
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_REQUISITE_ADDRESS_TOOL_SET_CODE,
				'descriptionOverride' => 'Create a company address.',
			],
		],
		'crm.company.address.update' => [
			'controller' => OwnerAddressController::class,
			'method' => 'update',
			'dtoGenerator' => OwnerAddressDtoGenerator::class,
			'entityTypeId' => OwnerType::COMPANY,
			'categoryId' => self::COMPANY_CATEGORY_ID,
			'dtoGeneratorArgs' => [
				'ownerFieldName' => OwnerAddressController::OWNER_FIELD_NAME_BY_ENTITY_TYPE_ID[OwnerType::COMPANY],
			],
			'scopes' => self::ADDRESS_SCOPES,
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_REQUISITE_ADDRESS_TOOL_SET_CODE,
				'descriptionOverride' => 'Update a company address.',
			],
		],
		'crm.company.address.get' => [
			'controller' => OwnerAddressController::class,
			'method' => 'get',
			'dtoGenerator' => OwnerAddressDtoGenerator::class,
			'entityTypeId' => OwnerType::COMPANY,
			'categoryId' => self::COMPANY_CATEGORY_ID,
			'dtoGeneratorArgs' => [
				'ownerFieldName' => OwnerAddressController::OWNER_FIELD_NAME_BY_ENTITY_TYPE_ID[OwnerType::COMPANY],
			],
			'scopes' => self::ADDRESS_SCOPES,
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_REQUISITE_ADDRESS_TOOL_SET_CODE,
				'descriptionOverride' => 'Get a company address by ID.',
			],
		],
		'crm.company.address.delete' => [
			'controller' => OwnerAddressController::class,
			'method' => 'delete',
			'dtoGenerator' => OwnerAddressDtoGenerator::class,
			'entityTypeId' => OwnerType::COMPANY,
			'categoryId' => self::COMPANY_CATEGORY_ID,
			'dtoGeneratorArgs' => [
				'ownerFieldName' => OwnerAddressController::OWNER_FIELD_NAME_BY_ENTITY_TYPE_ID[OwnerType::COMPANY],
			],
			'scopes' => self::ADDRESS_SCOPES,
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_REQUISITE_ADDRESS_TOOL_SET_CODE,
				'descriptionOverride' => 'Delete a company address.',
			],
		],
		'crm.company.address.list' => [
			'controller' => OwnerAddressController::class,
			'method' => 'list',
			'dtoGenerator' => OwnerAddressDtoGenerator::class,
			'entityTypeId' => OwnerType::COMPANY,
			'categoryId' => self::COMPANY_CATEGORY_ID,
			'dtoGeneratorArgs' => [
				'ownerFieldName' => OwnerAddressController::OWNER_FIELD_NAME_BY_ENTITY_TYPE_ID[OwnerType::COMPANY],
			],
			'scopes' => self::ADDRESS_SCOPES,
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_REQUISITE_ADDRESS_TOOL_SET_CODE,
				'descriptionOverride' => 'List company addresses.',
			],
		],
		'crm.lead.address.get' => [
			'controller' => Address::class,
			'method' => 'get',
			'dtoFqcn' => AddressDto::class,
			'scopes' => ['crm'],
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_REQUISITE_ADDRESS_TOOL_SET_CODE,
				'descriptionOverride' => 'Get the address of a CRM lead.',
			],
		],
		'crm.lead.address.set' => [
			'controller' => Address::class,
			'method' => 'set',
			'dtoFqcn' => AddressDto::class,
			'scopes' => ['crm'],
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_REQUISITE_ADDRESS_TOOL_SET_CODE,
				'descriptionOverride' => 'Set the address of a CRM lead.',
			],
		],
		'crm.lead.address.delete' => [
			'controller' => Address::class,
			'method' => 'delete',
			'dtoFqcn' => AddressDto::class,
			'scopes' => ['crm'],
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_REQUISITE_ADDRESS_TOOL_SET_CODE,
				'descriptionOverride' => 'Delete the address of a CRM lead.',
			],
		],
		'crm.activity.mail.getContent' => [
			'controller' => Mail::class,
			'method' => 'getContent',
			'dtoFqcn' => EmailActivityDto::class,
		],
		'crm.activity.mail.getThread' => [
			'controller' => Mail::class,
			'method' => 'getThread',
			'dtoFqcn' => EmailActivityDto::class,
		],
		'crm.activity.mail.reply' => [
			'controller' => Mail::class,
			'method' => 'reply',
			'dtoFqcn' => EmailActivityDto::class,
		],
		'crm.deal.timeline.activity.email.list' => [
			'controller' => Email::class,
			'method' => 'list',
			'dtoFqcn' => EmailActivityDto::class,
			'entityTypeId' => OwnerType::DEAL,
		],
		'crm.deal.timeline.activity.email.send' => [
			'controller' => Email::class,
			'method' => 'send',
			'dtoFqcn' => EmailActivityDto::class,
			'entityTypeId' => OwnerType::DEAL,
		],
		'crm.lead.timeline.activity.email.list' => [
			'controller' => Email::class,
			'method' => 'list',
			'dtoFqcn' => EmailActivityDto::class,
			'entityTypeId' => OwnerType::LEAD,
		],
		'crm.lead.timeline.activity.email.send' => [
			'controller' => Email::class,
			'method' => 'send',
			'dtoFqcn' => EmailActivityDto::class,
			'entityTypeId' => OwnerType::LEAD,
		],
		'crm.contact.timeline.activity.email.list' => [
			'controller' => Email::class,
			'method' => 'list',
			'dtoFqcn' => EmailActivityDto::class,
			'entityTypeId' => OwnerType::CONTACT,
		],
		'crm.contact.timeline.activity.email.send' => [
			'controller' => Email::class,
			'method' => 'send',
			'dtoFqcn' => EmailActivityDto::class,
			'entityTypeId' => OwnerType::CONTACT,
		],
		'crm.company.timeline.activity.email.list' => [
			'controller' => Email::class,
			'method' => 'list',
			'dtoFqcn' => EmailActivityDto::class,
			'entityTypeId' => OwnerType::COMPANY,
		],
		'crm.company.timeline.activity.email.send' => [
			'controller' => Email::class,
			'method' => 'send',
			'dtoFqcn' => EmailActivityDto::class,
			'entityTypeId' => OwnerType::COMPANY,
		],
		'crm.lead.productRow.add' => [
			'controller' => ProductRow::class,
			'method' => 'add',
			'dtoFqcn' => ProductRowDto::class,
			'entityTypeId' => OwnerType::LEAD,
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_PRODUCT_ROW_TOOL_SET_CODE,
				'descriptionOverride' => 'Create a product row of a CRM lead.',
			],
		],
		'crm.lead.productRow.update' => [
			'controller' => ProductRow::class,
			'method' => 'update',
			'dtoFqcn' => ProductRowDto::class,
			'entityTypeId' => OwnerType::LEAD,
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_PRODUCT_ROW_TOOL_SET_CODE,
				'descriptionOverride' => 'Update a product row of a CRM lead.',
			],
		],
		'crm.lead.productRow.get' => [
			'controller' => ProductRow::class,
			'method' => 'get',
			'dtoFqcn' => ProductRowDto::class,
			'entityTypeId' => OwnerType::LEAD,
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_PRODUCT_ROW_TOOL_SET_CODE,
				'descriptionOverride' => 'Get a product row of a CRM lead by ID.',
			],
		],
		'crm.lead.productRow.delete' => [
			'controller' => ProductRow::class,
			'method' => 'delete',
			'dtoFqcn' => ProductRowDto::class,
			'entityTypeId' => OwnerType::LEAD,
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_PRODUCT_ROW_TOOL_SET_CODE,
				'descriptionOverride' => 'Delete a product row of a CRM lead.',
			],
		],
		'crm.lead.productRow.list' => [
			'controller' => ProductRow::class,
			'method' => 'list',
			'dtoFqcn' => ProductRowDto::class,
			'entityTypeId' => OwnerType::LEAD,
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_PRODUCT_ROW_TOOL_SET_CODE,
				'descriptionOverride' => 'List the product rows of a CRM lead.',
			],
		],
		'crm.lead.productRow.replace' => [
			'controller' => ProductRow::class,
			'method' => 'replace',
			'dtoFqcn' => ProductRowDto::class,
			'entityTypeId' => OwnerType::LEAD,
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_PRODUCT_ROW_TOOL_SET_CODE,
				'descriptionOverride' => 'Replace the product rows of a CRM lead.',
			],
		],
		'crm.lead.productRow.getAvailableForPayment' => [
			'controller' => ProductRow::class,
			'method' => 'getAvailableForPayment',
			'dtoFqcn' => ProductRowDto::class,
			'entityTypeId' => OwnerType::LEAD,
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_PRODUCT_ROW_TOOL_SET_CODE,
				'descriptionOverride' => 'List product rows available for payment for a CRM lead.',
			],
		],
		'crm.deal.productRow.add' => [
			'controller' => ProductRow::class,
			'method' => 'add',
			'dtoFqcn' => ProductRowDto::class,
			'entityTypeId' => OwnerType::DEAL,
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_PRODUCT_ROW_TOOL_SET_CODE,
				'descriptionOverride' => 'Create a product row of a CRM deal.',
			],
		],
		'crm.deal.productRow.update' => [
			'controller' => ProductRow::class,
			'method' => 'update',
			'dtoFqcn' => ProductRowDto::class,
			'entityTypeId' => OwnerType::DEAL,
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_PRODUCT_ROW_TOOL_SET_CODE,
				'descriptionOverride' => 'Update a product row of a CRM deal.',
			],
		],
		'crm.deal.productRow.get' => [
			'controller' => ProductRow::class,
			'method' => 'get',
			'dtoFqcn' => ProductRowDto::class,
			'entityTypeId' => OwnerType::DEAL,
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_PRODUCT_ROW_TOOL_SET_CODE,
				'descriptionOverride' => 'Get a product row of a CRM deal by ID.',
			],
		],
		'crm.deal.productRow.delete' => [
			'controller' => ProductRow::class,
			'method' => 'delete',
			'dtoFqcn' => ProductRowDto::class,
			'entityTypeId' => OwnerType::DEAL,
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_PRODUCT_ROW_TOOL_SET_CODE,
				'descriptionOverride' => 'Delete a product row of a CRM deal.',
			],
		],
		'crm.deal.productRow.list' => [
			'controller' => ProductRow::class,
			'method' => 'list',
			'dtoFqcn' => ProductRowDto::class,
			'entityTypeId' => OwnerType::DEAL,
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_PRODUCT_ROW_TOOL_SET_CODE,
				'descriptionOverride' => 'List the product rows of a CRM deal.',
			],
		],
		'crm.deal.productRow.replace' => [
			'controller' => ProductRow::class,
			'method' => 'replace',
			'dtoFqcn' => ProductRowDto::class,
			'entityTypeId' => OwnerType::DEAL,
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_PRODUCT_ROW_TOOL_SET_CODE,
				'descriptionOverride' => 'Replace the product rows of a CRM deal.',
			],
		],
		'crm.deal.productRow.getAvailableForPayment' => [
			'controller' => ProductRow::class,
			'method' => 'getAvailableForPayment',
			'dtoFqcn' => ProductRowDto::class,
			'entityTypeId' => OwnerType::DEAL,
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_PRODUCT_ROW_TOOL_SET_CODE,
				'descriptionOverride' => 'List product rows available for payment for a CRM deal.',
			],
		],
		'crm.quote.productRow.add' => [
			'controller' => ProductRow::class,
			'method' => 'add',
			'dtoFqcn' => ProductRowDto::class,
			'entityTypeId' => OwnerType::QUOTE,
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_PRODUCT_ROW_TOOL_SET_CODE,
				'descriptionOverride' => 'Create a product row of a CRM quote.',
			],
		],
		'crm.quote.productRow.update' => [
			'controller' => ProductRow::class,
			'method' => 'update',
			'dtoFqcn' => ProductRowDto::class,
			'entityTypeId' => OwnerType::QUOTE,
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_PRODUCT_ROW_TOOL_SET_CODE,
				'descriptionOverride' => 'Update a product row of a CRM quote.',
			],
		],
		'crm.quote.productRow.get' => [
			'controller' => ProductRow::class,
			'method' => 'get',
			'dtoFqcn' => ProductRowDto::class,
			'entityTypeId' => OwnerType::QUOTE,
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_PRODUCT_ROW_TOOL_SET_CODE,
				'descriptionOverride' => 'Get a product row of a CRM quote by ID.',
			],
		],
		'crm.quote.productRow.delete' => [
			'controller' => ProductRow::class,
			'method' => 'delete',
			'dtoFqcn' => ProductRowDto::class,
			'entityTypeId' => OwnerType::QUOTE,
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_PRODUCT_ROW_TOOL_SET_CODE,
				'descriptionOverride' => 'Delete a product row of a CRM quote.',
			],
		],
		'crm.quote.productRow.list' => [
			'controller' => ProductRow::class,
			'method' => 'list',
			'dtoFqcn' => ProductRowDto::class,
			'entityTypeId' => OwnerType::QUOTE,
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_PRODUCT_ROW_TOOL_SET_CODE,
				'descriptionOverride' => 'List the product rows of a CRM quote.',
			],
		],
		'crm.quote.productRow.replace' => [
			'controller' => ProductRow::class,
			'method' => 'replace',
			'dtoFqcn' => ProductRowDto::class,
			'entityTypeId' => OwnerType::QUOTE,
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_PRODUCT_ROW_TOOL_SET_CODE,
				'descriptionOverride' => 'Replace the product rows of a CRM quote.',
			],
		],
		'crm.quote.productRow.getAvailableForPayment' => [
			'controller' => ProductRow::class,
			'method' => 'getAvailableForPayment',
			'dtoFqcn' => ProductRowDto::class,
			'entityTypeId' => OwnerType::QUOTE,
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_PRODUCT_ROW_TOOL_SET_CODE,
				'descriptionOverride' => 'List product rows available for payment for a CRM quote.',
			],
		],
		// A help method carries two DTO: `dtoFqcn` is the one it answers with and its `select` names, while
		// the query param of the same name is the one it describes. The second is a service parameter of
		// the route on purpose - read from the request object, it would be the client's to choose.
		'crm.lead.productRow.field.list' => [
			'controller' => ProductRowField::class,
			'method' => 'list',
			'dtoFqcn' => CrmFieldMetadataDto::class,
			'entityTypeId' => OwnerType::LEAD,
			'queryParams' => ['dtoFqcn' => ProductRowDto::class],
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_PRODUCT_ROW_TOOL_SET_CODE,
			],
		],
		'crm.lead.productRow.field.get' => [
			'controller' => ProductRowField::class,
			'method' => 'get',
			'dtoFqcn' => CrmFieldMetadataDto::class,
			'entityTypeId' => OwnerType::LEAD,
			'queryParams' => ['dtoFqcn' => ProductRowDto::class],
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_PRODUCT_ROW_TOOL_SET_CODE,
			],
		],
		'crm.deal.productRow.field.list' => [
			'controller' => ProductRowField::class,
			'method' => 'list',
			'dtoFqcn' => CrmFieldMetadataDto::class,
			'entityTypeId' => OwnerType::DEAL,
			'queryParams' => ['dtoFqcn' => ProductRowDto::class],
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_PRODUCT_ROW_TOOL_SET_CODE,
			],
		],
		'crm.deal.productRow.field.get' => [
			'controller' => ProductRowField::class,
			'method' => 'get',
			'dtoFqcn' => CrmFieldMetadataDto::class,
			'entityTypeId' => OwnerType::DEAL,
			'queryParams' => ['dtoFqcn' => ProductRowDto::class],
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_PRODUCT_ROW_TOOL_SET_CODE,
			],
		],
		'crm.quote.productRow.field.list' => [
			'controller' => ProductRowField::class,
			'method' => 'list',
			'dtoFqcn' => CrmFieldMetadataDto::class,
			'entityTypeId' => OwnerType::QUOTE,
			'queryParams' => ['dtoFqcn' => ProductRowDto::class],
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_PRODUCT_ROW_TOOL_SET_CODE,
			],
		],
		'crm.quote.productRow.field.get' => [
			'controller' => ProductRowField::class,
			'method' => 'get',
			'dtoFqcn' => CrmFieldMetadataDto::class,
			'entityTypeId' => OwnerType::QUOTE,
			'queryParams' => ['dtoFqcn' => ProductRowDto::class],
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_PRODUCT_ROW_TOOL_SET_CODE,
			],
		],
		// The cards of the catalog are not addressed through an entity, so the route names no type and
		// carries no service parameter. The scopes are written out because the ones built from the
		// segments of the name would offer `crm.product` and `crm.product.list` as if the catalog were an
		// ordinary resource of the CRM; the method is an administrator's and answers to the scope of the
		// module alone.
		'crm.product.list' => [
			'controller' => Product::class,
			'method' => 'list',
			'dtoFqcn' => ProductDto::class,
			'scopes' => ['crm'],
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_PRODUCT_TOOL_SET_CODE,
			],
		],
		// The requisites of the whole portal, so the route names no owner and carries no service
		// parameter. The scopes are written out for the same reason the catalog ones are: the ones built
		// from the segments of the name would offer `crm.requisite` and `crm.requisite.list`, and an
		// application holding either would look like a caller this administrative method accepts.
		'crm.requisite.list' => [
			'controller' => Requisite::class,
			'method' => 'list',
			'dtoFqcn' => RequisiteDto::class,
			'scopes' => ['crm'],
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_REQUISITE_ADDRESS_TOOL_SET_CODE,
			],
		],
		// The requisites of one contact or of one company, and the presets a client chooses among when it
		// creates one. The type of the owner is the invariant of the route: it is what the controller is
		// given instead of reading it from the request, and it is what the generator builds the contract of
		// the route out of - a requisite of a contact publishes its owner as `contactId`, one of a company
		// as `companyId`.
		//
		// The scopes are written out because the ones built from the segments of the name would offer
		// `crm.contact` and `crm.contact.requisite` as if either were a resource of its own that an
		// application could hold; the requisites of a counterparty answer to the scope of the module alone.
		'crm.contact.requisite.add' => [
			'controller' => OwnerRequisite::class,
			'method' => 'add',
			'dtoGenerator' => OwnerRequisiteDtoGenerator::class,
			'entityTypeId' => OwnerType::CONTACT,
			'categoryId' => self::CONTACT_CATEGORY_ID,
			'scopes' => ['crm'],
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_REQUISITE_ADDRESS_TOOL_SET_CODE,
				'descriptionOverride' => 'Create CRM contact details.',
			],
		],
		'crm.contact.requisite.update' => [
			'controller' => OwnerRequisite::class,
			'method' => 'update',
			'dtoGenerator' => OwnerRequisiteDtoGenerator::class,
			'entityTypeId' => OwnerType::CONTACT,
			'categoryId' => self::CONTACT_CATEGORY_ID,
			'scopes' => ['crm'],
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_REQUISITE_ADDRESS_TOOL_SET_CODE,
				'descriptionOverride' => 'Update CRM contact details.',
			],
		],
		'crm.contact.requisite.get' => [
			'controller' => OwnerRequisite::class,
			'method' => 'get',
			'dtoGenerator' => OwnerRequisiteDtoGenerator::class,
			'entityTypeId' => OwnerType::CONTACT,
			'categoryId' => self::CONTACT_CATEGORY_ID,
			'scopes' => ['crm'],
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_REQUISITE_ADDRESS_TOOL_SET_CODE,
				'descriptionOverride' => 'Get CRM contact details by ID.',
			],
		],
		'crm.contact.requisite.delete' => [
			'controller' => OwnerRequisite::class,
			'method' => 'delete',
			'dtoGenerator' => OwnerRequisiteDtoGenerator::class,
			'entityTypeId' => OwnerType::CONTACT,
			'categoryId' => self::CONTACT_CATEGORY_ID,
			'scopes' => ['crm'],
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_REQUISITE_ADDRESS_TOOL_SET_CODE,
				'descriptionOverride' => 'Delete CRM contact details.',
			],
		],
		'crm.contact.requisite.list' => [
			'controller' => OwnerRequisite::class,
			'method' => 'list',
			'dtoGenerator' => OwnerRequisiteDtoGenerator::class,
			'entityTypeId' => OwnerType::CONTACT,
			'categoryId' => self::CONTACT_CATEGORY_ID,
			'scopes' => ['crm'],
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_REQUISITE_ADDRESS_TOOL_SET_CODE,
				'descriptionOverride' => 'List CRM contact details.',
			],
		],
		'crm.contact.requisite.preset.get' => [
			'controller' => Preset::class,
			'method' => 'get',
			'dtoFqcn' => PresetDto::class,
			'entityTypeId' => OwnerType::CONTACT,
			'scopes' => ['crm'],
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_REQUISITE_ADDRESS_TOOL_SET_CODE,
				'descriptionOverride' => 'Get a preset of CRM contact details by ID.',
			],
		],
		'crm.contact.requisite.preset.list' => [
			'controller' => Preset::class,
			'method' => 'list',
			'dtoFqcn' => PresetDto::class,
			'entityTypeId' => OwnerType::CONTACT,
			'scopes' => ['crm'],
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_REQUISITE_ADDRESS_TOOL_SET_CODE,
				'descriptionOverride' => 'List presets of CRM contact details.',
			],
		],
		'crm.company.requisite.add' => [
			'controller' => OwnerRequisite::class,
			'method' => 'add',
			'dtoGenerator' => OwnerRequisiteDtoGenerator::class,
			'entityTypeId' => OwnerType::COMPANY,
			'categoryId' => self::COMPANY_CATEGORY_ID,
			'scopes' => ['crm'],
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_REQUISITE_ADDRESS_TOOL_SET_CODE,
				'descriptionOverride' => 'Create CRM company details.',
			],
		],
		'crm.company.requisite.update' => [
			'controller' => OwnerRequisite::class,
			'method' => 'update',
			'dtoGenerator' => OwnerRequisiteDtoGenerator::class,
			'entityTypeId' => OwnerType::COMPANY,
			'categoryId' => self::COMPANY_CATEGORY_ID,
			'scopes' => ['crm'],
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_REQUISITE_ADDRESS_TOOL_SET_CODE,
				'descriptionOverride' => 'Update CRM company details.',
			],
		],
		'crm.company.requisite.get' => [
			'controller' => OwnerRequisite::class,
			'method' => 'get',
			'dtoGenerator' => OwnerRequisiteDtoGenerator::class,
			'entityTypeId' => OwnerType::COMPANY,
			'categoryId' => self::COMPANY_CATEGORY_ID,
			'scopes' => ['crm'],
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_REQUISITE_ADDRESS_TOOL_SET_CODE,
				'descriptionOverride' => 'Get CRM company details by ID.',
			],
		],
		'crm.company.requisite.delete' => [
			'controller' => OwnerRequisite::class,
			'method' => 'delete',
			'dtoGenerator' => OwnerRequisiteDtoGenerator::class,
			'entityTypeId' => OwnerType::COMPANY,
			'categoryId' => self::COMPANY_CATEGORY_ID,
			'scopes' => ['crm'],
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_REQUISITE_ADDRESS_TOOL_SET_CODE,
				'descriptionOverride' => 'Delete CRM company details.',
			],
		],
		'crm.company.requisite.list' => [
			'controller' => OwnerRequisite::class,
			'method' => 'list',
			'dtoGenerator' => OwnerRequisiteDtoGenerator::class,
			'entityTypeId' => OwnerType::COMPANY,
			'categoryId' => self::COMPANY_CATEGORY_ID,
			'scopes' => ['crm'],
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_REQUISITE_ADDRESS_TOOL_SET_CODE,
				'descriptionOverride' => 'List CRM company details.',
			],
		],
		'crm.company.requisite.preset.get' => [
			'controller' => Preset::class,
			'method' => 'get',
			'dtoFqcn' => PresetDto::class,
			'entityTypeId' => OwnerType::COMPANY,
			'scopes' => ['crm'],
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_REQUISITE_ADDRESS_TOOL_SET_CODE,
				'descriptionOverride' => 'Get a preset of CRM company details by ID.',
			],
		],
		'crm.company.requisite.preset.list' => [
			'controller' => Preset::class,
			'method' => 'list',
			'dtoFqcn' => PresetDto::class,
			'entityTypeId' => OwnerType::COMPANY,
			'scopes' => ['crm'],
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_REQUISITE_ADDRESS_TOOL_SET_CODE,
				'descriptionOverride' => 'List presets of CRM company details.',
			],
		],
		'crm.contact.requisite.address.add' => [
			'controller' => RequisiteAddress::class,
			'method' => 'add',
			'dtoFqcn' => RequisiteAddressDto::class,
			'entityTypeId' => OwnerType::CONTACT,
			'categoryId' => self::CONTACT_CATEGORY_ID,
			'scopes' => self::ADDRESS_SCOPES,
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_REQUISITE_ADDRESS_TOOL_SET_CODE,
				'descriptionOverride' => 'Create an address in CRM contact details.',
			],
		],
		'crm.contact.requisite.address.get' => [
			'controller' => RequisiteAddress::class,
			'method' => 'get',
			'dtoFqcn' => RequisiteAddressDto::class,
			'entityTypeId' => OwnerType::CONTACT,
			'categoryId' => self::CONTACT_CATEGORY_ID,
			'scopes' => self::ADDRESS_SCOPES,
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_REQUISITE_ADDRESS_TOOL_SET_CODE,
				'descriptionOverride' => 'Get an address in CRM contact details by ID.',
			],
		],
		'crm.contact.requisite.address.list' => [
			'controller' => RequisiteAddress::class,
			'method' => 'list',
			'dtoFqcn' => RequisiteAddressDto::class,
			'entityTypeId' => OwnerType::CONTACT,
			'categoryId' => self::CONTACT_CATEGORY_ID,
			'scopes' => self::ADDRESS_SCOPES,
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_REQUISITE_ADDRESS_TOOL_SET_CODE,
				'descriptionOverride' => 'List addresses in CRM contact details.',
			],
		],
		'crm.contact.requisite.address.update' => [
			'controller' => RequisiteAddress::class,
			'method' => 'update',
			'dtoFqcn' => RequisiteAddressDto::class,
			'entityTypeId' => OwnerType::CONTACT,
			'categoryId' => self::CONTACT_CATEGORY_ID,
			'scopes' => self::ADDRESS_SCOPES,
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_REQUISITE_ADDRESS_TOOL_SET_CODE,
				'descriptionOverride' => 'Update an address in CRM contact details.',
			],
		],
		'crm.contact.requisite.address.delete' => [
			'controller' => RequisiteAddress::class,
			'method' => 'delete',
			'dtoFqcn' => RequisiteAddressDto::class,
			'entityTypeId' => OwnerType::CONTACT,
			'categoryId' => self::CONTACT_CATEGORY_ID,
			'scopes' => self::ADDRESS_SCOPES,
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_REQUISITE_ADDRESS_TOOL_SET_CODE,
				'descriptionOverride' => 'Delete an address in CRM contact details.',
			],
		],
		'crm.company.requisite.address.add' => [
			'controller' => RequisiteAddress::class,
			'method' => 'add',
			'dtoFqcn' => RequisiteAddressDto::class,
			'entityTypeId' => OwnerType::COMPANY,
			'categoryId' => self::COMPANY_CATEGORY_ID,
			'scopes' => self::ADDRESS_SCOPES,
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_REQUISITE_ADDRESS_TOOL_SET_CODE,
				'descriptionOverride' => 'Create an address in CRM company details.',
			],
		],
		'crm.company.requisite.address.get' => [
			'controller' => RequisiteAddress::class,
			'method' => 'get',
			'dtoFqcn' => RequisiteAddressDto::class,
			'entityTypeId' => OwnerType::COMPANY,
			'categoryId' => self::COMPANY_CATEGORY_ID,
			'scopes' => self::ADDRESS_SCOPES,
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_REQUISITE_ADDRESS_TOOL_SET_CODE,
				'descriptionOverride' => 'Get an address in CRM company details by ID.',
			],
		],
		'crm.company.requisite.address.list' => [
			'controller' => RequisiteAddress::class,
			'method' => 'list',
			'dtoFqcn' => RequisiteAddressDto::class,
			'entityTypeId' => OwnerType::COMPANY,
			'categoryId' => self::COMPANY_CATEGORY_ID,
			'scopes' => self::ADDRESS_SCOPES,
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_REQUISITE_ADDRESS_TOOL_SET_CODE,
				'descriptionOverride' => 'List addresses in CRM company details.',
			],
		],
		'crm.company.requisite.address.update' => [
			'controller' => RequisiteAddress::class,
			'method' => 'update',
			'dtoFqcn' => RequisiteAddressDto::class,
			'entityTypeId' => OwnerType::COMPANY,
			'categoryId' => self::COMPANY_CATEGORY_ID,
			'scopes' => self::ADDRESS_SCOPES,
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_REQUISITE_ADDRESS_TOOL_SET_CODE,
				'descriptionOverride' => 'Update an address in CRM company details.',
			],
		],
		'crm.company.requisite.address.delete' => [
			'controller' => RequisiteAddress::class,
			'method' => 'delete',
			'dtoFqcn' => RequisiteAddressDto::class,
			'entityTypeId' => OwnerType::COMPANY,
			'categoryId' => self::COMPANY_CATEGORY_ID,
			'scopes' => self::ADDRESS_SCOPES,
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_REQUISITE_ADDRESS_TOOL_SET_CODE,
				'descriptionOverride' => 'Delete an address in CRM company details.',
			],
		],
		// The group of the client requisite: one controller, the entity type of the method name as its
		// trusted invariant. Only the three types every portal has are written out - `smartDocument` and
		// the smart processes are built in
		// {@see self::addClientRequisiteRoutesOfLinkableTypes()}, because which of them a portal has is
		// up to the portal.
		'crm.deal.clientRequisite.get' => [
			'controller' => ClientRequisite::class,
			'method' => 'get',
			'dtoFqcn' => ClientRequisiteDto::class,
			'entityTypeId' => OwnerType::DEAL,
			'scopes' => self::CLIENT_REQUISITE_SCOPES,
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_REQUISITE_ADDRESS_TOOL_SET_CODE,
				'descriptionOverride' => 'Get the client requisites of a CRM deal by ID.',
			],
		],
		'crm.deal.clientRequisite.set' => [
			'controller' => ClientRequisite::class,
			'method' => 'set',
			'dtoFqcn' => ClientRequisiteDto::class,
			'entityTypeId' => OwnerType::DEAL,
			'scopes' => self::CLIENT_REQUISITE_SCOPES,
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_REQUISITE_ADDRESS_TOOL_SET_CODE,
				'descriptionOverride' => 'Set the client requisites of a CRM deal.',
			],
		],
		'crm.quote.clientRequisite.get' => [
			'controller' => ClientRequisite::class,
			'method' => 'get',
			'dtoFqcn' => ClientRequisiteDto::class,
			'entityTypeId' => OwnerType::QUOTE,
			'scopes' => self::CLIENT_REQUISITE_SCOPES,
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_REQUISITE_ADDRESS_TOOL_SET_CODE,
				'descriptionOverride' => 'Get the client requisites of a CRM quote by ID.',
			],
		],
		'crm.quote.clientRequisite.set' => [
			'controller' => ClientRequisite::class,
			'method' => 'set',
			'dtoFqcn' => ClientRequisiteDto::class,
			'entityTypeId' => OwnerType::QUOTE,
			'scopes' => self::CLIENT_REQUISITE_SCOPES,
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_REQUISITE_ADDRESS_TOOL_SET_CODE,
				'descriptionOverride' => 'Set the client requisites of a CRM quote.',
			],
		],
		'crm.smartInvoice.clientRequisite.get' => [
			'controller' => ClientRequisite::class,
			'method' => 'get',
			'dtoFqcn' => ClientRequisiteDto::class,
			'entityTypeId' => OwnerType::SMART_INVOICE,
			'scopes' => self::CLIENT_REQUISITE_SCOPES,
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_REQUISITE_ADDRESS_TOOL_SET_CODE,
				'descriptionOverride' => 'Get the client requisites of a CRM smart invoice by ID.',
			],
		],
		'crm.smartInvoice.clientRequisite.set' => [
			'controller' => ClientRequisite::class,
			'method' => 'set',
			'dtoFqcn' => ClientRequisiteDto::class,
			'entityTypeId' => OwnerType::SMART_INVOICE,
			'scopes' => self::CLIENT_REQUISITE_SCOPES,
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_REQUISITE_ADDRESS_TOOL_SET_CODE,
				'descriptionOverride' => 'Set the client requisites of a CRM smart invoice.',
			],
		],
		'crm.form.get' => [
			'controller' => Controller\Form\Form::class,
			'method' => 'get',
			'dtoFqcn' => FormDto::class,
			'scopes' => ['crm'],
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_FORM_TOOL_SET_CODE,
			],
		],
		'crm.form.list' => [
			'controller' => Controller\Form\Form::class,
			'method' => 'list',
			'dtoFqcn' => FormDto::class,
			'scopes' => ['crm'],
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_FORM_TOOL_SET_CODE,
			],
		],
		// MCP cannot describe fields handled by FormWriteRequestMapper or fields of nested DTOs.
		'crm.form.add' => [
			'controller' => Controller\Form\Form::class,
			'method' => 'add',
			'dtoFqcn' => FormDto::class,
			'scopes' => ['crm'],
			'mcp' => [
				'export' => false,
				'toolSetCode' => self::MCP_FORM_TOOL_SET_CODE,
			],
		],
		// MCP cannot describe fields handled by FormWriteRequestMapper or fields of nested DTOs.
		'crm.form.update' => [
			'controller' => Controller\Form\Form::class,
			'method' => 'update',
			'dtoFqcn' => FormDto::class,
			'scopes' => ['crm'],
			'mcp' => [
				'export' => false,
				'toolSetCode' => self::MCP_FORM_TOOL_SET_CODE,
			],
		],
		'crm.form.delete' => [
			'controller' => Controller\Form\Form::class,
			'method' => 'delete',
			'dtoFqcn' => FormDto::class,
			'scopes' => ['crm'],
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_FORM_TOOL_SET_CODE,
			],
		],
		// A check writes nothing, yet it reads the document of a write and belongs to the form
		// itself, so it keeps the DTO and the scope the writes of the form have.
		'crm.form.validate' => [
			'controller' => Controller\Form\Form::class,
			'method' => 'validate',
			'dtoFqcn' => FormDto::class,
			'scopes' => ['crm'],
			'mcp' => [
				'export' => false,
				'toolSetCode' => self::MCP_FORM_TOOL_SET_CODE,
			],
		],
		// Both switches change the form and stand on the right to change forms, so they keep the
		// scope of its writes. Neither answers with the document, yet both keep its DTO: the one
		// the controller is declared with, and the one every method of the form is registered with.
		'crm.form.activate' => [
			'controller' => Controller\Form\Form::class,
			'method' => 'activate',
			'dtoFqcn' => FormDto::class,
			'scopes' => ['crm'],
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_FORM_TOOL_SET_CODE,
				'descriptionOverride' => 'Activate a CRM form by ID.',
			],
		],
		'crm.form.deactivate' => [
			'controller' => Controller\Form\Form::class,
			'method' => 'deactivate',
			'dtoFqcn' => FormDto::class,
			'scopes' => ['crm'],
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_FORM_TOOL_SET_CODE,
				'descriptionOverride' => 'Deactivate a CRM form by ID.',
			],
		],
		'crm.form.configuration.get' => [
			'controller' => Form\Configuration::class,
			'method' => 'get',
			'dtoFqcn' => ConfigurationDto::class,
			'scopes' => ['crm'],
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_FORM_TOOL_SET_CODE,
			],
		],
		'crm.form.field.get' => [
			'controller' => Form\Field::class,
			'method' => 'get',
			'dtoFqcn' => CrmFieldMetadataDto::class,
			'scopes' => ['crm'],
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_FORM_TOOL_SET_CODE,
			],
		],
		'crm.form.field.list' => [
			'controller' => Form\Field::class,
			'method' => 'list',
			'dtoFqcn' => CrmFieldMetadataDto::class,
			'scopes' => ['crm'],
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_FORM_TOOL_SET_CODE,
			],
		],
		// The embedding options have no entity of their own, so the identifier of the method is the
		// identifier of the form and the group stays a child of `crm.form`.
		'crm.form.embedding.get' => [
			'controller' => Form\Embedding::class,
			'method' => 'get',
			'dtoFqcn' => EmbeddingDto::class,
			'scopes' => ['crm'],
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_FORM_TOOL_SET_CODE,
			],
		],
		'crm.form.embedding.update' => [
			'controller' => Form\Embedding::class,
			'method' => 'update',
			'dtoFqcn' => EmbeddingDto::class,
			'scopes' => ['crm'],
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_FORM_TOOL_SET_CODE,
				'descriptionOverride' => 'Update embedding settings of a CRM form by ID.',
			],
		],
		// Both lists answer about one form, and the form reaches them as a required filter rather
		// than as a segment of the name: the widget and the chat are entities of their own and keep
		// identifiers of their own in the answer.
		'crm.form.sitebutton.list' => [
			'controller' => Form\SiteButton::class,
			'method' => 'list',
			'dtoFqcn' => SiteButtonDto::class,
			'scopes' => ['crm'],
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_FORM_TOOL_SET_CODE,
			],
		],
		'crm.form.openline.list' => [
			'controller' => Form\OpenLine::class,
			'method' => 'list',
			'dtoFqcn' => OpenLineDto::class,
			'scopes' => ['crm'],
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_FORM_TOOL_SET_CODE,
			],
		],
		// The three dictionaries an external editor of forms is built on. Scenarios and their
		// groups are answered whole, while fields are answered only for the required limited set of
		// entities the caller names. None of them takes a page.
		'crm.form.scenario.list' => [
			'controller' => Form\Scenario::class,
			'method' => 'list',
			'dtoFqcn' => ScenarioDto::class,
			'scopes' => ['crm'],
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_FORM_TOOL_SET_CODE,
			],
		],
		'crm.form.scenariocategory.list' => [
			'controller' => Form\ScenarioCategory::class,
			'method' => 'list',
			'dtoFqcn' => ScenarioCategoryDto::class,
			'scopes' => ['crm'],
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_FORM_TOOL_SET_CODE,
			],
		],
		'crm.form.entityfield.list' => [
			'controller' => Form\EntityField::class,
			'method' => 'list',
			'dtoFqcn' => EntityFieldDto::class,
			'scopes' => ['crm'],
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_FORM_TOOL_SET_CODE,
			],
		],
		// The traces of the end-to-end analytics are read and written through the CRM items they are bound
		// to, so the group takes the scope of CRM. MCP describes the nested utm, pages and channels of the
		// input as bare objects: their format is written out in the descriptions of the fields of TraceDto.
		'crm.tracking.trace.get' => [
			'controller' => Tracking\Trace::class,
			'method' => 'get',
			'dtoFqcn' => TraceDto::class,
			'scopes' => ['crm'],
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_TRACKING_TOOL_SET_CODE,
				'descriptionOverride' => 'Get a tracking trace of the end-to-end analytics by ID: its source,'
					. ' creation time, device, channels, visited pages and UTM tags.',
			],
		],
		'crm.tracking.trace.list' => [
			'controller' => Tracking\Trace::class,
			'method' => 'list',
			'dtoFqcn' => TraceDto::class,
			'scopes' => ['crm'],
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_TRACKING_TOOL_SET_CODE,
				'descriptionOverride' => 'List the tracking traces of one CRM item, the current attribution first.'
					. ' The filter must be exactly [["entityTypeId", "=", <type>], ["entityId", "=", <id>]].',
			],
		],
		'crm.tracking.trace.add' => [
			'controller' => Tracking\Trace::class,
			'method' => 'add',
			'dtoFqcn' => TraceDto::class,
			'scopes' => ['crm'],
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_TRACKING_TOOL_SET_CODE,
				'descriptionOverride' => 'Create a tracking trace and bind it to CRM items. fields.sourceId is'
					. ' required, and at least one of leadsId, dealsId, contactsId, companiesId, quotesId.'
					. ' Optional: isMobile; utm as {"source", "medium", "campaign", "content", "term"};'
					. ' pages as [{"url", "title", "time"}]; channels as [{"code", "value"}].',
			],
		],
		'crm.tracking.trace.delete' => [
			'controller' => Tracking\Trace::class,
			'method' => 'delete',
			'dtoFqcn' => TraceDto::class,
			'scopes' => ['crm'],
			'mcp' => [
				'export' => true,
				'toolSetCode' => self::MCP_TRACKING_TOOL_SET_CODE,
				'descriptionOverride' => 'Delete a tracking trace by ID from the attribution of every CRM item'
					. ' it is bound to.',
			],
		],
	];

	/** @var array<string, array>|null */
	private ?array $availableRoutesConfig = null;

	/** @var array<string, array>|null */
	private ?array $builtRoutesConfig = null;

	/** @var array<string, class-string<Feature\BaseFeature|Feature\AbstractCrmFlag>>|null */
	private ?array $builtFeatureByRoute = null;

	public function getControllersData(): array
	{
		$availableRoutesConfig = $this->getAvailableRoutesConfig();
		$controllersData = [
			...$this->buildControllersDataFromRoutesConfig($availableRoutesConfig),
			...$this->buildDisabledControllersDataFromRoutesConfig(
				$availableRoutesConfig,
				$this->builtRoutesConfig ?? [],
			),
			$this->buildActivityControllerData(),
		];

		if (!Feature::enabled(RestV3CrudDeal::class))
		{
			$controllersData[] = $this->buildHiddenControllerData(
				Controller\Lead::class,
				self::LEAD_CONTROLLER_METHODS,
			);
			$controllersData[] = $this->buildHiddenControllerData(
				Controller\MyCompany\Basic::class,
				self::MY_COMPANY_BASIC_CONTROLLER_METHODS,
			);
		}

		$this->assertUniqueActionUris($controllersData);

		return $controllersData;
	}

	public function getDataForDtoGeneration(): array
	{
		$availableRoutesConfig = $this->getAvailableRoutesConfig();
		$generatedDtosByFqcn = [];
		$sourceRouteByGeneratedDtoFqcn = [];
		foreach ($availableRoutesConfig as $actionUri => $routeConfig)
		{
			$generatedDto = $this->generateDtoFromRouteConfig($routeConfig);
			if ($generatedDto instanceof GeneratedDto)
			{
				$this->registerGeneratedDtoForRoute(
					$generatedDto,
					$actionUri,
					$generatedDtosByFqcn,
					$sourceRouteByGeneratedDtoFqcn,
				);
			}
		}

		$generatedDtos = [];
		foreach ($availableRoutesConfig as $routeConfig)
		{
			$generatedDto = $this->generateDtoFromRouteConfig($routeConfig);
			if (!$generatedDto instanceof GeneratedDto)
			{
				continue;
			}

			$this->addGeneratedDtoWithDependencies($generatedDto, $generatedDtosByFqcn, $generatedDtos);
		}

		return array_values($generatedDtos);
	}

	/**
	 * @param array<string, GeneratedDto> $generatedDtosByFqcn
	 * @param array<string, string> $sourceRouteByGeneratedDtoFqcn
	 */
	private function registerGeneratedDtoForRoute(
		GeneratedDto $generatedDto,
		string $actionUri,
		array &$generatedDtosByFqcn,
		array &$sourceRouteByGeneratedDtoFqcn,
	): void
	{
		$fqcn = $generatedDto->getFQCN();
		$registeredDto = $generatedDtosByFqcn[$fqcn] ?? null;
		if ($registeredDto !== null && $registeredDto != $generatedDto)
		{
			throw new \InvalidArgumentException(sprintf(
				'Generated DTO "%s" has different descriptors for routes "%s" and "%s".',
				$fqcn,
				$sourceRouteByGeneratedDtoFqcn[$fqcn],
				$actionUri,
			));
		}

		$generatedDtosByFqcn[$fqcn] = $generatedDto;
		$sourceRouteByGeneratedDtoFqcn[$fqcn] = $sourceRouteByGeneratedDtoFqcn[$fqcn] ?? $actionUri;
	}

	/**
	 * @param array<string, GeneratedDto> $generatedDtosByFqcn
	 * @param array<string, GeneratedDto> $generatedDtos
	 */
	private function addGeneratedDtoWithDependencies(
		GeneratedDto $generatedDto,
		array &$generatedDtosByFqcn,
		array &$generatedDtos,
	): void
	{
		$fqcn = $generatedDto->getFQCN();
		if (isset($generatedDtos[$fqcn]))
		{
			return;
		}

		$generatedDtos[$fqcn] = $generatedDto;
		foreach ($generatedDto->fields as $field)
		{
			if (!$field instanceof DtoField)
			{
				continue;
			}

			foreach ([$field->getPropertyType(), $field->getElementType()] as $dependencyFqcn)
			{
				if ($dependencyFqcn === null)
				{
					continue;
				}

				$dependency = $generatedDtosByFqcn[$dependencyFqcn]
					?? $this->generateItemDtoDependency($dependencyFqcn);
				if (!$dependency instanceof GeneratedDto)
				{
					continue;
				}

				$generatedDtosByFqcn[$dependencyFqcn] = $dependency;
				$this->addGeneratedDtoWithDependencies($dependency, $generatedDtosByFqcn, $generatedDtos);
			}
		}
	}

	private function generateItemDtoDependency(string $dtoFqcn): ?GeneratedDto
	{
		$generator = new ItemDtoGenerator();
		$entityType = $generator->getEntityTypeByGeneratedDtoClass($dtoFqcn);
		if ($entityType === null)
		{
			return null;
		}

		return $generator->generate($entityType->getId(), $generator->isMyCompanyGeneratedDtoClass($dtoFqcn));
	}

	protected function getAvailableRoutesConfig(): array
	{
		if ($this->availableRoutesConfig !== null)
		{
			return $this->availableRoutesConfig;
		}

		[$routesConfig, $featureByRoute] = $this->buildRoutesConfig();
		$this->builtRoutesConfig = $routesConfig;
		$this->builtFeatureByRoute = $featureByRoute;

		return $this->availableRoutesConfig = array_filter(
			$routesConfig,
			fn(string $actionUri): bool => $this->isRouteAvailable($actionUri, $featureByRoute),
			ARRAY_FILTER_USE_KEY,
		);
	}

	/**
	 * @return array{array<string, array>, array<string, class-string<Feature\BaseFeature|Feature\AbstractCrmFlag>>}
	 */
	protected function buildRoutesConfig(): array
	{
		$routesConfig = $this->routesConfig;
		$featureByRoute = $this->featureByRoute;
		$routesConfig = $this->addCustomEnumRoutes($routesConfig, $featureByRoute);

		foreach (self::CUSTOM_FIELD_STATIC_ENTITY_SEGMENTS as $entityTypeId => $entitySegment)
		{
			$this->addCustomFieldRoutes(
				$routesConfig,
				$featureByRoute,
				$entityTypeId,
				$entitySegment,
			);
		}

		$layoutRouteBuilder = new DetailPageLayout\RouteBuilder();
		$layoutRoutes = $layoutRouteBuilder->buildRoutes();
		$routesConfig = array_merge($routesConfig, $layoutRoutes);
		foreach (array_keys($layoutRoutes) as $actionUri)
		{
			$featureByRoute[$actionUri] = DetailPageLayout\RouteBuilder::FEATURE;
		}

		if ($this->isCategoryFamilyEnabled())
		{
			$categoryRoutes = (new RouteBuilder())->buildRoutes();
			$routesConfig = array_merge($routesConfig, $categoryRoutes);
			foreach (array_keys($categoryRoutes) as $actionUri)
			{
				$featureByRoute[$actionUri] = RouteBuilder::FEATURE;
			}
		}

		$activityRoutes = $this->createActivityRouteBuilder()->buildRoutes();
		$routesConfig = array_merge($routesConfig, $activityRoutes);
		foreach (array_keys($activityRoutes) as $actionUri)
		{
			$featureByRoute[$actionUri] = Activity\RouteBuilder::FEATURE;
		}

		foreach ($this->getDynamicTypes() as $dynamicType)
		{
			$entityTypeId = $dynamicType->getEntityTypeId();
			if ($entityTypeId === null || !EntityType::isValid($entityTypeId))
			{
				continue;
			}

			if (EntityType::isPossibleSmartProcessTypeId($entityTypeId))
			{
				$actionUri = 'crm.smartProcess' . $entityTypeId . '.field.required.get';
				$routesConfig[$actionUri] = [
					'controller' => Requirement::class,
					'method' => 'get',
					'dtoFqcn' => FieldRequirementDto::class,
					'entityTypeId' => $entityTypeId,
					'scopes' => ['crm'],
					'mcp' => [
						'export' => true,
						'toolSetCode' => self::MCP_FIELD_REQUIREMENT_TOOL_SET_CODE,
						'descriptionOverride' => 'Get field requirement settings for smart process ' . $entityTypeId . '.',
					],
				];
				$featureByRoute[$actionUri] = RestV3CrudDeal::class;
			}

			if (OwnerType::isPossibleSmartProcessTypeId($entityTypeId))
			{
				$dynamicLayoutRoutes = $layoutRouteBuilder->buildRoutesForEntity(
					'smartProcess' . $entityTypeId,
					$entityTypeId,
				);
				foreach ($dynamicLayoutRoutes as $actionUri => $config)
				{
					$routesConfig[$actionUri] = $config;
					$featureByRoute[$actionUri] = DetailPageLayout\RouteBuilder::FEATURE;
				}
			}

			foreach (['add', 'update', 'delete', 'get', 'list', 'aggregate'] as $method)
			{
				$actionUri = 'crm.smartProcess' . $entityTypeId . '.' . $method;
				$routeConfig = [
					'controller' => Item::class,
					'method' => $method,
					'entityTypeId' => $entityTypeId,
					'mcp' => $this->buildSmartProcessMcpMetadata($entityTypeId, $method),
				];
				if ($method === 'delete')
				{
					$routeConfig['dtoFqcn'] = DeleteItemDto::class;
				}
				else
				{
					$routeConfig['dtoGenerator'] = ItemDtoGenerator::class;
				}

				$routesConfig[$actionUri] = $routeConfig;
				$featureByRoute[$actionUri] = RestV3CrudDeal::class;
			}

			foreach (['list', 'get'] as $method)
			{
				$actionUri = 'crm.smartProcess' . $entityTypeId . '.field.' . $method;
				$routesConfig[$actionUri] = [
					'controller' => Field::class,
					'method' => $method,
					'dtoFqcn' => CrmFieldMetadataDto::class,
					'entityTypeId' => $entityTypeId,
					'mcp' => [
						'export' => true,
						'toolSetCode' => self::MCP_SMART_PROCESS_TOOL_SET_CODE,
					],
				];
				$featureByRoute[$actionUri] = RestV3CrudDeal::class;
			}

			$this->addCustomFieldRoutes(
				$routesConfig,
				$featureByRoute,
				$entityTypeId,
				'smartProcess' . $entityTypeId,
			);

			$routesConfig = $this->addCustomEnumRoutesForEntity(
				$routesConfig,
				$featureByRoute,
				'crm.smartProcess' . $entityTypeId,
				$entityTypeId,
			);

			$actionUri = 'crm.smartProcess' . $entityTypeId . '.addFieldValue';
			$routesConfig[$actionUri] = [
				'controller' => Item::class,
				'method' => 'addFieldValue',
				'dtoGenerator' => ItemDtoGenerator::class,
				'entityTypeId' => $entityTypeId,
				'mcp' => $this->buildSmartProcessMcpMetadata($entityTypeId, 'addFieldValue'),
			];
			$featureByRoute[$actionUri] = RestV3CrudDeal::class;

			$actionUri = 'crm.smartProcess' . $entityTypeId . '.deleteFieldValue';
			$routesConfig[$actionUri] = [
				'controller' => Item::class,
				'method' => 'deleteFieldValue',
				'dtoGenerator' => ItemDtoGenerator::class,
				'entityTypeId' => $entityTypeId,
				'mcp' => $this->buildSmartProcessMcpMetadata($entityTypeId, 'deleteFieldValue'),
			];
			$featureByRoute[$actionUri] = RestV3CrudDeal::class;
		}

		$routesConfig = $this->addProductRowRoutesOfProductEnabledTypes($routesConfig, $featureByRoute);
		$routesConfig = $this->addClientRequisiteRoutesOfLinkableTypes($routesConfig, $featureByRoute);
		$routesConfig = $this->addDictionaryValueRoutes($routesConfig, $featureByRoute);

		foreach ($routesConfig as $actionUri => $route)
		{
			if (($route['controller'] ?? null) !== Requirement::class || ($route['method'] ?? null) !== 'get')
			{
				continue;
			}
			foreach (['set', 'clear'] as $action)
			{
				$writeUri = substr($actionUri, 0, -3) . $action;
				if (isset($routesConfig[$writeUri]))
				{
					throw new \RuntimeException(
						'CRM REST V3 field requirement route is already registered: ' . $writeUri,
					);
				}
				$routesConfig[$writeUri] = array_replace($route, ['method' => $action]);
				$description = substr($route['mcp']['descriptionOverride'], strlen('Get '));
				$routesConfig[$writeUri]['mcp']['descriptionOverride'] = ucfirst($action) . ' ' . $description;
				$featureByRoute[$writeUri] = RestV3CrudDeal::class;
			}
		}

		return $this->normalizeRouteMaps($routesConfig, $featureByRoute);
	}

	/**
	 * @param array<string, array> $routesConfig
	 * @param array<string, class-string<Feature\BaseFeature|Feature\AbstractCrmFlag>> $featureByRoute
	 */
	private function addCustomFieldRoutes(
		array &$routesConfig,
		array &$featureByRoute,
		int $entityTypeId,
		string $entitySegment,
	): void
	{
		$entityId = \CCrmOwnerType::ResolveUserFieldEntityID($entityTypeId);
		foreach (self::CUSTOM_FIELD_ACTIONS as $action)
		{
			$actionUri = 'crm.' . $entitySegment . '.field.custom.' . $action;
			$routesConfig[$actionUri] = [
				'controller' => Custom::class,
				'method' => $action,
				'dtoFqcn' => CustomFieldConfigDto::class,
				'entityTypeId' => $entityTypeId,
				'queryParams' => ['entityId' => $entityId],
				'mcp' => $this->buildCustomFieldMcpMetadata($entityTypeId, $action, false),
			];
			$featureByRoute[$actionUri] = RestV3CrudDeal::class;
		}
	}

	/**
	 * Routes for enum values of CRM user fields. The entity type is a trusted route parameter.
	 *
	 * @param array<string, array> $routesConfig
	 * @param array<string, class-string<Feature\BaseFeature|Feature\AbstractCrmFlag>> $featureByRoute
	 * @return array<string, array>
	 */
	private function addCustomEnumRoutes(array $routesConfig, array &$featureByRoute): array
	{
		$entities = [
			OwnerType::DEAL => 'deal',
			OwnerType::CONTACT => 'contact',
			OwnerType::LEAD => 'lead',
			OwnerType::COMPANY => 'company',
			OwnerType::QUOTE => 'quote',
			OwnerType::SMART_INVOICE => 'smartInvoice',
		];

		foreach ($entities as $entityTypeId => $entitySegment)
		{
			$routesConfig = $this->addCustomEnumRoutesForEntity(
				$routesConfig,
				$featureByRoute,
				'crm.' . $entitySegment,
				$entityTypeId,
			);
		}

		return $routesConfig;
	}

	/**
	 * @param array<string, array> $routesConfig
	 * @param array<string, class-string<Feature\BaseFeature|Feature\AbstractCrmFlag>> $featureByRoute
	 * @return array<string, array>
	 */
	private function addCustomEnumRoutesForEntity(
		array $routesConfig,
		array &$featureByRoute,
		string $entityPrefix,
		int $entityTypeId,
	): array
	{
		foreach (['add', 'update', 'get', 'list', 'delete'] as $action)
		{
			$actionUri = $entityPrefix . '.field.custom.enum.' . $action;
			$routesConfig[$actionUri] = [
				'controller' => CustomEnum::class,
				'method' => $action,
				'dtoFqcn' => EnumDto::class,
				'entityTypeId' => $entityTypeId,
				'mcp' => $this->buildCustomFieldMcpMetadata($entityTypeId, $action, true),
			];
			$featureByRoute[$actionUri] = RestV3CrudDeal::class;
		}

		return $routesConfig;
	}

	/** @return array<string, mixed> */
	private function buildCustomFieldMcpMetadata(int $entityTypeId, string $action, bool $isEnum): array
	{
		$owner = self::CUSTOM_FIELD_OWNER_NAMES[$entityTypeId] ?? 'CRM smart process type ' . $entityTypeId;
		$resource = $isEnum ? 'enumeration value of a custom field' : 'custom field';
		$resources = $isEnum ? 'enumeration values of custom fields' : 'custom fields';
		$article = $isEnum ? 'an' : 'a';

		return [
			'export' => true,
			'toolSetCode' => self::CUSTOM_FIELD_TOOL_SET_CODES[$entityTypeId]
				?? self::MCP_SMART_PROCESS_TOOL_SET_CODE,
			'descriptionOverride' => match ($action)
			{
				'list' => 'List ' . $resources . ' for ' . $owner . '.',
				'get' => 'Get ' . $article . ' ' . $resource . ' for ' . $owner . ' by ID.',
				'add' => 'Create ' . $article . ' ' . $resource . ' for ' . $owner . '.',
				'update' => 'Update ' . $article . ' ' . $resource . ' for ' . $owner . '.',
				'delete' => 'Delete ' . $article . ' ' . $resource . ' for ' . $owner . '.',
			},
		];
	}

	/** @return \Bitrix\Crm\Model\Dynamic\Type[] */
	protected function getDynamicTypes(): array
	{
		return Container::getInstance()->getDynamicTypesMap()->load([
			'isLoadStages' => false,
			'isLoadCategories' => false,
		])->getTypes();
	}

	protected function isCategoryFamilyEnabled(): bool
	{
		return Feature::enabled(RouteBuilder::FEATURE);
	}

	protected function createActivityRouteBuilder(): Activity\RouteBuilder
	{
		return new Activity\RouteBuilder();
	}

	/**
	 * @return array<string, mixed>
	 */
	private function buildSmartProcessMcpMetadata(int $entityTypeId, string $method): array
	{
		$item = 'CRM smart process item of type ' . $entityTypeId;
		$items = 'CRM smart process items of type ' . $entityTypeId;

		return [
			'export' => true,
			'toolSetCode' => self::MCP_SMART_PROCESS_TOOL_SET_CODE,
			'descriptionOverride' => match ($method)
			{
				'add' => 'Create a ' . $item . '.',
				'update' => 'Update a ' . $item . '.',
				'delete' => 'Delete a ' . $item . '.',
				'get' => 'Get a ' . $item . ' by ID.',
				'list' => 'List ' . $items . '.',
				'aggregate' => 'Calculate aggregates for ' . $items . '.',
				'addFieldValue' => 'Add a value to a multiple-value field of a ' . $item . '.',
				'deleteFieldValue' => 'Remove a value from a multiple-value field of a ' . $item . '.',
			},
		];
	}

	/**
	 * The values of the seven basic dictionaries: `crm.dictionary.<dictionary>.{get|list|add|update|delete}`,
	 * thirty-five methods on one controller and one DTO. Which dictionary a method answers about is a
	 * service parameter of its route - the client has no say in it - and a segment of mixed case like
	 * `dealType` is out of reach of the scanning of controllers anyway.
	 *
	 * Built rather than written out because the routes and their gate have to agree name for name: a name
	 * missing from {@see $featureByRoute} would stay open on every portal, since {@see isRouteAvailable()}
	 * gates a route only when a feature is mapped for it, and thirty-five pairs kept by hand is where that
	 * happens. The names themselves stay literal all the same - {@see DICTIONARY_TYPE_BY_SEGMENT} spells
	 * them - so nothing of the domain can rename a published method.
	 *
	 * Nothing here reads the portal: the seven dictionaries are a closed set of the domain, so these routes
	 * are as static as the ones declared above. That matters because the schema is built before
	 * authorization with no exception handling around it, where a failed read would break the lookup of a
	 * method for every module.
	 *
	 * The scopes are written out for the reason the catalog and the requisites of the portal write them:
	 * the ones built from the segments of the name would offer `crm.dictionary`,
	 * `crm.dictionary.<dictionary>` and `crm.dictionary.<dictionary>.<action>` as scopes, and a dictionary
	 * is no resource of its own - which dictionary a method is about is a service parameter of its route,
	 * and the right it follows is the right on the CRM, which the module has one scope for. All thirty-five
	 * declare it and not the twenty-one that write: the group is answered by one scope the way the address
	 * family and `crm.form.*` are, both of which write it on their reading methods too, and a group where
	 * some methods declare and some do not would read as if the difference meant something.
	 */
	protected function addDictionaryValueRoutes(array $routesConfig, array &$featureByRoute): array
	{
		foreach (static::DICTIONARY_TYPE_BY_SEGMENT as $segment => $dictionaryType)
		{
			foreach (static::DICTIONARY_VALUE_ACTIONS as $action)
			{
				$actionUri = 'crm.dictionary.' . $segment . '.' . $action;
				$routesConfig[$actionUri] = [
					'controller' => Dictionary\DictionaryValue::class,
					'method' => $action,
					'dtoFqcn' => DictionaryValueDto::class,
					'queryParams' => ['dictionaryTypeId' => $dictionaryType->value],
					'scopes' => ['crm'],
					'mcp' => $this->buildDictionaryValueMcpMetadata($segment, $action),
				];
				$featureByRoute[$actionUri] = RestV3CrudDeal::class;
			}
		}

		return $routesConfig;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function buildDictionaryValueMcpMetadata(string $segment, string $action): array
	{
		$dictionary = match ($segment)
		{
			'dealType' => 'deal type',
			'contactType' => 'contact type',
			'honorific' => 'honorific',
			'companyType' => 'company type',
			'employees' => 'employee count range',
			'industry' => 'industry',
			'source' => 'CRM source',
		};

		return [
			'export' => true,
			'toolSetCode' => self::MCP_DICTIONARY_TOOL_SET_CODE,
			'descriptionOverride' => match ($action)
			{
				'add' => 'Create an entry in the ' . $dictionary . ' dictionary.',
				'update' => 'Update an entry in the ' . $dictionary . ' dictionary.',
				'delete' => 'Delete an entry from the ' . $dictionary . ' dictionary.',
				'get' => 'Get an entry of the ' . $dictionary . ' dictionary by ID.',
				'list' => 'List entries in the ' . $dictionary . ' dictionary.',
			},
		];
	}

	/**
	 * Routes of `crm.{entity}.productRow.*` for the smart-process-based types products are enabled for.
	 * Whether such a type works with products is a state of the portal, not a constant of the product, so
	 * routes declared literally would drift away from it; lead, deal and quote are literals for that
	 * very reason - their answer never changes.
	 *
	 * This provider is built without dependency injection, is called with no exception handling around it
	 * and is reachable before authorization, so a failed read of the types would break the lookup of a
	 * method for *every* module. It degrades to the routes declared literally instead.
	 *
	 * @param array<string, array> $routesConfig
	 * @return array<string, array>
	 */
	protected function addProductRowRoutesOfProductEnabledTypes(array $routesConfig, array &$featureByRoute): array
	{
		try
		{
			$entityTypeIds = $this->getProductEnabledTypeRepository()->getEntityTypeIds();
		}
		catch (\Throwable $exception)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($exception);

			return $routesConfig;
		}

		foreach ($entityTypeIds as $entityTypeId)
		{
			$entitySegment = $this->getProductRowEntitySegment($entityTypeId);
			if ($entitySegment === null)
			{
				continue;
			}

			foreach (static::PRODUCT_ROW_ACTIONS as $action)
			{
				$actionUri = 'crm.' . $entitySegment . '.productRow.' . $action;
				if (isset($routesConfig[$actionUri]))
				{
					continue;
				}

				$routesConfig[$actionUri] = [
					'controller' => ProductRow::class,
					'method' => $action,
					'dtoFqcn' => ProductRowDto::class,
					'entityTypeId' => $entityTypeId,
					'mcp' => $this->buildProductRowMcpMetadata($entityTypeId, $action),
				];
				$featureByRoute[$actionUri] = RestV3CrudDeal::class;
			}

			$routesConfig = $this->addProductRowFieldRoutesOfType(
				$routesConfig,
				$featureByRoute,
				$entitySegment,
				$entityTypeId,
			);
		}

		return $routesConfig;
	}

	/**
	 * Routes of `crm.{entity}.productRow.field.*` of one type, next to the branch that gives the type its
	 * actions and under the same answer of the portal: a type whose actions are published gets the help on
	 * them, and hiding one without the other would publish a method describing an absent contract.
	 *
	 * The controller is another one - a help method is named after the group and served by a class of its
	 * own - and the DTO under description is a service parameter of the route, as it is in the routes
	 * written out literally.
	 *
	 * @param array<string, array> $routesConfig
	 * @return array<string, array>
	 */
	protected function addProductRowFieldRoutesOfType(
		array $routesConfig,
		array &$featureByRoute,
		string $entitySegment,
		int $entityTypeId,
	): array
	{
		foreach (static::PRODUCT_ROW_FIELD_ACTIONS as $action)
		{
			$actionUri = 'crm.' . $entitySegment . '.productRow.field.' . $action;
			if (isset($routesConfig[$actionUri]))
			{
				continue;
			}

			$routesConfig[$actionUri] = [
				'controller' => ProductRowField::class,
				'method' => $action,
				'dtoFqcn' => CrmFieldMetadataDto::class,
				'entityTypeId' => $entityTypeId,
				'queryParams' => ['dtoFqcn' => ProductRowDto::class],
				'mcp' => [
					'export' => true,
					'toolSetCode' => self::MCP_PRODUCT_ROW_TOOL_SET_CODE,
				],
			];
			$featureByRoute[$actionUri] = RestV3CrudDeal::class;
		}

		return $routesConfig;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function buildProductRowMcpMetadata(int $entityTypeId, string $action): array
	{
		$smartProcessItem = 'a CRM smart process item of type ' . $entityTypeId;
		$owner = match (true)
		{
			$entityTypeId === OwnerType::SMART_INVOICE => 'a CRM smart invoice',
			$entityTypeId === OwnerType::SMART_DOCUMENT => 'a CRM smart document',
			OwnerType::isPossibleSmartProcessTypeId($entityTypeId) => $smartProcessItem,
		};

		return [
			'export' => true,
			'toolSetCode' => self::MCP_PRODUCT_ROW_TOOL_SET_CODE,
			'descriptionOverride' => match ($action)
			{
				'add' => 'Create a product row of ' . $owner . '.',
				'update' => 'Update a product row of ' . $owner . '.',
				'get' => 'Get a product row of ' . $owner . ' by ID.',
				'delete' => 'Delete a product row of ' . $owner . '.',
				'list' => 'List the product rows of ' . $owner . '.',
				'replace' => 'Replace the product rows of ' . $owner . '.',
				'getAvailableForPayment' => 'List product rows available for payment for ' . $owner . '.',
			},
		];
	}

	/**
	 * Routes of `crm.{entity}.clientRequisite.*` for the types the link serves that are not written out
	 * literally: the smart document, which has no literal route at all, and the smart processes, whose
	 * composition is a state of the portal.
	 *
	 * No protection of its own against a failing read of the map of the dynamic types: the list of the
	 * types below reads the very map the loop above it in {@see self::buildRoutesConfig()} has already
	 * walked, so a map that cannot be read throws before this is reached. The product rows guard theirs
	 * because they read another source, which that loop does not touch.
	 *
	 * @param array<string, array> $routesConfig
	 * @return array<string, array>
	 */
	protected function addClientRequisiteRoutesOfLinkableTypes(array $routesConfig, array &$featureByRoute): array
	{
		foreach (EntityLinkService::getInstance()->getAvailableEntityTypeIds() as $entityTypeId)
		{
			if (!EntityType::isValid($entityTypeId))
			{
				continue;
			}

			$entitySegment = $this->getClientRequisiteEntitySegment($entityTypeId);
			if ($entitySegment === null)
			{
				continue;
			}

			foreach (static::CLIENT_REQUISITE_ACTIONS as $action)
			{
				$actionUri = 'crm.' . $entitySegment . '.clientRequisite.' . $action;
				$routesConfig[$actionUri] = [
					'controller' => ClientRequisite::class,
					'method' => $action,
					'dtoFqcn' => ClientRequisiteDto::class,
					'entityTypeId' => $entityTypeId,
					'scopes' => static::CLIENT_REQUISITE_SCOPES,
					'mcp' => [
						'export' => true,
						'toolSetCode' => self::MCP_REQUISITE_ADDRESS_TOOL_SET_CODE,
						'descriptionOverride' => sprintf(
							$action === 'set'
								? 'Set the client requisites of a CRM %s.'
								: 'Get the client requisites of a CRM %s by ID.',
							$entitySegment === 'smartDocument'
								? 'smart document'
								: 'smart process item of type ' . $entityTypeId,
						),
					],
				];
				$featureByRoute[$actionUri] = RestV3CrudDeal::class;
			}
		}

		return $routesConfig;
	}

	/**
	 * The segment a type is named by in a method of the group, or null for a type the group builds no
	 * routes for here.
	 *
	 * Two branches of {@see self::getProductRowEntitySegment()} and no more: deal, quote and the smart
	 * invoice are written out literally, and a branch of theirs here would build their routes a second
	 * time. The B2E document needs no branch of its own to be left out - it lies outside both ranges of
	 * the smart processes and falls to the default, which is what keeps it from being published under a
	 * segment REST v3 does not have.
	 */
	protected function getClientRequisiteEntitySegment(int $entityTypeId): ?string
	{
		return match (true)
		{
			$entityTypeId === OwnerType::SMART_DOCUMENT => 'smartDocument',
			OwnerType::isPossibleSmartProcessTypeId($entityTypeId) => 'smartProcess' . $entityTypeId,
			default => null,
		};
	}

	/**
	 * The segment a type is named by in a method of the group, or null for a type the group publishes no
	 * routes for.
	 */
	protected function getProductRowEntitySegment(int $entityTypeId): ?string
	{
		return match (true)
		{
			$entityTypeId === OwnerType::SMART_INVOICE => 'smartInvoice',
			$entityTypeId === OwnerType::SMART_DOCUMENT => 'smartDocument',
			OwnerType::isPossibleSmartProcessTypeId($entityTypeId) => 'smartProcess' . $entityTypeId,
			default => null,
		};
	}

	/**
	 * Resolved through the locator so that a failing read can be substituted in a test: the degradation
	 * above is a requirement of the design and needs a way to be exercised.
	 */
	protected function getProductEnabledTypeRepository(): ProductEnabledTypeRepository
	{
		$serviceLocator = ServiceLocator::getInstance();

		return $serviceLocator->has(ProductEnabledTypeRepository::class)
			? $serviceLocator->get(ProductEnabledTypeRepository::class)
			: new ProductEnabledTypeRepository()
		;
	}

	protected function isRouteAvailable(string $actionUri, array $featureByRoute): bool
	{
		$featureClass = $featureByRoute[$actionUri] ?? null;
		if ($featureClass !== null && !Feature::enabled($featureClass))
		{
			return false;
		}

		return true;
	}

	protected function buildControllersDataFromRoutesConfig(array $routesConfig): array
	{
		$methodDescriptionsControllerMap = [];
		foreach ($routesConfig as $actionUri => $routeConfig)
		{
			if (!isset($routeConfig['controller'], $routeConfig['method']))
			{
				continue;
			}

			$controllerFqcn = $routeConfig['controller'];
			$generatedDto = $this->generateDtoFromRouteConfig($routeConfig);
			if ($generatedDto instanceof GeneratedDto)
			{
				$dtoFqcn = $generatedDto->getFQCN();
			}
			else
			{
				$dtoFqcn = $routeConfig['dtoFqcn'] ?? null;
			}
			if ($dtoFqcn !== null)
			{
				$this->registerDto($dtoFqcn);
			}

			$methodDescriptionsControllerMap[$controllerFqcn][] = new MethodDescription(
				module: self::MODULE,
				controllerFqcn: $controllerFqcn,
				method: $routeConfig['method'],
				dtoFqcn: $dtoFqcn,
				scopes: $routeConfig['scopes'] ?? $this->buildScopes($actionUri),
				actionUri: $actionUri,
				queryParams: $this->buildQueryParamsFromRouteConfig($routeConfig),
				mcpTool: McpToolMetadata::fromArray($routeConfig['mcp'] ?? null),
			);

		}

		$controllersData = [];
		foreach ($methodDescriptionsControllerMap as $controllerFqcn => $methodDescriptions)
		{
			$controllersData[] = new ControllerData(
				module: self::MODULE,
				controllerFqcn: $controllerFqcn,
				methods: $methodDescriptions,
			);
		}

		return $controllersData;
	}

	/**
	 * Keeps unavailable controllers in provider data as a veto for namespace discovery.
	 *
	 * @return ControllerData[]
	 */
	protected function buildDisabledControllersDataFromRoutesConfig(
		array $availableRoutesConfig,
		array $routesConfig,
	): array
	{
		$unavailableControllers = [];
		foreach ($routesConfig as $routeConfig)
		{
			if (!isset($routeConfig['controller'], $routeConfig['method']))
			{
				continue;
			}

			$unavailableControllers[$routeConfig['controller']] = true;
		}

		foreach ($availableRoutesConfig as $routeConfig)
		{
			if (isset($routeConfig['controller']))
			{
				unset($unavailableControllers[$routeConfig['controller']]);
			}
		}

		$controllersData = [];
		foreach (array_keys($unavailableControllers) as $controllerFqcn)
		{
			$controllersData[] = new ControllerData(
				module: self::MODULE,
				controllerFqcn: $controllerFqcn,
				enabled: false,
			);
		}

		return $controllersData;
	}

	/** @return array<string, class-string<Feature\BaseFeature|Feature\AbstractCrmFlag>> */
	protected function getBuiltFeatureByRoute(): array
	{
		$this->getAvailableRoutesConfig();

		return $this->builtFeatureByRoute ?? [];
	}

	private function registerDto(string $dtoFqcn): void
	{
		if (
			is_subclass_of($dtoFqcn, Dto::class)
			&& Structure::getDto($dtoFqcn) === null
		)
		{
			Structure::addDto($dtoFqcn::create());
		}
	}

	/**
	 * Describes the global activity methods without routing them.
	 *
	 * {@see Controller\Activity} is an ordinary controller, found by the namespace of the module, and the
	 * schema builds a description of every action of it by reflection. A description given here carries the
	 * same action uri, so it replaces that one whole, and what it fixes is the two things reflection cannot
	 * know: the exact scope the methods are granted by and whether the wave they go out with is published.
	 * An unpublished method is refused before dispatch, with the answer REST gives a method it knows as
	 * disabled - the route itself stays in the schema either way.
	 *
	 * The two field methods of the resource are described here for the same reason: REST would otherwise
	 * build them on its own system controller and grant them by the scopes of the segments of their names,
	 * whatever the wave is doing. They are served by that system controller all the same, so the check of
	 * the wave this controller makes for itself never runs for them and they carry
	 * {@see ActivityFieldAccessProvider} instead: the schema caches the name of the class rather than its
	 * decision, so the wave is read on every call and not once per hour of the cache.
	 *
	 * Every published route of {@see Activity\EntityActivity} gets the same pair beside it, for the same reason:
	 * REST would build one for each of them too. A route the wave has taken out has no pair either.
	 */
	protected function buildActivityControllerData(): ControllerData
	{
		$isEnabled = $this->isActivityEnabled();
		$methodDescriptions = [];
		foreach (self::ACTIVITY_ACTIONS as $actionUri => $method)
		{
			$methodDescriptions[] = new MethodDescription(
				module: self::MODULE,
				controllerFqcn: Controller\Activity::class,
				method: $method,
				dtoFqcn: ActivityDto::class,
				scopes: self::ACTIVITY_SCOPES,
				actionUri: $actionUri,
				isEnabled: $isEnabled,
				mcpTool: McpToolMetadata::fromArray([
					'export' => true,
					'toolSetCode' => self::ACTIVITY_MCP_TOOL_SET_CODE,
				]),
			);
		}

		foreach (self::ACTIVITY_FIELD_ACTIONS as $actionUri => $method)
		{
			$methodDescriptions[] = $this->buildActivityFieldMethodDescription(
				$actionUri,
				$method,
				$isEnabled,
				McpToolMetadata::fromArray([
					'export' => true,
					'toolSetCode' => self::ACTIVITY_MCP_TOOL_SET_CODE,
				]),
			);
		}

		foreach ($this->getContextActivityResourceToolSets() as $resourceUri => $toolSetCode)
		{
			foreach (self::ACTIVITY_FIELD_ACTIONS as $method)
			{
				$description = $method === 'get'
					? "Get metadata for a specific {$resourceUri} field."
					: "List available fields for {$resourceUri}.";
				$methodDescriptions[] = $this->buildActivityFieldMethodDescription(
					$resourceUri . '.field.' . $method,
					$method,
					$isEnabled,
					McpToolMetadata::fromArray([
						'export' => true,
						'toolSetCode' => $toolSetCode,
						'descriptionOverride' => $description,
					]),
				);
			}
		}

		return new ControllerData(
			module: self::MODULE,
			controllerFqcn: Controller\Activity::class,
			namespace: self::CONTROLLER_NAMESPACE,
			methods: $methodDescriptions,
		);
	}

	private function buildActivityFieldMethodDescription(
		string $actionUri,
		string $method,
		bool $isEnabled,
		?McpToolMetadata $mcpTool = null,
	): MethodDescription
	{
		return new MethodDescription(
			module: self::MODULE,
			controllerFqcn: DtoFieldController::class,
			method: $method,
			dtoFqcn: DtoFieldDto::class,
			scopes: self::ACTIVITY_SCOPES,
			actionUri: $actionUri,
			isEnabled: $isEnabled,
			queryParams: ['dtoClass' => ActivityDto::class],
			accessProvider: ActivityFieldAccessProvider::class,
			resourceDtoFqcn: ActivityDto::class,
			mcpTool: $mcpTool,
		);
	}

	/**
	 * @return array<string, string> Tool set code for each published `crm.<entity>.activity` resource.
	 */
	private function getContextActivityResourceToolSets(): array
	{
		$resourceToolSets = [];
		foreach ($this->getAvailableRoutesConfig() as $actionUri => $routeConfig)
		{
			if (($routeConfig['controller'] ?? null) === Activity\EntityActivity::class)
			{
				$resourceToolSets[substr($actionUri, 0, (int)strrpos($actionUri, '.'))]
					= $routeConfig['mcp']['toolSetCode'];
			}
		}

		return $resourceToolSets;
	}

	protected function isActivityEnabled(): bool
	{
		return (new ActivityEnabledProvider())->isEnabled();
	}

	protected function buildHiddenControllerData(string $controllerFqcn, array $methods): ControllerData
	{
		$namespace = self::CONTROLLER_NAMESPACE;
		$controllerData = new ControllerData(
			module: self::MODULE,
			controllerFqcn: $controllerFqcn,
			namespace: $namespace,
		);

		foreach ($methods as $method)
		{
			$actionUri = $this->canonicalizeRouteName($controllerData->getMethodUri($method));
			$controllerData->addMethod(new MethodDescription(
				module: self::MODULE,
				controllerFqcn: $controllerFqcn,
				method: $method,
				dtoFqcn: null,
				scopes: $this->buildScopes($actionUri),
				actionUri: $actionUri,
				isHidden: true,
			));
		}

		return $controllerData;
	}

	protected function generateDtoFromRouteConfig(array $routeConfig): ?GeneratedDto
	{
		$isValidDtoGenerator =
			isset($routeConfig['dtoGenerator'])
			&& class_exists($routeConfig['dtoGenerator'])
			&& is_subclass_of($routeConfig['dtoGenerator'], DtoGeneratorInterface::class)
		;
		if (!$isValidDtoGenerator)
		{
			return null;
		}

		/** @var DtoGeneratorInterface $dtoGenerator */
		$dtoGenerator = new $routeConfig['dtoGenerator']();

		return $dtoGenerator->generate(...$this->buildDtoGeneratorArgsFromRouteConfig($routeConfig));
	}

	protected function buildDtoGeneratorArgsFromRouteConfig(array $routeConfig): array
	{
		$dtoGeneratorArgs = $routeConfig['dtoGeneratorArgs'] ?? [];
		if (isset($routeConfig['entityTypeId']) && !isset($dtoGeneratorArgs['entityTypeId']))
		{
			$dtoGeneratorArgs['entityTypeId'] = $routeConfig['entityTypeId'];
		}

		return $dtoGeneratorArgs;
	}

	protected function buildScopes(string $actionUri): array
	{
		$actionUri = $this->canonicalizeRouteName($actionUri);
		$scopes = [];
		$parts = explode('.', $actionUri);
		$scope = '';
		foreach ($parts as $part)
		{
			if ($part === '')
			{
				continue;
			}

			$scope =
				$scope === ''
					? $part
					: $scope . '.' . $part
			;
			$scopes[] = $scope;
		}

		return $scopes;
	}

	protected function canonicalizeRouteName(string $actionUri): string
	{
		return strtolower($actionUri);
	}

	/**
	 * @param array<string, mixed> $routes
	 * @return array<string, mixed>
	 */
	private function normalizeRouteMap(array $routes): array
	{
		$normalizedRoutes = [];
		$sourceRoutesByCanonicalName = [];

		foreach ($routes as $sourceRoute => $route)
		{
			$canonicalRoute = in_array($sourceRoute, self::ROUTES_WITHOUT_NORMALIZATION, true)
				? $sourceRoute
				: $this->canonicalizeRouteName($sourceRoute)
			;
			$previousSourceRoute = $sourceRoutesByCanonicalName[$canonicalRoute] ?? null;
			if ($previousSourceRoute !== null)
			{
				throw new \RuntimeException(sprintf(
					'CRM REST V3 route name conflict: "%s" and "%s" normalize to "%s".',
					$previousSourceRoute,
					$sourceRoute,
					$canonicalRoute,
				));
			}

			$sourceRoutesByCanonicalName[$canonicalRoute] = $sourceRoute;
			$normalizedRoutes[$canonicalRoute] = $route;
		}

		return $normalizedRoutes;
	}

	/**
	 * @param array<string, array> $routesConfig
	 * @param array<string, class-string<Feature\BaseFeature|Feature\AbstractCrmFlag>> $featureByRoute
	 * @return array{array<string, array>, array<string, class-string<Feature\BaseFeature|Feature\AbstractCrmFlag>>}
	 */
	private function normalizeRouteMaps(array $routesConfig, array $featureByRoute): array
	{
		return [
			$this->normalizeRouteMap($routesConfig),
			$this->normalizeRouteMap($featureByRoute),
		];
	}

	/**
	 * @param ControllerData[] $controllersData
	 */
	private function assertUniqueActionUris(array $controllersData): void
	{
		$sourceRoutesByCanonicalName = [];
		foreach ($controllersData as $controllerData)
		{
			foreach ($controllerData->getMethods() as $methodDescription)
			{
				$sourceRoute = $methodDescription->actionUri;
				$canonicalRoute = $this->canonicalizeRouteName($sourceRoute);
				$previousSourceRoute = $sourceRoutesByCanonicalName[$canonicalRoute] ?? null;
				if ($previousSourceRoute !== null)
				{
					throw new \RuntimeException(sprintf(
						'CRM REST V3 published route name conflict: "%s" and "%s" normalize to "%s".',
						$previousSourceRoute,
						$sourceRoute,
						$canonicalRoute,
					));
				}

				$sourceRoutesByCanonicalName[$canonicalRoute] = $sourceRoute;
			}
		}
	}

	protected function buildQueryParamsFromRouteConfig(array $routeConfig): ?array
	{
		$queryParams = $routeConfig['queryParams'] ?? [];
		if (isset($routeConfig['entityTypeId']))
		{
			$queryParams['entityTypeId'] = $routeConfig['entityTypeId'];
		}
		if (isset($routeConfig['categoryId']))
		{
			$queryParams['categoryId'] = $routeConfig['categoryId'];
		}

		return $queryParams === [] ? null : $queryParams;
	}

}
