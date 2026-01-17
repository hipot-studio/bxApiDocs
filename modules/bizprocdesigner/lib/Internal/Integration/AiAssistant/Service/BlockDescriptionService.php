<?php

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service;

use Bitrix\Bizproc\Internal\Entity\Activity\Result\ActivityAiDescriptionResult;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Result\BlockDescriptionResult;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Result\BlockSettingsResult;
use Bitrix\BizprocDesigner\Internal\Entity\BlockType;
use Bitrix\BizprocDesigner\Internal\Entity\BlockTypeCollection;
use Bitrix\BizprocDesigner\Internal\Entity\BlockTypeDetail;
use Bitrix\BizprocDesigner\Internal\Entity\DocumentDescription;
use Bitrix\BizprocDesigner\Internal\Entity\ReturnField;
use Bitrix\BizprocDesigner\Internal\Entity\ReturnFieldCollection;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;

class BlockDescriptionService
{
	private const ACTIVITY_AI_DESCRIPTION = 'AI_DESCRIPTION';
	private const ACTIVITY_DESCRIPTION = 'DESCRIPTION';
	private const ACTIVITY_NAME = 'NAME';

	public function getBlocksWithDescription(DocumentDescription $documentType): Result|BlockDescriptionResult
	{
		if (!Loader::includeModule('bizproc'))
		{
			return (new Result())->addError(new Error('Module bizproc is not installed'));
		}

		$runtime = \CBPRuntime::getRuntime();
		$activities = $runtime->searchActivitiesByType('activity', $documentType->toBizprocComplexType());
		if (!is_array($activities))
		{
			return (new Result())->addError(new Error('Search activities error'));
		}

		$triggers = $runtime->searchActivitiesByType('trigger', $documentType->toBizprocComplexType());
		if (!is_array($triggers))
		{
			return (new Result())->addError(new Error('Search activities error'));
		}

		$activities += $triggers;

		$blocks = new BlockTypeCollection();
		foreach ($activities as $type => $activity)
		{
			if (isset($activity['EXCLUDED']) && $activity['EXCLUDED'] === true)
			{
				continue;
			}

			$description = $this->getBlockDescription($activity);
			if ($type && $description && $this->isBlockSettingsDescribed($type, $documentType))
			{
				$blocks->add(
					new BlockType(
						type: $type,
						description: $description,
					)
				);
			}
		}

		return new BlockDescriptionResult($blocks);
	}

	public function getBlockSettings(DocumentDescription $documentType, string $blockType): Result|BlockSettingsResult
	{
		if (!Loader::includeModule('bizproc'))
		{
			return (new Result())->addError(new Error('Module bizproc is not installed'));
		}

		$runtime = \CBPRuntime::getRuntime();
		$typeToSearch = str_ends_with(mb_strtolower($blockType), 'trigger') ? 'trigger' : 'activity';
		$activities = $runtime->searchActivitiesByType($typeToSearch, $documentType->toBizprocComplexType());

		$activity = $activities[$blockType] ?? null;
		if (!is_array($activity))
		{
			return (new Result())->addError(new Error('Block not found'));
		}

		$result = $runtime
			->getAiDescriptionService()
			->getActivityDescription($blockType, $documentType->toBizprocComplexType())
		;

		if (!$result instanceof ActivityAiDescriptionResult)
		{
			return (new Result())
				->addError(new Error('Block settings is not described'))
				->addErrors($result->getErrors())
			;
		}

		return new BlockSettingsResult(new BlockTypeDetail(
			block: new BlockType(
				type: $blockType,
				description: $this->getBlockDescription($activity),
			),
			settings: $result->settings,
			returnFields: $this->getReturnFields($activity),
			describedTypes: $result->settings->getDescribedSettingTypesMap(),
		));
	}

	private function getBlockDescription(array $activity): string
	{
		$fieldPriority = [
			self::ACTIVITY_AI_DESCRIPTION,
			self::ACTIVITY_DESCRIPTION,
			self::ACTIVITY_NAME,
		];

		foreach ($fieldPriority as $fieldName)
		{
			$value = $activity[$fieldName] ?? '';
			if (is_string($value) && $value !== '')
			{
				return $value;
			}
		}

		return '';
	}

	private function getReturnFields(array $activity): ReturnFieldCollection
	{
		$activityReturn = (array)($activity['RETURN'] ?? []);
		$returnFields = new ReturnFieldCollection();

		foreach ($activityReturn as $name => $field)
		{
			$description = (string)($field['NAME'] ?? '');
			if ($description === '')
			{
				continue;
			}

			$type = (string)($field['TYPE'] ?? '');
			if ($type === '')
			{
				continue;
			}

			$returnFields->add(
				new ReturnField(
					name: $name,
					description: $description,
					type: $type,
				)
			);
		}

		return $returnFields;
	}

	private function isBlockSettingsDescribed(string $blockType, DocumentDescription $documentType): bool
	{
		$result = \CBPRuntime::getRuntime()
			->getAiDescriptionService()
			->getActivityDescription($blockType, $documentType->toBizprocComplexType())
		;

		return $result instanceof ActivityAiDescriptionResult;
	}
}