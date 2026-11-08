<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Feedback;

final class FeedbackFormRow
{
	/**
	 * @param string[] $zones
	 */
	public function __construct(
		public readonly array $zones,
		public readonly string $lang,
		public readonly string $id,
		public readonly string $sec,
		public readonly string $portalUri,
	)
	{
	}
}
