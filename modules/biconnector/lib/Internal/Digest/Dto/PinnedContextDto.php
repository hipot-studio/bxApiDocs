<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Digest\Dto;

/**
 * What a ready recipient carries into the send step. The set of fields is a
 * cross-feature contract: the digest activity packs exactly these values into
 * its property, so adding or dropping one changes that contract.
 *
 * Serialization stays out of the domain layer: the activity decides how these
 * values become a string.
 */
final class PinnedContextDto
{
	/**
	 * @param array<string, int|string> $scopeValues Scope parameter code => value id; empty when the report has no scope.
	 * @param array{from: string, to: string}|null $period Present only when the report period was trimmed.
	 */
	public function __construct(
		public readonly int $dashboardId,
		public readonly array $scopeValues,
		public readonly string $runId,
		public readonly ?array $period = null,
	)
	{
	}
}
