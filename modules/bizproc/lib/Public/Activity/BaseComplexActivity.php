<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Activity;

use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Internal\Entity\Workflow\ExecutionPayload;
use Bitrix\Bizproc\Public\Activity\Structure\FlowDirectedActivity;
use Bitrix\Bizproc\Internal\Entity\Activity\Interface\FlowCompositeActivity;
use Bitrix\Bizproc\Internal\Service\Container;
use Bitrix\Main\Localization\Loc;
use CBPActivity;
use CBPWorkflowTemplateUser;
use IBPConfigurableActivity;

/**
 * @property-read $Rules
 * @property-read $InputNames
 * @property-read $OutputNames
 */
abstract class BaseComplexActivity extends FlowDirectedActivity implements
	IBPConfigurableActivity,
	FlowCompositeActivity
{
	protected const RULES_PARAM = 'Rules';
	protected const INPUT_ACTIVITY_NAMES = 'InputNames';
	protected const OUTPUT_ACTIVITY_NAMES = 'OutputNames';
	protected const NOT_FILLED_MARK = 'NotFilled';

	protected array $queuePortIds = [];

	protected function __construct($name)
	{
		parent::__construct($name);

		$this->arProperties = array_merge(
			$this->arProperties,
			[
				static::RULES_PARAM => [],
				static::INPUT_ACTIVITY_NAMES => [],
				static::OUTPUT_ACTIVITY_NAMES => [],
			],
		);

		$this->setPropertiesTypes([
			static::RULES_PARAM => [
				'Type' => FieldType::RULES,
			],
		]);
	}

	public function executeWithPayload(ExecutionPayload $payload): int
	{
		$this->queuePortIds[] = $payload->getInputPort();

		return $this->execute();
	}

	public static function validateChild($childActivity, $bFirstChild = false)
	{
		$errors = [];

		$whiteList = [
			'IfElseActivity',
			'EmptyBlockActivity',
		];

		$nodeActionCollection = Container::instance()
			->getComplexActivityService()
			->getCorrespondingNodeActionActivityByName(static::getActivityName())
		;
		foreach ($nodeActionCollection as $nodeAction)
		{
			$whiteList[] = $nodeAction->getClass();
		}

		if (!in_array($childActivity, $whiteList, true))
		{
			$errors[] = [
				'code' => 'WrongChildType',
				'message' => Loc::getMessage('BIZPROC_PUBLIC_ACTIVITY_BCA_INVALID_CHILD'),
			];
		}

		return [...$errors, ...parent::validateChild($childActivity, $bFirstChild)];
	}

	protected static function getActivityName(): string
	{
		$class = static::class;

		return mb_strtolower(str_starts_with($class, 'CBP') ? mb_substr($class, 3) : $class);
	}

	/**
	 * @return list<string>
	 */
	protected function getStartActivityNames(): array
	{
		return array_values(
			array_intersect_key(
				$this->getRawProperty(static::INPUT_ACTIVITY_NAMES),
				array_flip($this->queuePortIds)
			)
		);
	}

	protected function onDeadEndReached(CBPActivity $lastActivity): void
	{
		$outputNames = $this->getRawProperty(static::OUTPUT_ACTIVITY_NAMES);

		$portId = 0;
		$nameWithPort = static::createOutputName($lastActivity->getName(), $lastActivity->getOutputPortId());
		if (isset($outputNames[$nameWithPort]))
		{
			$portId = (int)$outputNames[$nameWithPort];
		}

		$this->outputPortId = $portId;
	}

	protected function close(): void
	{
		$this->queuePortIds = [];

		parent::close();
	}

	public static function validateProperties($arTestProperties = [], ?CBPWorkflowTemplateUser $user = null): array
	{
		$arErrors = [];
		if (empty($arTestProperties[self::RULES_PARAM]))
		{
			$arErrors[] = [
				'code' => 'NotExist',
				'parameter' => self::RULES_PARAM,
				'message' => Loc::getMessage('BIZPROC_PUBLIC_ACTIVITY_BCA_EMPTY_RULES'),
			];
		}

		if (($arTestProperties[static::NOT_FILLED_MARK] ?? 'N') === 'Y')
		{
			$arErrors[] = $arErrors[] = [
				'code' => 'NotExist',
				'message' => Loc::getMessage('BIZPROC_PUBLIC_ACTIVITY_BCA_NOT_FILLED'),
			];
		}

		return array_merge($arErrors, parent::validateProperties($arTestProperties, $user));
	}

	protected static function getPropertiesMap(array $documentType, array $context = []): array
	{
		$complexActivityService = Container::instance()->getComplexActivityService();

		return [
			...$complexActivityService->configureRuleProperty(),
		];
	}

	public static function getPropertiesDialogValues(
		$documentType,
		$activityName,
		&$workflowTemplate,
		&$workflowParameters,
		&$workflowVariables,
		$currentValues,
		&$errors,
	): bool
	{
		// todo: realize base logic
		$errors = [];
		$properties = [];

		$documentService = \CBPRuntime::getRuntime()->getDocumentService();
		$map = static::getPropertiesMap($documentType, is_array($currentValues) ? $currentValues : []);

		foreach ($map as $id => $property)
		{
			$value = $documentService->getFieldInputValue(
				$documentType,
				$property,
				$property['FieldName'],
				$currentValues,
				$errors,
			);

			if (!empty($errors))
			{
				return false;
			}

			$properties[$id] = $value;
		}

		$currentActivity = &\CBPWorkflowTemplateLoader::findActivityByName($workflowTemplate, $activityName);
		$currentActivity['Properties'] = $properties;

		return true;
	}
}
