<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\DataView\Interface;

use Bitrix\Bizproc\Public\DataView\Dto\SourceRef;

/**
 * Secondary capability of a data source provider: availability of a single source.
 * A provider implementing it is asked about one source instead of being asked for its whole catalog.
 */
interface SourceAvailabilityCapableProvider
{
	/**
	 * Answers for one source exactly as membership in the catalog of the same actor and template
	 * context would, without building that catalog.
	 */
	public function isSourceAvailable(SourceRef $source, int $actorId, ?int $templateId): bool;
}
