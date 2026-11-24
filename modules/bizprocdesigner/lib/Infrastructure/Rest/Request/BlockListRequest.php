<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Rest\Request;

use Bitrix\Rest\V3\Interaction\Request\ListRequest;

/**
 * Reading the block catalog of the template the caller names.
 *
 * The catalog is not one list per portal: it is built from the document the template runs on, so which
 * template is being asked about decides the answer. Under a token of the contour the field may be left
 * out and the template is taken from the binding of the token; an application key carries no binding,
 * and there the field is the only thing that names a template.
 */
final class BlockListRequest extends ListRequest
{
	public ?int $templateId = null;
}
