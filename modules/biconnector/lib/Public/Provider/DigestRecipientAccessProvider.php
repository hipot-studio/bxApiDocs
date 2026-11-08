<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Public\Provider;

use Bitrix\BIConnector\Integration\Superset\Model;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Internal\Digest\Dto\DepartmentAccessDto;
use Bitrix\BIConnector\Internal\Digest\Reason\DigestReasonClass;
use Bitrix\BIConnector\Internal\Digest\Service\DepartmentDashboardAccessEvaluator;
use Bitrix\BIConnector\Internal\Digest\Service\RecipientReadinessService;
use Bitrix\BIConnector\Public\Dto\RecipientAccessIssue;

/**
 * Tells which of the chosen recipients will not be sent a digest of a report,
 * and why, while the digest is still being set up.
 *
 * Only settings problems are reported. Any other reason means every setting
 * checked out, and a state that resolves itself says nothing about the people
 * chosen - warning about it during setup would be noise the setter cannot act on.
 *
 * A chosen department is checked by the report view right alone, as a whole,
 * without walking its people.
 */
final class DigestRecipientAccessProvider
{
	private readonly DepartmentDashboardAccessEvaluator $departmentEvaluator;

	public function __construct(
		private readonly RecipientReadinessService $readinessService,
		?DepartmentDashboardAccessEvaluator $departmentEvaluator = null,
	)
	{
		$this->departmentEvaluator = $departmentEvaluator ?? new DepartmentDashboardAccessEvaluator();
	}

	/**
	 * @param int[] $recipientIds Explicitly chosen people; departments go to {@see self::findDepartmentIssues()}.
	 * @param array<string, int> $scopeValues Scope parameter code => chosen value id; empty when no scope is set.
	 *
	 * @return array<int, RecipientAccessIssue|null> Recipient id => issue, or null when there is nothing to warn about.
	 */
	public function findIssues(int $dashboardId, array $recipientIds, array $scopeValues = []): array
	{
		$issues = [];
		foreach ($recipientIds as $id)
		{
			$recipientId = (int)$id;
			if ($recipientId <= 0 || array_key_exists($recipientId, $issues))
			{
				continue;
			}

			$issues[$recipientId] = $this->findIssue($recipientId, $dashboardId, $scopeValues);
		}

		return $issues;
	}

	/**
	 * @param list<array{id: int, withSubdepartments: bool}> $departments
	 *
	 * @return list<DepartmentAccessDto> Departments where somebody has no access; a department
	 *                                   that could not be checked is left out.
	 */
	public function findDepartmentIssues(int $dashboardId, array $departments): array
	{
		if (empty($departments))
		{
			return [];
		}

		$ormObject = SupersetDashboardTable::getById($dashboardId)->fetchObject();
		if ($ormObject === null)
		{
			return [];
		}

		$dashboard = new Model\Dashboard($ormObject);
		$issues = [];
		foreach ($departments as $department)
		{
			$access = $this->departmentEvaluator->evaluate(
				$dashboard,
				(int)$department['id'],
				(bool)$department['withSubdepartments'],
			);
			if ($access !== null && !$access->isFull())
			{
				$issues[] = $access;
			}
		}

		return $issues;
	}

	/**
	 * @param array<string, int> $scopeValues
	 */
	private function findIssue(int $recipientId, int $dashboardId, array $scopeValues): ?RecipientAccessIssue
	{
		$reason = $this->readinessService
			->checkForSetup($recipientId, $dashboardId, $scopeValues)
			->reason
		;

		if ($reason === null || $reason->reasonClass() !== DigestReasonClass::Settings)
		{
			return null;
		}

		return new RecipientAccessIssue($reason->code(), $reason->setupText());
	}
}
