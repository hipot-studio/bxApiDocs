<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Runtime\Mixins;

use Bitrix\Bizproc;
use Bitrix\Main;
use CBPActivity;
use CBPArgumentNullException;
use CBPDocument;
use CBPHelper;

trait ActivityRuntimePropertyGetter
{
	/**
	 * @param string $object
	 * @param string $field
	 * @param CBPActivity $ownerActivity
	 * @return array<mixed, mixed>
	 * @throws CBPArgumentNullException
	 */
	public function getRuntimeProperty($object, $field, CBPActivity $ownerActivity): array
	{
		$rootActivity = $ownerActivity->getRootActivity();

		$result = null;
		$property = null;

		if (!$object)
		{
			return [$property, $result];
		}
		if ($object === 'Workflow')
		{
			return $this->getRuntimeWorkflowField($field, $ownerActivity);
		}
		if ($object === 'User')
		{
			return $this->getRuntimeUserField($field, $ownerActivity);
		}
		if ($object === Bizproc\Workflow\Template\SourceType::System)
		{
			return $this->getRuntimeSystemField($field, $ownerActivity);
		}
		if ($object === 'Template' || $object === Bizproc\Workflow\Template\SourceType::Parameter)
		{
			$result = $rootActivity->__get($field);
			$property = $rootActivity->getTemplatePropertyType($field);
		}
		elseif ($object === Bizproc\Workflow\Template\SourceType::Variable)
		{
			$result = $rootActivity->getVariable($field);
			$property = $rootActivity->getVariableType($field);
		}
		elseif ($object === Bizproc\Workflow\Template\SourceType::Constant)
		{
			$result = $rootActivity->getConstant($field);
			$property = $rootActivity->getConstantType($field);
		}
		elseif ($object === Bizproc\Workflow\Template\SourceType::GlobalConstant)
		{
			$result = Bizproc\Workflow\Type\GlobalConst::getValue($field);
			$property = Bizproc\Workflow\Type\GlobalConst::getById($field);
		}
		elseif ($object === Bizproc\Workflow\Template\SourceType::GlobalVariable)
		{
			$result = Bizproc\Workflow\Type\GlobalVar::getValue($field);
			$property = Bizproc\Workflow\Type\GlobalVar::getById($field);
		}
		elseif ($object === Bizproc\Workflow\Template\SourceType::DocumentField)
		{
			return $this->getRuntimeDocumentField($field, $ownerActivity);
		}
		else
		{
			if ($object === Bizproc\Workflow\Template\SourceType::Input)
			{
				$activity = $this->parent;
			}
			else
			{
				$activity = $ownerActivity->workflow->getActivityByName($object);
			}

			if ($activity)
			{
				return $this->getRuntimeActivityField($field, $activity);
			}

			return [null, null];
		}

		if (!$property)
		{
			$property = ['Type' => 'string'];
		}

		return [$property, $result];
	}

	private function getRuntimeDocumentField(string $field, CBPActivity $activity): array
	{
		$rootActivity = $activity->getRootActivity();
		$documentType = $rootActivity->getDocumentType();
		$usedDocumentFields = $rootActivity->{CBPDocument::PARAM_USED_DOCUMENT_FIELDS} ?? [];

		$documentService = $activity->workflow->getService('DocumentService');
		$documentFields = $documentService->getDocumentFields($documentType);
		$documentId = $activity->getDocumentId();
		$property = $documentFields[$field] ?? null;

		if (!$property)
		{
			$documentFieldsAliasesMap = CBPDocument::getDocumentFieldsAliasesMap($documentFields);
			if (isset($documentFieldsAliasesMap[$field]))
			{
				$field = $documentFieldsAliasesMap[$field];
				$property = $documentFields[$field];
			}
		}

		$result = $documentService->getFieldValue($documentId, $field, $documentType, $usedDocumentFields);

		if (!$property)
		{
			$property = ['Type' => 'string'];
		}

		return [$property, $result];
	}

	private function getRuntimeWorkflowField(string $field, CBPActivity $activity): array
	{
		$workflowField = mb_strtolower($field);
		if ($workflowField === 'templateid')
		{
			return [
				['Type' => Bizproc\FieldType::INT],
				$activity->getWorkflowTemplateId(),
			];
		}

		return [
			['Type' => 'string'],
			$activity->getWorkflowInstanceId(),
		];
	}

	private function getRuntimeUserField(string $field, CBPActivity $activity): array
	{
		$user = new \CBPWorkflowTemplateUser(\CBPWorkflowTemplateUser::CurrentUser);

		return [
			['Type' => Bizproc\FieldType::USER],
			$user->getBizprocId(),
		];
	}

	private function getRuntimeSystemField(string $field, CBPActivity $activity): array
	{
		$systemField = mb_strtolower($field);
		if ($systemField === 'now')
		{
			return [
				['Type' => Bizproc\FieldType::DATETIME],
				new Bizproc\BaseType\Value\DateTime(),
			];
		}
		if ($systemField === 'nowlocal')
		{
			return [
				['Type' => Bizproc\FieldType::DATETIME],
				new Bizproc\BaseType\Value\DateTime(time(), \CTimeZone::GetOffset()),
			];
		}
		if ($systemField === 'date')
		{
			return [
				['Type' => Bizproc\FieldType::DATE],
				new Bizproc\BaseType\Value\Date(),
			];
		}
		if ($systemField === 'eol')
		{
			return [
				['Type' => Bizproc\FieldType::STRING],
				PHP_EOL,
			];
		}
		if ($systemField === 'hosturl')
		{
			return [
				['Type' => Bizproc\FieldType::STRING],
				Main\Engine\UrlManager::getInstance()->getHostUrl(),
			];
		}

		return [['Type' => Bizproc\FieldType::STRING], null];
	}

	private function getRuntimeActivityField(string $field, CBPActivity $activity): array
	{
		$documentFieldName = null;

		$property = $activity->getPropertyType($field);
		if (!$property && str_contains($field, '.'))
		{
			$parts = explode('.', $field);
			$baseFieldName = array_shift($parts);
			$property = $activity->getPropertyType($baseFieldName);
			if ($property)
			{
				$documentFieldName = implode('.', $parts);
				$field = $baseFieldName;
			}
		}

		$result = $activity->__get($field);

		if (($property['Type'] ?? '') === Bizproc\FieldType::DOCUMENT && $documentFieldName)
		{
			$docId = CBPHelper::normalizeComplexDocumentId($result);
			if ($docId)
			{
				$documentService = $activity->workflow->getService('DocumentService');
				$documentType = $documentService->getDocumentType($docId);

				$result = $documentService->getFieldValue(
					$docId,
					$documentFieldName,
					$documentType,
					[$documentFieldName]
				);
				$documentFields = $documentService->getDocumentFields($documentType);

				$property = $documentFields[$documentFieldName] ?? null;
			}
		}

		if (!$property)
		{
			$property = ['Type' => 'string'];
		}

		return [$property, $result];
	}
}
