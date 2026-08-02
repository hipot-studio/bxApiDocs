<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Starter\Dto;

final readonly class ParentWorkflowDto
{
	public function __construct(
		public string $workflowId,
		public int $templateId,
	)
	{}

	public function toArray(): array
	{
		return [
			'workflowId' => $this->workflowId,
			'templateId' => $this->templateId,
		];
	}
}
