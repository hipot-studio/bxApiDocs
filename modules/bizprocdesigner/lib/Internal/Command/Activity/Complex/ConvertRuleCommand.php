<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Command\Activity\Complex;

use Bitrix\Bizproc\Automation\Helper;
use Bitrix\Bizproc\Public\Activity\Interface\NodeFilterMetadataProvider;
use Bitrix\Bizproc\Public\Service\Activity\ActivityNameGeneratorService;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\PortRuleDto;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\Rule\ActionExpressionDto;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\Rule\ConditionExpressionDto;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\Rule\ConstructionDto;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\Rule\OutputExpressionDto;
use Bitrix\BizprocDesigner\Infrastructure\Enum\ConstructionType;
use Bitrix\BizprocDesigner\Internal\Command\AbstractCommand;
use Bitrix\BizprocDesigner\Internal\Entity\ActivityData;
use Bitrix\BizprocDesigner\Internal\Trait\ConditionValueResolver;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Main\Error;
use Bitrix\Main\DI;
use CBPRuntime;

class ConvertRuleCommand extends AbstractCommand
{
	use ConditionValueResolver;

	private const FILTER_RETURN_PROPERTIES_MAP = 'FilterReturnPropertiesMap';
	private const TARGET_FILTER_ID_PROPERTY = 'TargetFilterId';

	private ActivityNameGeneratorService $nameGeneratorService;

	private array $childrenActivities = [];
	private array $inputNames = [];
	private array $outputNames = [];
	private array $links = [];
	private array $filterSettings = [];
	private array $filterReturnProperties = [];
	private array $returnProperties = [];
	private array $returnPropertyIndexes = [];

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

		if (!empty($this->filterSettings))
		{
			$activity['Properties']['FilterSettings'] = $this->filterSettings;
		}
		else
		{
			unset($activity['Properties']['FilterSettings']);
		}

		if (!empty($this->filterReturnProperties))
		{
			$activity['Properties'][self::FILTER_RETURN_PROPERTIES_MAP] = $this->filterReturnProperties;
			$activity['ReturnProperties'] = $this->returnProperties;
		}
		else
		{
			unset($activity['Properties'][self::FILTER_RETURN_PROPERTIES_MAP]);
			unset($activity['ReturnProperties']);
		}

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
			$previousFilterId = null;

			$constructions = $rule->constructions;
			foreach ($constructions as $position => $construction)
			{
				/** @var ?ActivityData $currentActivity */
				$currentActivity = null;

				$expression = $construction->expression;
				if (
					$construction->constructionType === ConstructionType::FILTER
					&& $expression instanceof ActionExpressionDto
				)
				{
					$filterSettings = $this->extractFilterSettings($construction, $expression, $previousFilterId);
					if ($filterSettings !== null)
					{
						$this->filterSettings[] = $filterSettings;
						$previousFilterId = $filterSettings['id'];

						$filterReturnProperty = $this->extractFilterReturnProperty($expression, $filterSettings['id']);
						if ($filterReturnProperty !== null)
						{
							$this->registerFilterReturnProperty($filterSettings['id'], $filterReturnProperty);
						}
					}

					continue;
				}

				if ($expression instanceof ActionExpressionDto)
				{
					$activityData = $this->prepareActionActivityData(
						$expression->activityData ?? [],
						$this->resolveTargetFilterIdForAction($expression),
					);

					if (!empty($expression->auxPortId))
					{
						$activityData['Properties'] ??= [];
						$activityData['Properties']['auxPort'] = $expression->auxPortId;
					}

					$currentActivity = ActivityData::createFromArray($activityData);
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

	private function extractFilterSettings(
		ConstructionDto $construction,
		ActionExpressionDto $expression,
		?string $previousFilterId,
	): ?array
	{
		$activityData = $expression->activityData ?? [];
		$activityType = (string)($activityData['Type'] ?? '');
		if (!self::isNodeFilterBackingActivity($activityType))
		{
			return null;
		}

		$properties = is_array($activityData['Properties'] ?? null) ? $activityData['Properties'] : [];
		$conditions = is_array($properties['DynamicFilterFields'] ?? null)
			? $properties['DynamicFilterFields']
			: ['items' => []]
		;
		$filterId = is_string($activityData['Name'] ?? null) && $activityData['Name'] !== ''
			? $activityData['Name']
			: $construction->id
		;

		$filterSettings = [
			'id' => $filterId,
			'targetEntityTypeId' => (int)($properties['DynamicTypeId'] ?? 0),
			'sourceMode' => $previousFilterId ? 'filter' : 'workflow',
			'conditions' => $conditions,
		];

		if ($previousFilterId)
		{
			$filterSettings['sourceFilterId'] = $previousFilterId;
		}

		return $filterSettings;
	}

	private static function isNodeFilterBackingActivity(string $activityType): bool
	{
		if ($activityType === '')
		{
			return false;
		}

		if (!CBPRuntime::getRuntime()->includeActivityFile($activityType))
		{
			return false;
		}

		return is_subclass_of('CBP' . $activityType, NodeFilterMetadataProvider::class, true);
	}

	private function extractFilterReturnProperty(ActionExpressionDto $expression, string $filterId): ?array
	{
		$activityData = $expression->activityData ?? [];
		$returnProperties = is_array($activityData['ReturnProperties'] ?? null)
			? $activityData['ReturnProperties']
			: []
		;

		$documentProperty = null;
		foreach ($returnProperties as $property)
		{
			if (
				is_array($property)
				&& ($property['Id'] ?? null) === 'Document'
				&& is_array($property['Default'] ?? null)
			)
			{
				$documentProperty = $property;
				break;
			}
		}

		if ($documentProperty === null)
		{
			return null;
		}

		$documentTitle = trim((string)($documentProperty['Name'] ?? ''));
		$filterTitle = trim((string)($activityData['Properties']['Title'] ?? ''));
		$title = $documentTitle;
		if ($title !== '' && $filterTitle !== '')
		{
			$title .= " ({$filterTitle})";
		}
		elseif ($filterTitle !== '')
		{
			$title = $filterTitle;
		}

		return [
			'Name' => $title !== '' ? $title : $filterId,
			'Type' => \Bitrix\Bizproc\FieldType::DOCUMENT,
			'Multiple' => (bool)($documentProperty['Multiple'] ?? false),
			'Default' => $documentProperty['Default'],
		];
	}

	private function registerFilterReturnProperty(string $filterId, array $property): void
	{
		$this->filterReturnProperties[$filterId] = $property;

		$returnProperty = ['Id' => $filterId, ...$property];
		if (isset($this->returnPropertyIndexes[$filterId]))
		{
			$this->returnProperties[$this->returnPropertyIndexes[$filterId]] = $returnProperty;

			return;
		}

		$this->returnPropertyIndexes[$filterId] = count($this->returnProperties);
		$this->returnProperties[] = $returnProperty;
	}

	private function prepareActionActivityData(array $activityData, ?string $targetFilterId): array
	{
		$properties = is_array($activityData['Properties'] ?? null) ? $activityData['Properties'] : [];

		if ($targetFilterId !== null)
		{
			$properties[self::TARGET_FILTER_ID_PROPERTY] = $targetFilterId;
		}
		else
		{
			unset($properties[self::TARGET_FILTER_ID_PROPERTY]);
		}

		$activityData['Properties'] = $properties;

		return $activityData;
	}

	private function resolveTargetFilterIdForAction(ActionExpressionDto $expression): ?string
	{
		$document = $expression->document;
		if (!is_string($document) || $document === '')
		{
			return null;
		}

		$parsedExpression = \CBPActivity::parseExpression($document);
		if ($parsedExpression === null)
		{
			return null;
		}

		foreach ([$parsedExpression['object'] ?? null, $parsedExpression['field'] ?? null] as $candidateFilterId)
		{
			if (!is_string($candidateFilterId) || $candidateFilterId === '')
			{
				continue;
			}

			foreach ($this->filterSettings as $filter)
			{
				if (($filter['id'] ?? null) === $candidateFilterId)
				{
					return $candidateFilterId;
				}
			}
		}

		return null;
	}

	private function convertConditionExpressionToMixedConditionEntry(
		ConditionExpressionDto $expression,
		ConstructionType $constructionType,
	): array
	{
		$field = $expression->field;

		$value = $this->resolveConditionValue(
			$field,
			$expression->value ?? '',
			$this->documentType,
		);

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
				if ($construction->expression->field === null)
				{
					continue;
				}

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
