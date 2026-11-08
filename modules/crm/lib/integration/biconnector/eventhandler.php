<?php

namespace Bitrix\Crm\Integration\BiConnector;

use Bitrix\BIConnector\DB\MysqliConnection;
use Bitrix\Main\Event;

class EventHandler
{
	/**
	 * Event handler for onBIConnectorDataSources event.
	 * Adds a key crm_contact to the second event parameter.
	 * Fills it with data to retrieve information from b_crm_dynamic_type table.
	 *
	 * @param Event $event Event data.
	 *
	 * @return void
	 */
	public static function onBIConnectorDataSources(Event $event): void
	{
		$params = $event->getParameters();
		$result = &$params[1];
		$languageId = $params[2];

		/** @var MysqliConnection $connection */
		$connection = $params[0]->getDatabaseConnection();
		$helper = $connection->getSqlHelper();

		$eventTableName = $params[3];
		if (!empty($eventTableName))
		{
			if (str_starts_with($eventTableName, 'crm_automated_solution_'))
			{
				$result = AutomatedSolutionMapping::getMapping($languageId, $eventTableName);

				return;
			}

			if (str_starts_with($eventTableName, 'crm_dynamic_items_prod_'))
			{
				$result = DynamicItemsProductMapping::getMapping($helper, $languageId, $eventTableName);

				return;
			}

			if ($eventTableName === 'crm_quote_uf')
			{
				$crmQuoteUf = QuoteUserFieldsMapping::getMapping($languageId);
				if ($crmQuoteUf)
				{
					$result['crm_quote_uf'] = $crmQuoteUf;
				}

				return;
			}

			if ($eventTableName === 'crm_requisite_uf')
			{
				$crmRequisiteUf = static::getRequisiteUserFieldsMapping($languageId);
				if ($crmRequisiteUf)
				{
					$result['crm_requisite_uf'] = $crmRequisiteUf;
				}

				return;
			}

			$tableNames = [
				'crm_smart_proc',
				'crm_stages',
				'crm_entity_relation',
				'crm_lead_contact',
				'crm_deal_contact',
				'crm_quote_contact',
				'crm_contact_company',
				'crm_quote',
				'crm_quote_product_row',
				'crm_activity_relation',
				'crm_ai_quality_assessment',
				'crm_copilot_call_assessment',
				'crm_entity_stage_history',
				'crm_last_communication',
				'crm_address',
				'crm_requisite',
				'crm_bank_detail',
				'crm_requisite_preset',
			];

			if (!in_array($eventTableName, $tableNames, true))
			{
				return;
			}

			$result[$eventTableName] = match($eventTableName)
			{
				'crm_smart_proc' => DynamicTypeMapping::getMapping(),
				'crm_stages' => StagesMapping::getMapping($helper, $languageId),
				'crm_entity_relation' => EntityRelationMapping::getMapping(),
				'crm_lead_contact' => LeadContactMapping::getMapping(),
				'crm_deal_contact' => DealContactMapping::getMapping(),
				'crm_quote_contact' => QuoteContactMapping::getMapping(),
				'crm_contact_company' => ContactCompanyMapping::getMapping(),
				'crm_quote' => QuoteMapping::getMapping($helper),
				'crm_quote_product_row' => QuoteProductMapping::getMapping($helper),
				'crm_activity_relation' => ActivityRelationMapping::getMapping(),
				'crm_ai_quality_assessment' => AiQualityAssessmentMapping::getMapping(),
				'crm_copilot_call_assessment' => CopilotCallAssessmentMapping::getMapping(),
				'crm_entity_stage_history' => EntityStageHistoryMapping::getMapping($helper),
				'crm_last_communication' => LastCommunicationMapping::getMapping(),
				'crm_address' => AddressMapping::getMapping($helper, $languageId),
				'crm_requisite' => RequisiteMapping::getMapping(),
				'crm_bank_detail' => BankDetailMapping::getMapping(),
				'crm_requisite_preset' => RequisitePresetMapping::getMapping(),
			};

			self::addDescriptions([
				$eventTableName,
			], $result, $languageId);

			return;
		}

		$result['crm_smart_proc'] = DynamicTypeMapping::getMapping();
		$result['crm_stages'] = StagesMapping::getMapping($helper, $languageId);
		$result['crm_entity_relation'] = EntityRelationMapping::getMapping();
		$result['crm_lead_contact'] = LeadContactMapping::getMapping();
		$result['crm_deal_contact'] = DealContactMapping::getMapping();
		$result['crm_quote_contact'] = QuoteContactMapping::getMapping();
		$result['crm_contact_company'] = ContactCompanyMapping::getMapping();
		$result['crm_quote'] = QuoteMapping::getMapping($helper);
		$result['crm_quote_product_row'] = QuoteProductMapping::getMapping($helper);
		$result['crm_activity_relation'] = ActivityRelationMapping::getMapping();
		$result['crm_ai_quality_assessment'] = AiQualityAssessmentMapping::getMapping();
		$result['crm_copilot_call_assessment'] = CopilotCallAssessmentMapping::getMapping();
		$result['crm_entity_stage_history'] = EntityStageHistoryMapping::getMapping($helper);
		$result['crm_last_communication'] = LastCommunicationMapping::getMapping();
		$result['crm_address'] = AddressMapping::getMapping($helper, $languageId);
		$result['crm_requisite'] = RequisiteMapping::getMapping();
		$result['crm_bank_detail'] = BankDetailMapping::getMapping();
		$result['crm_requisite_preset'] = RequisitePresetMapping::getMapping();
		$result = array_merge(
			$result,
			AutomatedSolutionMapping::getMapping($languageId),
			DynamicItemsProductMapping::getMapping($helper, $languageId),
		);
		$crmQuoteUf = QuoteUserFieldsMapping::getMapping($languageId);
		if ($crmQuoteUf)
		{
			$result['crm_quote_uf'] = $crmQuoteUf;
		}

		$crmRequisiteUf = static::getRequisiteUserFieldsMapping($languageId);
		if ($crmRequisiteUf)
		{
			$result['crm_requisite_uf'] = $crmRequisiteUf;
		}

		self::addDescriptions([
			'crm_smart_proc',
			'crm_stages',
			'crm_entity_relation',
			'crm_lead_contact',
			'crm_deal_contact',
			'crm_quote_contact',
			'crm_contact_company',
			'crm_quote',
			'crm_quote_product_row',
			'crm_activity_relation',
			'crm_ai_quality_assessment',
			'crm_copilot_call_assessment',
			'crm_entity_stage_history',
			'crm_last_communication',
			'crm_address',
			'crm_requisite',
			'crm_bank_detail',
			'crm_requisite_preset',
		], $result, $languageId);
	}

	protected static function getRequisiteUserFieldsMapping(string $languageId): ?array
	{
		return RequisiteUserFieldsMapping::getMapping($languageId);
	}

	private static function addDescriptions(array $keys, array &$mapping, ?string $languageId): void
	{
		foreach ($keys as $key)
		{
			$entityName = strtoupper($key);
			$mapping[$key]['TABLE_DESCRIPTION'] = Localization::getMessage($entityName . '_TABLE', $languageId) ?: $key;
			$mapping[$key]['TABLE_DESCRIPTION_FULL'] = Localization::getMessage($entityName . '_TABLE_DESCRIPTION_FULL_MSGVER_1', $languageId) ?? '';
			foreach ($mapping[$key]['FIELDS'] as $fieldCode => &$fieldInfo)
			{
				$fieldInfo['FIELD_DESCRIPTION'] =  Localization::getMessage($entityName . '_FIELD_' . $fieldCode, $languageId) ?: $fieldCode;
				$fieldInfo['FIELD_DESCRIPTION_FULL'] = Localization::getMessage($entityName . '_FIELD_' . $fieldCode . '_FULL', $languageId) ?? '';
			}
			unset($fieldInfo);
		}
	}
}
