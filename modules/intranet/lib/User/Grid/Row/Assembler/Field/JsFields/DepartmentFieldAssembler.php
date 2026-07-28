<?php

namespace Bitrix\Intranet\User\Grid\Row\Assembler\Field\JsFields;

use Bitrix\Intranet\Entity\Department;

class DepartmentFieldAssembler extends JsExtensionFieldAssembler
{
	protected function getExtensionClassName(): string
	{
		return 'DepartmentField';
	}

	protected function getRenderParams($rawValue): array
	{
		$departmentList = $this->getSettings()
			->getUserDepartmentsByUserId((int)$rawValue['ID'])
			->map(fn(Department $department) => [
				'id' => $department->getId(),
				'name' => htmlspecialcharsbx($department->getName()),
			]);

		return [
			'departments' => $departmentList,
			'canEdit' => false,
			'userId' => $rawValue['ID'],
			'selectedDepartment' => $this->extractSelectedDepartmentId(
				$this->getSettings()->getSelectedDepartmentFilterValue()
			),
		];
	}

	protected function prepareColumnForExport($data): string
	{
		$departmentNameList = $this->getSettings()
			->getUserDepartmentsByUserId((int)$data['ID'])
			->map(fn(Department $department) => htmlspecialcharsbx($department->getName()));

		return implode(', ', $departmentNameList);
	}

	private function extractSelectedDepartmentId(mixed $departmentFilterValue): ?int
	{
		if (!is_scalar($departmentFilterValue))
		{
			return null;
		}

		if (preg_match('/^(\d+)(?::F)?$/', (string)$departmentFilterValue, $matches))
		{
			return (int)$matches[1];
		}

		return null;
	}
}
