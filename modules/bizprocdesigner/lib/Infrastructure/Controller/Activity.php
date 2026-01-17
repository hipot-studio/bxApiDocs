<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Controller;

use Bitrix\BizprocDesigner\Internal\Trait\ActivitySettingsDecoder;
use Bitrix\BizprocDesigner\Public\Command;
use Bitrix\Main\Engine\JsonController;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Bizproc\Api\Enum\ErrorMessage;

class Activity extends JsonController
{
	use ActivitySettingsDecoder;

	protected function init()
	{
		parent::init();
		Loader::requireModule('bizproc');
	}

	public function getSettingsControlsAction(
		array $documentType,
		array $activity,
	): ?array
	{
		$description = \CBPRuntime::getRuntime()->getActivityDescription($activity['Type']);
		$result = [
			'controls' => null,
			'useDocumentContext' => isset($description['FILTER']),
		];
		$configurator = \CBPActivity::createConfigurator($activity['Type'], $activity['Properties'] ?? []);

		if (!$configurator->getActivityType())
		{
			return $result;
		}
		$propertiesMap = $configurator->getPropertiesMap();

		$properties = [];
		foreach ($propertiesMap as $key => $property)
		{
			$properties[] = [
				'property' => $property,
				'fieldName' => $property['FieldName'] ?? $key,
				'value' => $activity['Properties'][$key] ?? $property['Default'] ?? null,
			];
		}

		$defaultControls = [
			[
				'property' => [
					'Name' => Loc::getMessage('BIZPROCDESIGNER_CONTROLLER_ACTIVITY_CONTROL_TITLE') ?? '',
					'Type' => \Bitrix\Bizproc\FieldType::STRING,
					'Required' => true,
				],
				'fieldName' => 'title',
				'value' => $activity['Properties']['Title'] ?? null,
				'controlId' => 'title',
			],
			[
				'property' => [
					'Name' => Loc::getMessage('BIZPROCDESIGNER_CONTROLLER_ACTIVITY_CONTROL_ID') ?? '',
					'Type' => \Bitrix\Bizproc\FieldType::STRING,
					'Required' => true,
					'Hidden' => true,
				],
				'fieldName' => 'activity_id',
				'value' => $activity['Name'] ?? null,
				'controlId' => 'activity_id',
			],
			[
				'property' => [
					'Name' => Loc::getMessage('BIZPROCDESIGNER_CONTROLLER_ACTIVITY_CONTROL_COMMENT') ?? '',
					'Type' => \Bitrix\Bizproc\FieldType::STRING,
					'Required' => false,
					'Hidden' => true,
				],
				'fieldName' => 'activity_editor_comment',
				'value' => $activity['Properties']['EditorComment'] ?? null,
				'controlId' => 'activity_editor_comment',
			],
		];

		$result['controls'] = array_merge($defaultControls, $properties);

		return $result;
	}

	public function saveSettingsAction(): ?array
	{
		$user = new \CBPWorkflowTemplateUser(\CBPWorkflowTemplateUser::CurrentUser);

		$json = $this->getRequest()->getJsonList();
		$currentRequest = $json->toArray();
		$documentType = (array)$currentRequest['documentType'];
		$canWrite = \CBPDocument::CanUserOperateDocumentType(
			\CBPCanUserOperateOperation::CreateWorkflow,
			$user->getId(),
			$documentType
		);

		if (!$canWrite)
		{
			$this->errorCollection->setError(ErrorMessage::ACCESS_DENIED->getError());

			return null;
		}

		$activityName = (string)($currentRequest['id'] ?? $currentRequest['activity_id'] ?? '');
		$isActivated = $currentRequest['activated'] ?? 'Y';

		[
			'template' => $workflowTemplate,
			'parameters' => $workflowParameters,
			'variables' => $workflowVariables,
			'constants' => $workflowConstants,
			'properties' => $activityProperties,
		] = $this->decodeActivitySettings($currentRequest, $documentType);

		/** @var Command\Activity\Settings\SaveCommandResult $result */
		$result =
			(new Command\Activity\Settings\SaveCommand(
				new Command\Activity\Settings\SaveCommandDto(
					activity: new Command\Activity\Settings\SaveCommandActivityDto(
						type: (string)($currentRequest['activityType'] ?? ''),
						name: $activityName,
						properties: $activityProperties,
						title: \CBPHelper::stringify($activityProperties['title'] ?? ''),
						editorComment: \CBPHelper::stringify($currentRequest['activity_editor_comment'] ?? ''),
						isActivated: ($isActivated === 'Y'),
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

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $result->getSettings()?->toArray();
	}
}
