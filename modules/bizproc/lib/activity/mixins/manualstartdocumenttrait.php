<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Activity\Mixins;

use Bitrix\Bizproc\FieldType;
use Bitrix\Main\Localization\Loc;

trait ManualStartDocumentTrait
{
	protected static function getReturnDocumentFieldName(): string
	{
		return 'ReturnDocument';
	}

	protected function initManualStartDocumentProperties(): void
	{
		$this->arProperties[static::getReturnDocumentFieldName()] = null;
	}

	public function execute(): int
	{
		$document = $this->getDocumentId();

		$this->setProperties([
			static::getReturnDocumentFieldName() => $document,
		]);

		$this->setPropertiesTypes([
			static::getReturnDocumentFieldName() => $this->getReturnDocumentMapTypeForInstance(),
		]);

		return \CBPActivityExecutionStatus::Closed;
	}

	protected function getReturnDocumentMapTypeForInstance(): array
	{
		return static::getReturnDocumentMapType();
	}

	protected static function getReturnDocumentMapType(): array
	{
		$document = static::resolveDocumentType();

		return [
			'Name' =>
				$document
					? static::getDocumentName($document)
					: (Loc::getMessage('BP_CRM_FCT_DOCUMENT') ?? '')
			,
			'Type' => FieldType::DOCUMENT,
			'Default' => $document,
		];
	}

	protected static function getDocumentName(array $documentType)
	{
		return \CBPRuntime::getRuntime()->getDocumentService()->getDocumentTypeName($documentType);
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
		$result = parent::getPropertiesDialogValues(
			$documentType,
			$activityName,
			$workflowTemplate,
			$workflowParameters,
			$workflowVariables,
			$currentValues,
			$errors,
		);

		if (!$result)
		{
			return false;
		}

		$currentActivity = &\CBPWorkflowTemplateLoader::FindActivityByName($workflowTemplate, $activityName);
		$properties = $currentActivity['Properties'];

		$properties['Return'] = [
			static::getReturnDocumentFieldName() => static::getReturnDocumentMapType(),
		];

		$currentActivity['Properties'] = $properties;

		return true;
	}

	protected static function resolveDocumentType(): ?array
	{
		return null;
	}

	protected function getDocumentComplexType(): array
	{
		return static::resolveDocumentType() ?? [];
	}
}
