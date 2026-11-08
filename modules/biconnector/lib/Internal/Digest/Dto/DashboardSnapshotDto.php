<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Digest\Dto;

/**
 * Everything the readiness check needs to know about a report, read locally in
 * one go: no call reaches the reports instance while the recipient is resolved.
 */
final class DashboardSnapshotDto
{
	/**
	 * @param int $id Report id on the portal.
	 * @param string $status One of SupersetDashboardTable::DASHBOARD_STATUS_*.
	 * @param string|null $type One of SupersetDashboardTable::DASHBOARD_TYPE_*; carried for the access check.
	 * @param int|null $externalId Superset id; empty until the report is synced.
	 * @param string[] $scopeParameterCodes Scope parameter codes registered for the report, globals excluded.
	 * @param string[] $scopeCodes Scope codes the report belongs to.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $status,
		public readonly ?string $type,
		public readonly ?int $externalId,
		public readonly array $scopeParameterCodes,
		public readonly array $scopeCodes,
	)
	{
	}
}
