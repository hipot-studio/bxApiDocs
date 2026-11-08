<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\Activity\Enum;

/**
 * Markup of the description of an activity: it tells the readers (search index, timeline,
 * mail) how to render the text and how to strip it.
 *
 * There is no "undefined" member on purpose: the stored column uses 0 and NULL as
 * "not set" sentinels, and resolving them into a real markup is the job of the field map.
 */
enum DescriptionType: string
{
	case Plain = 'plain';
	case Bbcode = 'bbcode';
	case Html = 'html';
}
