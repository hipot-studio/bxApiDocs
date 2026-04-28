<?php

namespace Bitrix\Crm\Integration\AI\Operation;

use Bitrix\Crm\Dto\Dto;
use Bitrix\Crm\Format\TextHelper;
use Bitrix\Crm\Integration\AI\Dto\RepeatSale\FillRepeatSaleTipsPayload;
use Bitrix\Crm\Integration\AI\EventHandler;
use Bitrix\Crm\Integration\AI\Model\EO_Queue;
use Bitrix\Crm\RepeatSale\DataCollector\CopilotMarkerLimitManager;
use Bitrix\Main\Web\Json;

abstract class AbstractFillRepeatSaleTips extends AbstractOperation
{
	public const CONTEXT_ID = 'fill_repeat_sale_tips';

	protected const PAYLOAD_CLASS = FillRepeatSaleTipsPayload::class;
	protected const ENGINE_CODE = EventHandler::SETTINGS_REPEAT_SALE_ENGINE_CODE;

	protected function isPayloadMarkersValid(array $markers): bool
	{
		if (empty($markers))
		{
			return false;
		}

		$crmData = Json::decode($markers['crm_data'] ?? '');
		if (empty($crmData))
		{
			return false;
		}

		$dealList = $crmData['deals_list'] ?? [];
		if (empty($dealList))
		{
			return false;
		}

		$limit = CopilotMarkerLimitManager::getInstance()->getDealFieldsMinLimit();
		$filtered = array_filter(
			$dealList,
			static function (array $item) use ($limit): bool
			{
				$dealFields = $item['deal_fields'] ?? [];
				$communicationData = $item['communication_data'] ?? [];

				return
					TextHelper::countCharactersInArrayFlexible($dealFields, true) > $limit
					|| TextHelper::countCharactersInArrayFlexible($communicationData) > $limit;
			},
		);

		return !empty($filtered);
	}

	protected static function extractPayloadFromAIResult(\Bitrix\AI\Result $result, EO_Queue $job): Dto
	{
		$json = self::extractPayloadPrettifiedData($result);
		if (empty($json))
		{
			return new FillRepeatSaleTipsPayload([]);
		}

		return new FillRepeatSaleTipsPayload([
			'customerInfo' => self::underscoreToCamelCase($json['customer_info'] ?? []),
			'actionPlan' => self::underscoreToCamelCase($json['action_plan'] ?? []),
		]);
	}

	private static function underscoreToCamelCase(array $input): array
	{
		return array_combine(
			array_map(
				static fn(string $key) => lcfirst(str_replace('_', '', ucwords($key, '_'))),
				array_keys($input),
			),
			array_values($input),
		);
	}
}
