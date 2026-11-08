<?php

namespace Bitrix\Crm\Integration\AI\Operation;

use Bitrix\Crm\Dto\Dto;
use Bitrix\Crm\Format\TextHelper;
use Bitrix\Crm\Integration\AI\AIManager;
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

		$baseDeal = $crmData['base_deal'] ?? [];
		if (!empty($baseDeal))
		{
			return true;
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

		self::logUnreadableSections($json, $job);

		$customerInfo = self::readSectionFields($json, 'customer_info');
		$actionPlan = self::readSectionFields($json, 'action_plan');

		$fields = [];
		if (!empty($customerInfo))
		{
			$fields['customerInfo'] = $customerInfo;
		}

		if (!empty($actionPlan))
		{
			$fields['actionPlan'] = $actionPlan;
		}

		if (empty($fields) && self::isAnswerSayingThereIsNothingToRecommend($json))
		{
			$fields['noRecommendation'] = true;
		}

		return new FillRepeatSaleTipsPayload($fields);
	}

	private static function logUnreadableSections(array $json, EO_Queue $job): void
	{
		foreach (['customer_info', 'action_plan'] as $sectionKey)
		{
			if (self::isSectionReadable($json, $sectionKey))
			{
				continue;
			}

			AIManager::logger()->warning(
				'{date}: {class}: Unreadable section {sectionKey} in AI answer for job {jobHash},'
				. ' valid fields are kept' . PHP_EOL,
				[
					'class' => static::class,
					'sectionKey' => $sectionKey,
					'jobHash' => (string)$job->getHash(),
				],
			);
		}
	}

	/**
	 * An answer nobody could read carries no verdict and stays a parse error.
	 */
	private static function isAnswerSayingThereIsNothingToRecommend(array $json): bool
	{
		return (self::isSectionPresent($json, 'customer_info') || self::isSectionPresent($json, 'action_plan'))
			&& self::isSectionReadable($json, 'customer_info')
			&& self::isSectionReadable($json, 'action_plan');
	}

	/**
	 * Blank is not unreadable: a section stays readable while it is absent, null or an object of
	 * scalars, and only blank values are dropped from its fields.
	 *
	 * @return array<string, scalar>
	 */
	private static function readSectionFields(array $json, string $key): array
	{
		$section = $json[$key] ?? null;
		if (!is_array($section))
		{
			return [];
		}

		$fields = [];
		foreach ($section as $name => $value)
		{
			if (!is_string($name) || ($value !== null && !is_scalar($value)))
			{
				continue;
			}

			if ($value !== null && trim((string)$value) !== '')
			{
				$fields[self::underscoreToCamelCase($name)] = $value;
			}
		}

		return $fields;
	}

	private static function isSectionPresent(array $json, string $key): bool
	{
		return array_key_exists($key, $json);
	}

	private static function isSectionReadable(array $json, string $key): bool
	{
		$section = $json[$key] ?? null;
		if ($section === null)
		{
			return true;
		}

		if (!is_array($section))
		{
			return false;
		}

		foreach ($section as $name => $value)
		{
			if (!is_string($name) || ($value !== null && !is_scalar($value)))
			{
				return false;
			}
		}

		return true;
	}

	private static function underscoreToCamelCase(string $key): string
	{
		return lcfirst(str_replace('_', '', ucwords($key, '_')));
	}
}
