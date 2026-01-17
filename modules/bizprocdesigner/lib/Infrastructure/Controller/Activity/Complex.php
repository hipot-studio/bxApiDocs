<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Controller\Activity;

use Bitrix\Bizproc\Api\Enum\ErrorMessage;
use Bitrix\Bizproc\Internal\Service\Container;
use Bitrix\Bizproc\Public\Activity\Configurator;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\SaveSettingsRequestDto;
use Bitrix\BizprocDesigner\Internal\Exception\CommandValidateException;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\ActionDictionaryEntryDto;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\LoadSettingsResponseDto;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\PortRuleDto;
use Bitrix\BizprocDesigner\Internal\Command\Activity\Complex\ConvertRuleCommand;
use Bitrix\BizprocDesigner\Internal\Command\Activity\Complex\ConvertRuleCommandResult;
use Bitrix\BizprocDesigner\Internal\Command\Activity\Complex\SaveSingleRuleCommand;
use Bitrix\BizprocDesigner\Internal\Command\Activity\Complex\SaveSingleRuleCommandResult;
use Bitrix\BizprocDesigner\Internal\Entity\ActivityData;
use Bitrix\Main\Engine\AutoWire\ExactParameter;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use CBPCanUserOperateOperation;
use CBPDocument;
use Exception;

class Complex extends \Bitrix\Main\Engine\JsonController
{
	protected function init()
	{
		parent::init();
		Loader::requireModule('bizproc');
	}

	public function getAutoWiredParameters()
	{
		return [
			new ExactParameter(
				PortRuleDto::class,
				'portRule',
				static fn(string $className, array $portRule) => PortRuleDto::fromArray($portRule),
			),
			new ExactParameter(
				ActivityData::class,
				'activity',
				static fn(string $className, array $activity) => ActivityData::createFromArray($activity),
			),
			new ExactParameter(
				SaveSettingsRequestDto::class,
				'saveSettingsRequest',
				static fn(string $className, array $saveSettingsRequest) => SaveSettingsRequestDto::fromArray($saveSettingsRequest),
			),
		];
	}

	public function loadSettingsAction(ActivityData $activity): ?LoadSettingsResponseDto
	{
		if (empty($activity->type))
		{
			return null;
		}

		$configurator = \CBPActivity::createConfigurator($activity->type, $activity->properties);
		if (empty($configurator->getActivityType()))
		{
			$this->addError(ErrorMessage::ACTIVITY_NOT_FOUND->getError());
			return null;
		}

		$complexActivityService = Container::instance()->getComplexActivityService();

		$complexActivityName = strtolower($activity->type);
		$nodeActionCollection = $complexActivityService
			->getCorrespondingNodeActionActivityByName($complexActivityName)
		;
		if ($nodeActionCollection->isEmpty())
		{
			$this->addError(ErrorMessage::UNKNOWN_ERROR->getError());
			return null;
		}

		$portRuleDtoDictionary = $this->extractRulePropertyValue($configurator, $activity);
		if (!$portRuleDtoDictionary)
		{
			$this->addError(ErrorMessage::UNKNOWN_ERROR->getError());
			return null;
		}

		$actionDictionary = [];
		foreach ($nodeActionCollection as $nodeAction)
		{
			$actionDictionary[$nodeAction->getClass()] = new ActionDictionaryEntryDto(
				id: $nodeAction->getClass(),
				title: $nodeAction->getName(),
				handlesDocument: $nodeAction->getNodeActionSettings()['HANDLES_DOCUMENT'] ?? false,
			);
		}

		return new LoadSettingsResponseDto(
			title: $activity->properties['Title'] ?? '',
			description: $activity->properties['EditorComment'] ?? '',
			portRuleDtoDictionary: $portRuleDtoDictionary,
			actionEntryDtoDictionary: $actionDictionary,
			fixedDocumentType: $complexActivityService->getFixedDocumentTypeForNodeAction($activity->type),
		);
	}

	public function saveSettingsAction(
		SaveSettingsRequestDto $saveSettingsRequest,
		ActivityData $activity,
		array $documentType,
	): ?array
	{
		$result = $this->validateCurrentUserOperateDocument(
			operation: CBPCanUserOperateOperation::CreateWorkflow,
			documentType: $documentType,
		);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());
			return null;
		}

		$configurator = \CBPActivity::createConfigurator($activity->type, $activity->properties);
		if (empty($configurator->getActivityType()))
		{
			$this->addError(ErrorMessage::ACTIVITY_NOT_FOUND->getError());
			return null;
		}

		if (empty($saveSettingsRequest->portRuleCollectionDictionary))
		{
			$this->addError(ErrorMessage::UNKNOWN_ERROR->getError());
			return null;
		}

		$activity = $this->applySettingsToActivity($configurator, $activity, $saveSettingsRequest);
		if (!$activity)
		{
			$this->addError(ErrorMessage::UNKNOWN_ERROR->getError());
			return null;
		}

		$portRuleDtoDictionary = $this->extractRulePropertyValue($configurator, $activity);
		if (!$portRuleDtoDictionary)
		{
			$this->addError(ErrorMessage::UNKNOWN_ERROR->getError());
			return null;
		}

		try
		{
			$result = (new ConvertRuleCommand(
				activity: $activity,
				portRuleDtoDictionary: $portRuleDtoDictionary,
				documentType: $documentType,
			))->run();
		}
		catch (CommandValidateException $e)
		{
			return [
				'activity' => $this->markActivityFilled($activity, isFilled: false),
			];
		}
		catch (Exception)
		{
			$this->addError(ErrorMessage::UNKNOWN_ERROR->getError());
			return null;
		}

		if (!$result instanceof ConvertRuleCommandResult)
		{
			$this->addErrors($result->getErrors());
			return null;
		}

		return [
			'activity' => $this->markActivityFilled($result->activityData),
		];
	}

	public function saveRuleAction(
		PortRuleDto $portRule,
		array $documentType,
	): ?PortRuleDto {
		$result = $this->validateCurrentUserOperateDocument(
			operation: CBPCanUserOperateOperation::CreateWorkflow,
			documentType: $documentType,
		);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());
			return null;
		}

		try
		{
			$result = (new SaveSingleRuleCommand($portRule, $documentType))->run();
		}
		catch (Exception)
		{
			$this->addError(ErrorMessage::UNKNOWN_ERROR->getError());
			return null;
		}

		if (!$result instanceof SaveSingleRuleCommandResult)
		{
			$this->addErrors($result->getErrors());
			return null;
		}

		return $result->portRuleDto;
	}

	private function applySettingsToActivity(
		Configurator $configurator,
		ActivityData $activity,
		SaveSettingsRequestDto $saveSettingsRequestDto,
	): ?ActivityData
	{
		$rulePropertyName = $configurator->getFirstPropertyByType(\Bitrix\Bizproc\FieldType::RULES)['FieldName'] ?? null;
		if (!$rulePropertyName)
		{
			return null;
		}

		$newActivity = $activity->toArray();

		$newActivity['Properties'] = [
			...($newActivity['Properties'] ?? []),
			$rulePropertyName => $saveSettingsRequestDto->portRuleCollectionDictionary,
			'Title' => $saveSettingsRequestDto->title,
			'EditorComment' => $saveSettingsRequestDto->description,
		];

		return ActivityData::createFromArray($newActivity);
	}

	/**
	 * @param Configurator $configurator
	 * @param ActivityData $activity
	 * @return list<PortRuleDto>|null
	 */
	private function extractRulePropertyValue(Configurator $configurator, ActivityData $activity): ?array
	{
		$ruleProperty = $configurator->getFirstPropertyByType(\Bitrix\Bizproc\FieldType::RULES);
		$rulePropertyName = $ruleProperty['FieldName'] ?? null;
		if (!$rulePropertyName)
		{
			return null;
		}

		$rulePropertyValue = $activity->properties[$rulePropertyName] ?? $ruleProperty['Default'] ?? [];
		if (empty($rulePropertyValue))
		{
			return null;
		}

		return array_map(
			static fn($ruleCollection) => PortRuleDto::fromArray($ruleCollection),
			$rulePropertyValue,
		);
	}

	/**
	 * @param \CBPCanUserOperateOperation::* $operation
	 * @param array $documentType
	 * @return Result
	 */
	private function validateCurrentUserOperateDocument(int $operation, array $documentType): Result
	{
		$result = new Result();

		$userId = $this->getCurrentUser()->getId();
		$canOperate = CBPDocument::CanUserOperateDocumentType(
			$operation,
			$userId,
			$documentType,
		);

		if (!$canOperate)
		{
			$result->addError(ErrorMessage::ACCESS_DENIED->getError());
		}

		return $result;
	}

	private function markActivityFilled(ActivityData $activityData, bool $isFilled = true): ActivityData
	{
		$activityData = $activityData->toArray();
		if ($isFilled)
		{
			unset($activityData['Properties']['NotFilled']);
		}
		else
		{
			$activityData['Properties']['NotFilled'] = 'Y';
		}

		return ActivityData::createFromArray($activityData);
	}
}
