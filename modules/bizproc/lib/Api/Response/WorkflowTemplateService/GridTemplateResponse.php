<?php

namespace Bitrix\Bizproc\Api\Response\WorkflowTemplateService;

use Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplate_Collection;
use Bitrix\Main\Result;

final class GridTemplateResponse extends Result
{
	public function getTotalCount(): int
	{
		return $this->data['totalCount'] ?? $this->getCollection()->count();
	}

	public function getCollection(): EO_WorkflowTemplate_Collection
	{
		return $this->data['collection'];
	}

	public function setTotalCount(int $totalCount): static
	{
		$this->data['totalCount'] = $totalCount;

		return $this;
	}

	public function setCollection(EO_WorkflowTemplate_Collection $collection): static
	{
		$this->data['collection'] = $collection;

		return $this;
	}
}
