<?php

namespace Bitrix\BIConnector\Access\TableGroup;

use Bitrix\BIConnector\ExternalSource\DatasetTableCodes;
use Bitrix\BIConnector\Integration\Crm\DynamicTableCodes;

/**
 * Composition of the system table groups: the declaration below plus the tables found on this
 * portal - the dynamic CRM ones and the external datasets.
 *
 * The map takes no part in membership resolution: that rule lives in TableGroupMembership and
 * reads the binding rows. Only exact table codes are used here, patterns and prefixes are not
 * supported by design.
 *
 * Smart processes, their product rows and automated solutions carry entity identifiers in their
 * codes and cannot be declared in advance, so getMap() resolves them from the portal on every call
 * and never hands out the bare declaration: a run that writes the composition would then see no
 * dynamic code and delete the bindings of those tables. The tables of the external datasets are
 * resolved the same way and for the same reason.
 *
 * Which half of the map is applied on a portal today, and what has to happen when a group or a
 * dataset is added - .ai/knowledge-base/notes/table-groups-map.md.
 */
final class SystemTableGroupMap
{
	public const GROUP_CRM = 'crm';
	public const GROUP_TASKS = 'tasks';
	public const GROUP_BIZPROC = 'bizproc';
	public const GROUP_SHOP = 'shop';
	public const GROUP_HR = 'hr';
	public const GROUP_EMPLOYEES = 'employees';
	public const GROUP_TELEPHONY = 'telephony';
	public const GROUP_CALENDAR = 'calendar';
	public const GROUP_BOOKING = 'booking';

	/**
	 * The group of the tables no other group declares - external datasets among them. The product
	 * shows it as "External sources", and only the phrase carries that name: the stored code is not
	 * shown to anyone, and renaming it would leave an orphan group on every portal that has it.
	 */
	public const GROUP_USER_TABLES = 'user_tables';

	// The step of five keeps up to 19 system groups ahead of TableGroupTable::DEFAULT_SORT.
	private const SORT_STEP = 5;

	/**
	 * Group code => exact codes of the tables the group consists of.
	 *
	 * The order of the groups is the order the product shows them in, and the user tables group closes
	 * the list. getSystemGroupSort() turns a position in this list into the stored SORT value.
	 *
	 * Some of the declared codes are conditional: a portal without contact or quote user fields does
	 * not publish crm_contact_uf and crm_quote_uf at all. A binding for a code the catalog does not
	 * publish costs one row and hurts nobody, while a missing binding would silently move the table
	 * to the user tables group on the portal where it does exist.
	 *
	 * @var array<string, string[]>
	 */
	private const DECLARED_MAP = [
		self::GROUP_CRM => [
			'crm_deal',
			'crm_deal_uf',
			'crm_deal_product_row',
			'crm_deal_stage_history',
			'crm_deal_contact',
			'crm_lead',
			'crm_lead_contact',
			'crm_lead_uf',
			'crm_lead_product_row',
			'crm_lead_status_history',
			'crm_company',
			'crm_company_uf',
			'crm_contact',
			'crm_contact_uf',
			'crm_contact_company',
			'crm_address',
			'crm_quote',
			'crm_quote_uf',
			'crm_quote_product_row',
			'crm_quote_contact',
			'crm_entity_contact',
			'crm_activity',
			'crm_activity_relation',
			'crm_entity_relation',
			'crm_entity_stage_history',
			'crm_last_communication',
			'crm_stages',
			'crm_smart_proc',
			'crm_product',
			'crm_product_property',
			'crm_product_property_value',
			'crm_ai_quality_assessment',
			'crm_copilot_call_assessment',
			'tracking_source',
			'tracking_source_expenses',
		],
		self::GROUP_SHOP => [
			'catalog_store',
			'catalog_store_product',
			'catalog_store_document',
			'catalog_store_document_item',
			'sale_document_saleorder',
			'sale_document_saleorder_item',
		],
		self::GROUP_TASKS => [
			'task',
			'task_uf',
			'task_stages',
			'task_elapsed_time',
			'task_efficiency',
			'flow',
			'socialnetwork_group',
		],
		self::GROUP_BIZPROC => [
			'bizproc_task',
			'bizproc_workflow_state',
			'bizproc_workflow_template',
		],
		self::GROUP_HR => [
			'org_structure',
			'org_structure_relation',
		],
		self::GROUP_EMPLOYEES => [
			'user',
		],
		self::GROUP_TELEPHONY => [
			'telephony_call',
			'imopenlines_session',
		],
		self::GROUP_CALENDAR => [
			'calendar_event',
		],
		self::GROUP_BOOKING => [
			'booking',
			'booking_resource_relation',
			'booking_resource',
			'booking_client_relation',
			'booking_sku_relation',
			'booking_wait_list',
			'booking_wait_list_client_relation',
		],
	];

	/**
	 * Costs two queries to the crm module and one to the datasets of this portal: both compositions
	 * depend on the portal. It is not memoized because the run that will apply the composition calls
	 * it once.
	 *
	 * @return array<string, string[]> Group code => exact codes of the tables the group consists of.
	 */
	public static function getMap(): array
	{
		$map = self::DECLARED_MAP;

		$dynamicCodes = DynamicTableCodes::getCodes();
		if (!empty($dynamicCodes))
		{
			$map[self::GROUP_CRM] = [
				...$map[self::GROUP_CRM],
				...$dynamicCodes,
			];
		}

		// Declared rather than left to the remainder of the catalog: a dataset is absent from the
		// catalog whenever the plan or the switch of its type hides it, and the remainder would
		// leave it without a binding until it shows up again - which may be after the one-shot
		// agent has covered the catalog and removed itself.
		$datasetCodes = DatasetTableCodes::getCodes();
		if (!empty($datasetCodes))
		{
			$map[self::GROUP_USER_TABLES] = $datasetCodes;
		}

		return $map;
	}

	/**
	 * Whether a group other than the user tables one declares the code - what getMap() answers for those
	 * groups, without reading the datasets of the portal. The declaration is checked before the crm queries.
	 */
	public static function isReservedCode(string $tableCode): bool
	{
		foreach (self::DECLARED_MAP as $tableCodes)
		{
			if (in_array($tableCode, $tableCodes, true))
			{
				return true;
			}
		}

		return in_array($tableCode, DynamicTableCodes::getCodes(), true);
	}

	/**
	 * The user tables group holds every table no other group declares, so the declaration above has
	 * no entry for it: getMap() fills it with the tables of the external datasets, and the binding
	 * installer adds whatever else the catalog turns out to carry.
	 */
	public static function getUserTablesGroupCode(): string
	{
		return self::GROUP_USER_TABLES;
	}

	/**
	 * Reads the declaration rather than getMap(): the group codes are known without the portal, and
	 * going through getMap() would make every caller pay for the crm queries it does not need.
	 *
	 * @return string[] Codes of all system groups, the user tables one included.
	 */
	public static function getSystemGroupCodes(): array
	{
		return [
			...array_keys(self::DECLARED_MAP),
			self::getUserTablesGroupCode(),
		];
	}

	/**
	 * Position of the system group in the list, by its place in the declaration above.
	 *
	 * The declaration is the only source of the order, so a group added to the middle of it moves
	 * the ones below down; TableGroupInstaller brings the portals that already have the groups to
	 * the new order. The step keeps the stored numbers small and every one of them ahead of
	 * TableGroupTable::DEFAULT_SORT, the position a custom group takes.
	 *
	 * @param string $groupCode
	 *
	 * @return int The default position when the code is not a system group.
	 */
	public static function getSystemGroupSort(string $groupCode): int
	{
		$position = array_search($groupCode, self::getSystemGroupCodes(), true);
		if ($position === false)
		{
			return TableGroupTable::DEFAULT_SORT;
		}

		return ($position + 1) * self::SORT_STEP;
	}
}
