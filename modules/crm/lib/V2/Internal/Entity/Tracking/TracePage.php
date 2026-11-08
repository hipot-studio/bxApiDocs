<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\Tracking;

use Bitrix\Main\Type\DateTime;

/**
 * A page of the site the visitor of a trace opened.
 *
 * @internal
 */
final readonly class TracePage
{
	public function __construct(
		public string $url,
		public ?string $title,
		public ?DateTime $time,
	)
	{
	}
}
