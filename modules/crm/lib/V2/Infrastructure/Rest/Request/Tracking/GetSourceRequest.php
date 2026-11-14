<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Tracking;

use Bitrix\Rest\V3\Structure\SelectStructure;

/**
 * `crm.tracking.source.get`: the source named as in {@see SourceAddressRequest} and the fields of it to answer.
 */
final class GetSourceRequest extends SourceAddressRequest
{
	public ?SelectStructure $select = null;
}
