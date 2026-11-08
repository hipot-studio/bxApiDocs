<?php

namespace Bitrix\BIConnector\Access\Update;

use Bitrix\BIConnector\Access\TableGroup\TableGroupBindingTable;
use Bitrix\BIConnector\ExternalSource\SupersetServiceIntegration;
use Bitrix\BIConnector\Superset\Logger\Logger;
use Bitrix\Main\Error;

/**
 * Release agent that delivers the CRM group bindings of the datasets linking CRM entities to contacts.
 */
final class CrmContactLinkTableGroupBindingAgent
{
	public const AGENT_NAME = 'Bitrix\\BIConnector\\Access\\Update\\CrmContactLinkTableGroupBindingAgent::run();';

	private const TABLE_CODES = [
		'crm_lead_contact',
		'crm_deal_contact',
		'crm_quote_contact',
		'crm_contact_company',
		'crm_entity_contact',
	];
	private const LOG_SOURCE = 'crm_contact_link_table_group_binding_agent';

	/**
	 * @return string Own name until every source is published and bound, an empty string afterwards.
	 */
	public static function run(): string
	{
		try
		{
			if (TableGroupBindingAgent::run() !== '')
			{
				return self::AGENT_NAME;
			}

			// CRM and BI ship independently: a binding made before the source arrives is not completion.
			if (!empty(array_diff(self::TABLE_CODES, SupersetServiceIntegration::getTableList())))
			{
				return self::AGENT_NAME;
			}

			$boundRows = TableGroupBindingTable::query()
				->setSelect(['TABLE_CODE'])
				->whereIn('TABLE_CODE', self::TABLE_CODES)
				->setGroup(['TABLE_CODE'])
				->fetchAll()
			;
			$unboundCodes = array_diff(self::TABLE_CODES, array_column($boundRows, 'TABLE_CODE'));

			return empty($unboundCodes) ? '' : self::AGENT_NAME;
		}
		catch (\Throwable $exception)
		{
			Logger::logErrors([new Error($exception->getMessage())], ['source' => self::LOG_SOURCE]);

			return self::AGENT_NAME;
		}
	}
}
