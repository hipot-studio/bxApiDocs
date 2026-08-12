<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Command\Activity\Complex;

use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\PortRuleDto;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\Rule\ActionExpressionDto;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\Rule\ConditionExpressionDto;
use Bitrix\BizprocDesigner\Infrastructure\Enum\ConstructionType;
use Bitrix\BizprocDesigner\Internal\Command\AbstractCommand;

use Bitrix\BizprocDesigner\Internal\Trait\ActivitySettingsDecoder;
use Bitrix\BizprocDesigner\Internal\Trait\ConditionValueResolver;
use Bitrix\BizprocDesigner\Public\Command\Activity;
use Bitrix\Main\Result;
use Bitrix\Main\Web\Json;

class SaveSingleRuleCommand extends AbstractCommand
{
	use ActivitySettingsDecoder;
	use ConditionValueResolver;

	public function __construct(
		public readonly PortRuleDto $portRuleDto,
		public array $documentType,
	)
	{
	}

	protected function execute(): Result
	{
		$resultPortRule = clone $this->portRuleDto;

		$result = $this->processActionExpressions($resultPortRule);
		if (!$result->isSuccess())
		{
			return $result;
		}

		$result = $this->processConditionExpressions($resultPortRule);
		if (!$result->isSuccess())
		{
			return $result;
		}

		return new SaveSingleRuleCommandResult($resultPortRule);
	}

	private function processActionExpressions(PortRuleDto $resultPortRule): Result
	{
		$actionExpressionList = $this->extractActionExpressionList($resultPortRule);

		foreach ($actionExpressionList as $actionExpression)
		{
			if (empty($actionExpression->rawActivityData))
			{
				continue;
			}

			$saveActivityResult = $this->getActivitySettings($actionExpression);
			if ($saveActivityResult->isSuccess())
			{
				$actionExpression->rawActivityData = null;
				$activityData = $saveActivityResult->getSettings()?->toArray() ?? [];
				if (!empty($activityData))
				{
					$activityData['Document'] = $actionExpression->document;
				}

				$actionExpression->activityData = $activityData;
			}
			else
			{
				return $saveActivityResult;
			}
		}

		return new Result();
	}

	/**
	 * @param PortRuleDto $portRuleDto
	 * @return list<ActionExpressionDto>
	 */
	private function extractActionExpressionList(PortRuleDto $portRuleDto): array
	{
		$actionExpressionList = [];

		$rules = $portRuleDto->rules;
		foreach ($rules as $rule)
		{
			foreach ($rule->constructions as $construction)
			{
				if (
					$construction->constructionType !== ConstructionType::ACTION
					&& $construction->constructionType !== ConstructionType::FILTER
				)
				{
					continue;
				}

				$expression = $construction->expression;

				if (!$expression instanceof ActionExpressionDto)
				{
					continue;
				}

				$actionExpressionList[] = $expression;
			}
		}

		return $actionExpressionList;
	}

	private function getActivitySettings(ActionExpressionDto $actionExpression): Activity\Settings\SaveCommandResult
	{
		$rawActivityData = $this->modifyRawActivityDataTemplate($actionExpression->rawActivityData);

		$activityName = (string)$rawActivityData['id'];
		$isActivated = $rawActivityData['activated'] ?? 'Y';

		$documentType = $this->extractDocumentType($rawActivityData) ?? $this->documentType;
		[
			'template' => $workflowTemplate,
			'parameters' => $workflowParameters,
			'variables' => $workflowVariables,
			'constants' => $workflowConstants,
			'properties' => $activityProperties,
		] = $this->decodeActivitySettings($rawActivityData, $documentType);

		/** @var Activity\Settings\SaveCommandResult $result */
		$result =
			(new Activity\Settings\SaveCommand(
				new Activity\Settings\SaveCommandDto(
					activity: new Activity\Settings\SaveCommandActivityDto(
						type: (string)($rawActivityData['activityType'] ?? ''),
						name: $activityName,
						properties: $activityProperties,
						title: $activityProperties['title'] ?? '',
						isActivated: $isActivated === 'Y',
					),
					documentType: $documentType,
					template: $workflowTemplate,
					variables: $workflowVariables,
					parameters: $workflowParameters,
					constants: $workflowConstants,
				)
			))
				->run()
		;

		return $result;
	}

	private function processConditionExpressions(PortRuleDto $resultPortRule): Result
	{
		foreach ($this->extractConditionExpressionList($resultPortRule) as $conditionExpression)
		{
			$field = $conditionExpression->field;
			if ($field === null)
			{
				continue;
			}

			$conditionExpression->value = $this->resolveConditionValue(
				$field,
				$conditionExpression->value,
				$this->documentType,
			);
		}

		return new Result();
	}

	/**
	 * @param PortRuleDto $portRuleDto
	 * @return list<ConditionExpressionDto>
	 */
	private function extractConditionExpressionList(PortRuleDto $portRuleDto): array
	{
		$list = [];

		foreach ($portRuleDto->rules as $rule)
		{
			foreach ($rule->constructions as $construction)
			{
				if (
					$construction->constructionType->isCondition()
					&& $construction->expression instanceof ConditionExpressionDto
				)
				{
					$list[] = $construction->expression;
				}
			}
		}

		return $list;
	}

	private function modifyRawActivityDataTemplate(array $rawActivityData): array
	{
		$activityName = (string)($rawActivityData['id'] ?? '');
		$workflowTemplate = Json::decode($rawActivityData['arWorkflowTemplate'] ?? '[]');

		$workflowTemplate[$activityName] = [
			'Name' => $activityName,
			'Properties' => [],
			'Activated' => 'Y',
			'Type' => $rawActivityData['activityType'] ?? '',
		];

		$rawActivityData['arWorkflowTemplate'] = Json::encode($workflowTemplate);

		return $rawActivityData;
	}
}
