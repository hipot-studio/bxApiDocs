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
}
