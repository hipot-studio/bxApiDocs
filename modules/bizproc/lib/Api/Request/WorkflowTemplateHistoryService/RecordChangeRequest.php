<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Api\Request\WorkflowTemplateHistoryService;

use Bitrix\Bizproc\Api\Data\WorkflowTemplateHistoryService\TemplateChangeAttribution;

final class RecordChangeRequest
{
	/**
	 * @param array $prevState template state before the save: TEMPLATE, NAME, DESCRIPTION, PARAMETERS,
	 * VARIABLES, CONSTANTS
	 * @param array $nextState the same fields after the save
	 * @param bool $isTemplateJournaled true when the caller has already made sure the template belongs to
	 * the history; the journal then spares itself a second read of the same template row
	 */
	public function __construct(
		public readonly int $templateId,
		public readonly TemplateChangeAttribution $attribution,
		public readonly array $prevState,
		public readonly array $nextState,
		public readonly bool $isTemplateJournaled = false,
	)
	{}
}
