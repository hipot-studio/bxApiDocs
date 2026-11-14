<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Exception\Tracking;

use Bitrix\Crm\V2\Internal\Entity\Tracking\SourceRefusal;
use RuntimeException;

/**
 * A change of a source is refused before anything is written.
 *
 * @internal
 */
final class TrackingSourceRefusedException extends RuntimeException
{
	/**
	 * @param int|null $sourceId the record the change addressed; null when there is none
	 */
	public function __construct(
		public readonly SourceRefusal $reason,
		public readonly ?int $sourceId,
	)
	{
		parent::__construct(sprintf('Tracking source change is refused: %s.', $reason->name));
	}
}
