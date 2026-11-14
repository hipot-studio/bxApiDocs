<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Tracking;

use Bitrix\Rest\V3\Structure\FieldsStructure;

/**
 * `crm.tracking.source.update`: the values to write into the source named as in {@see SourceAddressRequest}.
 *
 * A source is changed one at a time, so the update of a set by `filter` of the standard request is not here.
 */
final class UpdateSourceRequest extends SourceAddressRequest
{
	public FieldsStructure $fields;
}
