<?php

namespace Bitrix\Crm\RepeatSale\DataCollector;

use Bitrix\Crm\Traits\Singleton;
use Bitrix\Main\Config\Option;

final class CopilotMarkerLimitManager
{
	use Singleton;

	private const DEAL_FIELDS_MIN_LIMIT_OPTION_NAME = 'ai_integration_repeat_sale_marker_min_limit_value';
	private const DEAL_FIELDS_MIN_LIMIT_VALUE = 500;

	private const COMMUNICATION_FIELDS_LIMIT_OPTION_NAME = 'ai_integration_repeat_sale_marker_crm_data_sufficient_value';
	private const COMMUNICATION_FIELDS_LIMIT_VALUE = 2500;

	private const MIN_AI_COLLECTOR_COMMUNICATION_LENGTH_OPTION_NAME = 'ai_integration_repeat_sale_ai_collector_min_communication_length_value';
	private const MIN_AI_COLLECTOR_COMMUNICATION_LENGTH_VALUE = 0;

	private const MIN_AI_COLLECTOR_DEAL_FIELDS_LENGTH_OPTION_NAME = 'ai_integration_repeat_sale_ai_collector_min_deal_fields_length_value';
	private const MIN_AI_COLLECTOR_DEAL_FIELDS_LENGTH_VALUE = 0;

	public function getDealFieldsMinLimit(): int
	{
		return (int)Option::get(
			'crm',
			self::DEAL_FIELDS_MIN_LIMIT_OPTION_NAME,
			self::DEAL_FIELDS_MIN_LIMIT_VALUE,
		);
	}

	public function getCommunicationFieldsLimit(): int
	{
		return (int)Option::get(
			'crm',
			self::COMMUNICATION_FIELDS_LIMIT_OPTION_NAME,
			self::COMMUNICATION_FIELDS_LIMIT_VALUE,
		);
	}

	public function getMinSufficientAiCollectorDealFieldsLength(): int
	{
		return Option::get(
			'crm',
			self::MIN_AI_COLLECTOR_DEAL_FIELDS_LENGTH_OPTION_NAME,
			self::MIN_AI_COLLECTOR_DEAL_FIELDS_LENGTH_VALUE,
		);
	}

	public function getMinSufficientAiCollectorCommunicationLength(): int
	{
		return Option::get(
			'crm',
			self::MIN_AI_COLLECTOR_COMMUNICATION_LENGTH_OPTION_NAME,
			self::MIN_AI_COLLECTOR_COMMUNICATION_LENGTH_VALUE,
		);
	}
}
