<?php

namespace Bitrix\Intranet\User\Grid\Row\Assembler\Field\JsFields;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Grid\Settings;
use CIBlock;

class DepartmentFieldAssembler extends JsExtensionFieldAssembler
{
	private array $departmentsData;
	private bool $canEdit;

	public function __construct(array $columnIds, ?Settings $settings = null)
	{
		parent::__construct($columnIds, $settings);
		$this->departmentsData = \CIntranetUtils::GetStructureWithoutEmployees()['DATA'];

		$iblockId = Option::get('intranet', 'iblock_structure', false);
		$this->canEdit = CIBlock::GetPermission($iblockId) >= 'U';
	}

	protected function getExtensionClassName(): string
	{
		return 'DepartmentField';
	}

	protected function getRenderParams($rawValue): array
	{
		$departmentList = [];

		if (is_array($rawValue['UF_DEPARTMENT']))
		{
			foreach($rawValue['UF_DEPARTMENT'] as $departmentId)
			{
				if (
					!empty($this->departmentsData[$departmentId])
					&& isset($this->departmentsData[$departmentId]['NAME'])
					&& $this->departmentsData[$departmentId]['NAME'] <> ''
				)
				{
					$departmentName = htmlspecialcharsbx($this->departmentsData[$departmentId]['NAME']);
					$departmentUrl = htmlspecialcharsbx(str_replace(['#ID#', '#SITE_DIR#'], [$departmentId, SITE_DIR], $this->departmentsData[$departmentId]['SECTION_PAGE_URL']));
					$departmentList[] = [
						'id' => $departmentId,
						'name' => $departmentName,
						'url' => $departmentUrl,
					];
				}
			}
		}

		return [
			'departments' => $departmentList,
			'canEdit' => $this->canEdit,
			'userId' => $rawValue['ID'],
			'selectedDepartment' => $this->getSettings()->getFilterFields()['=UF_DEPARTMENT'] ?? $this->getSettings()->getFilterFields()['@UF_DEPARTMENT'][0] ?? null
		];
	}

	protected function prepareColumnForExport($data): string
	{
		$departmentNameList = [];

		if (is_array($data['UF_DEPARTMENT']))
		{
			foreach($data['UF_DEPARTMENT'] as $departmentId)
			{
				if (
					!empty($this->departmentsData[$departmentId])
					&& isset($this->departmentsData[$departmentId]['NAME'])
					&& $this->departmentsData[$departmentId]['NAME'] <> ''
				)
				{
					$departmentNameList[] = htmlspecialcharsbx($this->departmentsData[$departmentId]['NAME']);
				}
			}
		}

		return implode(', ', $departmentNameList);
	}
}
