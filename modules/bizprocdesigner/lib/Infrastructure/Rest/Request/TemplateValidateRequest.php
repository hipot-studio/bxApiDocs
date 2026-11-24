<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Rest\Request;

use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\FieldsStructure;

/**
 * Dry run of a workflow graph: the graph to judge, in the same field an agent writes it with.
 *
 * The graph travels in fields so that the core converts it into the template dto and validates the form
 * against the add group - the very check the write path runs, which is what keeps the two verdicts one.
 * Not AddRequest: nothing is created here, and a dry run must not take part in idempotency.
 */
final class TemplateValidateRequest extends Request
{
	public FieldsStructure $fields;

	/**
	 * The template the graph is judged against: the document it runs on decides which blocks and fields
	 * are legal, so a dry run is answered per template just as a write is. Optional under a token of the
	 * contour, where the template comes from the binding; the only thing that names a template under an
	 * application key.
	 */
	public ?int $templateId = null;
}
