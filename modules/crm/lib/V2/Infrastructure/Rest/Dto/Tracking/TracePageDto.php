<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Tracking;

use Bitrix\Main\Type\DateTime;
use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\Required;
use Bitrix\Rest\V3\Dto\Dto;

/**
 * A page of the site the visitor of a trace opened.
 */
final class TracePageDto extends Dto
{
	#[Editable(['add'])]
	#[Required(['add'])]
	public string $url;

	#[Editable(['add'])]
	public ?string $title;

	#[Editable(['add'])]
	public ?DateTime $time;
}
