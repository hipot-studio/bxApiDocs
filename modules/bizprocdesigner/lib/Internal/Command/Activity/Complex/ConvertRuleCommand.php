<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Command\Activity\Complex;

use Bitrix\Bizproc\Automation\Helper;
use Bitrix\Bizproc\Public\Service\Activity\ActivityNameGeneratorService;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\PortRuleDto;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\Rule\ActionExpressionDto;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\Rule\ConditionExpressionDto;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\Rule\ConstructionDto;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\Rule\OutputExpressionDto;
use Bitrix\BizprocDesigner\Infrastructure\Enum\ConstructionType;
use Bitrix\BizprocDesigner\Internal\Command\AbstractCommand;
use Bitrix\BizprocDesigner\Internal\Entity\ActivityData;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Main\Error;
use Bitrix\Main\DI;
use CBPRuntime;

class ConvertRuleCommand extends AbstractCommand
{
	private ActivityNameGeneratorService $nameGeneratorService;

	private array $childrenActivities = [];
	private array $inputNames = [];
	private array $outputNames = [];
	private array $links = [];

	/**
	 * @param ActivityData $activity
	 * @param array<string, PortRuleDto> $portRuleDtoDictionary
	 * @param array $documentType
	 */
	public function __construct(
		public ActivityData $activity,
		public array $portRuleDtoDictionary,
		public array $documentType,
	) {
		$this->nameGeneratorService = DI\ServiceLocator::getInstance()
			->get('bizproc.service.activity.nameGenerator')
		;
	}

	protected function beforeRun(): void
	{
		Loader::requireModule('bizproc');
		parent::beforeRun();
	}

	protected function validate(): bool
	{
		foreach ($this->portRuleDtoDictionary as $portId => $portRule)
		{
			$result = (new ValidateSingleRuleCommand($portRule))->run();
			if (!$result instanceof ValidateSingleRuleCommandResult)
			{
				return false;
			}

			if (!$result->isFilled)
			{
				$this->errors[] = new Error('Rules are not filled for port: ' . $portId);
				return false;
			}
		}

		return true;
	}

	/**
	 * @return Result|ConvertRuleCommandResult
	 */
	protected function execute(): Result|ConvertRuleCommandResult
	{
		foreach ($this->portRuleDtoDictionary as $portId => $portRule)
		{
			if (!$portRule instanceof PortRuleDto)
			{
				continue;
			}

			$this->processPortRuleCollection($portRule);
		}

		$activity = $this->activity->toArray();

		$activity['Children'] = $this->childrenActivities;
		$activity['Properties'] = [
			...$activity['Properties'],
			...[
				'InputNames' => $this->inputNames,
				'OutputNames' => $this->outputNames,
				'Links' => $this->links,
			],
		];

		return new ConvertRuleCommandResult(ActivityData::createFromArray($activity));
	}

	private function processPortRuleCollection(PortRuleDto $portRuleCollection): void
	{
		/** @var list<ActivityData> $inputActivities */
		$inputActivities = [];

		foreach ($portRuleCollection->rules as $rule)
		{
			/** @var ?ActivityData $inputActivity */
			$inputActivity = null;
			/** @var ?ActivityData $prevActivity */
			$prevActivity = null;

			$constructions = $rule->constructions;
			foreach ($constructions as $position => $construction)
			{
				/** @var ?ActivityData $currentActivity */
				$currentActivity = null;

				$expression = $construction->expression;
				if ($expression instanceof ActionExpressionDto)
				{
					$currentActivity = ActivityData::createFromArray($expression->activityData);
				}

				if (
					$construction->constructionType === ConstructionType::IF_CONDITION
					&& $expression instanceof ConditionExpressionDto
				)
				{
					$currentActivity = $this->processIfConditionExpression($expression, $position, $constructions);
				}

				if ($expression instanceof OutputExpressionDto)
				{
					$outputActivity = $prevActivity;
					if (!$outputActivity)
					{
						$outputActivity = self::makeStubOutputActivity();

						$this->childrenActivities[] = $outputActivity->toArray();
						$inputActivity ??= $outputActivity;
					}

					$this->processOutputExpression($expression, $outputActivity);

					break;
				}

				if ($currentActivity)
				{
					if ($prevActivity)
					{
						$this->links[] = ["$prevActivity->name:o0", "$currentActivity->name:i0"];
					}

					$prevActivity = $currentActivity;
					$inputActivity ??= $currentActivity;

					$this->childrenActivities[] = $currentActivity->toArray();
				}
			}

			if ($inputActivity)
			{
				$inputActivities[] = $inputActivity;
			}
		}

		$inputPortId = $portRuleCollection->portId;
		$inputPortNumber = (int)substr($inputPortId, 1);

		/* temporary only first input activity */
		$inputActivity = $inputActivities[0] ?? null;
		if ($inputActivity)
		{
			$this->inputNames[$inputPortNumber] = "$inputActivity->name:$inputPortId";
		}
	}
	
	private function convertConditionExpressionToMixedConditionEntry(
		ConditionExpressionDto $expression,
		ConstructionType $constructionType,
	): array
	{
		$field = $expression->field;

		$errors = [];
		$value = CBPRuntime::getRuntime()
			->getDocumentService()
			->getFieldInputValue(
				$this->documentType,
				[
					'Multiple' => $field->multiple,
					'Id' => $field->fieldId,
					'Type' => $field->type,
				],
				$field->fieldId,
				[
					$field->fieldId . '_text' => $expression->value,
				],
			$errors,
			) ?? ''
		;

		return [
			'object' => $field->object,
			'field' => $field->fieldId,
			'operator' => $expression->operator,
			'value' => $value,
			'joiner' => in_array($constructionType,
					[ConstructionType::AND_CONDITION, ConstructionType::IF_CONDITION],
					true,
				) ? '0' : '1'
			,
		];
	}

	/**
	 * @param ConditionExpressionDto $expression
	 * @param int $position
	 * @param list<ConstructionDto> $constructionList
	 * @return ActivityData
	 */
	private function processIfConditionExpression(
		ConditionExpressionDto $expression,
		int $position,
		array $constructionList,
	): ActivityData
	{
		/** @var list<ConstructionDto> $conditionGroupConstructions */
		$mixedConditions = [];
		$constructionListCount = count($constructionList);
		for ($i = $position; $i < $constructionListCount; $i++)
		{
			$construction = $constructionList[$i];
			if (
				$construction->constructionType->isCondition()
				&& $construction->expression instanceof ConditionExpressionDto
			)
			{
				$mixedConditions[] = $this->convertConditionExpressionToMixedConditionEntry(
					$construction->expression,
					$construction->constructionType,
				);
			}
			else
			{
				break;
			}
		}

		$conditionProperties = [
			'Title' => 'ComplexActivityCondition',
			'Conditions' => [
				[
					'Title' => 'ComplexActivityConditionGroup_Positive',
					'mixedcondition' => $mixedConditions,
				],
				[
					'Title' => 'ComplexActivityConditionGroup_Negative',
				],
			],
		];
		$conditionProperties = Helper::unConvertProperties($conditionProperties, $this->documentType);

		return ActivityData::createFromArray([
			'Name' => $this->nameGeneratorService->generate(),
			'Type' => 'IfElseActivity',
			'Properties' => $conditionProperties,
			'Activated' => 'Y',
		]);
	}

	private function processOutputExpression(
		OutputExpressionDto $expression,
		ActivityData $outputActivity,
	): void
	{
		$portId = $expression->portId;
		$portNumber = (int)substr($portId, 1);

		$this->outputNames["$outputActivity->name:o0"] = $portNumber;
	}

	private function makeStubOutputActivity(): ActivityData
	{
		return new ActivityData(
			name: $this->nameGeneratorService->generate(),
			type: 'EmptyBlockActivity',
			activated: true,
			properties: ['Title' => 'ComplexChildrenStubActivity'],
		);
	}
}
