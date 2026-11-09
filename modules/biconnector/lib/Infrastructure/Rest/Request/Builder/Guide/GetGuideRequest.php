<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Request\Builder\Guide;

use Bitrix\Main\Validation\Rule\Length;
use Bitrix\Rest\V3\Interaction\Request\Request;

/**
 * Arguments of biconnector.builder.guide.get, named as get_bi_guide names them.
 */
final class GetGuideRequest extends Request
{
	/**
	 * Id of a section from the table of contents; omitted, the method answers the table of contents.
	 * Left uninitialized when omitted: a default null would be checked by the length rule and refused.
	 */
	#[Length(max: 64)]
	public ?string $section;
}
