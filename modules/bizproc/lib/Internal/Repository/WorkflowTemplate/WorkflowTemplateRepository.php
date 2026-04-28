<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Repository\WorkflowTemplate;

use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;
use Bitrix\Main\ORM\Data\UpdateResult;

class WorkflowTemplateRepository
{
	public function updateTemplate(int $id, array $data): UpdateResult
	{
		return WorkflowTemplateTable::update($id, $data);
	}

	public function isTemplateActive(int $templateId): ?bool
	{
		$templateRow = WorkflowTemplateTable::query()
			->setSelect(['ACTIVE'])
			->where('ID', $templateId)
			->setLimit(1)
			->fetch()
		;

		if (!$templateRow)
		{
			return null;
		}

		return $templateRow['ACTIVE'] === 'Y';
	}
}
