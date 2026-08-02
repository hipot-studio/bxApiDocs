<?php

namespace Bitrix\Bizproc\Controller;

use Bitrix\Bizproc\Api\Enum\ErrorMessage;

class GlobalField extends Base
{
	public function deleteAction($fieldId, $mode, $documentType): array
	{
		$documentType = \CBPDocument::unSignDocumentType($documentType);
		if (!is_array($documentType) || !\CBPHelper::normalizeComplexDocumentId($documentType))
		{
			return ['error' => ErrorMessage::ACCESS_DENIED->getError()->getMessage()];
		}

		$fieldId = htmlspecialcharsback($fieldId);

		return match ($mode)
		{
			'variable' => $this->deleteVariable($fieldId, $documentType),
			'constant' => $this->deleteConstant($fieldId, $documentType),
			default => [
				'error' => \Bitrix\Main\Localization\Loc::getMessage(
					'BIZPROC_CONTROLLER_GLOBALFIELD_MODE_NOT_DEFINED'
				),
			],
		};
	}

	private function deleteVariable($variableId, $documentType): array
	{
		$userId = (int)($this->getCurrentUser()->getId());
		$canDelete = \Bitrix\Bizproc\Workflow\Type\GlobalVar::canUserDelete($documentType, $userId);
		if (!$canDelete)
		{
			return [
				'error' => \Bitrix\Main\Localization\Loc::getMessage(
					'BIZPROC_CONTROLLER_GLOBALFIELD_CANT_DELETE_VARIABLE_RIGHT'
				),
			];
		}

		$field = \Bitrix\Bizproc\Workflow\Type\GlobalVar::getById($variableId);
		if (!$field)
		{
			return [
				'error' => \Bitrix\Main\Localization\Loc::getMessage(
					'BIZPROC_CONTROLLER_GLOBALFIELD_NOT_EXISTS_VARIABLE'
				),
			];
		}

		$result = \Bitrix\Bizproc\Workflow\Type\GlobalVar::delete($variableId);
		if (!$result)
		{
			return [
				'error' => \Bitrix\Main\Localization\Loc::getMessage(
					'BIZPROC_CONTROLLER_GLOBALFIELD_CANT_DELETE_VARIABLE'
				),
			];
		}

		return ['status' => 'success'];
	}

	private function deleteConstant($constantId, $documentType): array
	{
		$userId = (int)($this->getCurrentUser()->getId());
		$canDelete = \Bitrix\Bizproc\Workflow\Type\GlobalConst::canUserDelete($documentType, $userId);
		if (!$canDelete)
		{
			return [
				'error' => \Bitrix\Main\Localization\Loc::getMessage(
					'BIZPROC_CONTROLLER_GLOBALFIELD_CANT_DELETE_CONSTANT_RIGHT'
				),
			];
		}

		$field = \Bitrix\Bizproc\Workflow\Type\GlobalConst::getById($constantId);
		if (!$field)
		{
			return [
				'error' => \Bitrix\Main\Localization\Loc::getMessage(
					'BIZPROC_CONTROLLER_GLOBALFIELD_NOT_EXISTS_CONSTANT'
				),
			];
		}

		$result = \Bitrix\Bizproc\Workflow\Type\GlobalConst::delete($constantId);
		if (!$result)
		{
			return [
				'error' => \Bitrix\Main\Localization\Loc::getMessage(
					'BIZPROC_CONTROLLER_GLOBALFIELD_CANT_DELETE_CONSTANT'
				),
			];
		}

		return ['status' => 'success'];
	}

	public function upsertAction($fieldId, $property, $documentType, $mode): array
	{
		$documentType = \CBPDocument::unSignDocumentType($documentType);
		if (!is_array($documentType) || !\CBPHelper::normalizeComplexDocumentId($documentType))
		{
			return ['error' => ErrorMessage::ACCESS_DENIED->getError()->getMessage()];
		}

		return match ($mode)
		{
			'variable' => $this->upsertVariable($fieldId, $property, $documentType),
			'constant' => $this->upsertConstant($fieldId, $property, $documentType),
			default => [
				'error' => \Bitrix\Main\Localization\Loc::getMessage(
					'BIZPROC_CONTROLLER_GLOBALFIELD_MODE_NOT_DEFINED'
				),
			],
		};
	}

	private function upsertVariable($variableId, $property, $documentType): array
	{
		$userId = (int)($this->getCurrentUser()->getId());
		$canUpsert = \Bitrix\Bizproc\Workflow\Type\GlobalVar::canUserUpsert($documentType, $userId);
		if (!$canUpsert)
		{
			return [
				'error' => \Bitrix\Main\Localization\Loc::getMessage(
					'BIZPROC_CONTROLLER_GLOBALFIELD_CANT_UPSERT_VARIABLE_RIGHT'
				),
			];
		}

		$error = [];
		$value = $this->getDefaultValue($documentType, $property, $error);
		if ($error)
		{
			return ['error' => $error[0]['message']];
		}
		$property['Default'] = $value ?? '';
		$property['Name'] = trim($property['Name']);

		$userId = (int)($this->getCurrentUser()->getId());
		$result = \Bitrix\Bizproc\Workflow\Type\GlobalVar::upsertByProperty($variableId, $property, $userId);
		if (!$result->isSuccess())
		{
			return [
				'error' => $result->getErrorMessages()[0],
			];
		}

		return ['status' => 'success'];
	}

	private function upsertConstant($constantId, $property, $documentType): array
	{
		$userId = (int)($this->getCurrentUser()->getId());
		$canUpsert = \Bitrix\Bizproc\Workflow\Type\GlobalConst::canUserUpsert($documentType, $userId);
		if (!$canUpsert)
		{
			return [
				'error' => \Bitrix\Main\Localization\Loc::getMessage(
					'BIZPROC_CONTROLLER_GLOBALFIELD_CANT_UPSERT_CONSTANT_RIGHT'
				),
			];
		}

		$error = [];
		$value = $this->getDefaultValue($documentType, $property, $error);
		if ($error)
		{
			return ['error' => $error[0]['message']];
		}
		$property['Default'] = $value ?? '';
		$property['Name'] = trim($property['Name']);

		$userId = (int)($this->getCurrentUser()->getId());
		$result = \Bitrix\Bizproc\Workflow\Type\GlobalConst::upsertByProperty($constantId, $property, $userId);
		if (!$result->isSuccess())
		{
			return [
				'error' => $result->getErrorMessages()[0],
			];
		}

		return ['status' => 'success'];
	}

	private function getDefaultValue($documentType, $property, &$error)
	{
		$error = [];
		$runtime = \CBPRuntime::GetRuntime();
		$runtime->StartRuntime();
		$documentService = $runtime->GetService("DocumentService");
		$value = $documentService->GetFieldInputValue(
			$documentType,
			$property,
			'Default',
			$property,
			$error
		);

		return $value;
	}

	public function reloadAction($mode, $documentTypeSigned): array
	{
		$documentType = \CBPDocument::unSignDocumentType($documentTypeSigned);
		if (!is_array($documentType) || !\CBPHelper::normalizeComplexDocumentId($documentType))
		{
			return ['error' => ErrorMessage::ACCESS_DENIED->getError()->getMessage()];
		}

		return match ($mode)
		{
			'variable' => ['list' => \Bitrix\Bizproc\Workflow\Type\GlobalVar::getAll($documentType)],
			'constant' => ['list' => \Bitrix\Bizproc\Workflow\Type\GlobalConst::getAll($documentType)],
			default => [
				'error' => \Bitrix\Main\Localization\Loc::getMessage(
					'BIZPROC_CONTROLLER_GLOBALFIELD_MODE_NOT_DEFINED'
				),
			],
		};
	}
}
