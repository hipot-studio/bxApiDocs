<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Digest\Service;

use Bitrix\BIConnector\Integration\Superset\Model;
use Bitrix\BIConnector\Internal\Digest\Provider\DigestSetupRightChecker;
use Bitrix\BIConnector\Public\Provider\DigestRecipientAccessProvider;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Who of the chosen recipients will not be sent a digest of a report, and why, asked by the setup form.
 * An empty recipient list is a valid question with an empty answer.
 *
 * The question is open to whoever may set the digest up: the right to create an AI agent and to view the
 * report. How many people of a department lack access is a fact about the BI rights, so the counters are
 * given only to a holder of the right to manage them; anyone else learns the state of the department alone.
 *
 * Only the first {@see self::RECIPIENT_CHECK_LIMIT} people and {@see self::DEPARTMENT_CHECK_LIMIT}
 * departments are checked. The counters say so out loud: an unchecked one left out silently would read as
 * "no claim", which is the very thing this answer exists to prevent. A department with full access, or one
 * that could not be checked, is not mentioned.
 */
final class DigestRecipientAccessCheckService
{
	public const ERROR_ACCESS_DENIED = 'BICONNECTOR_DIGEST_RECIPIENT_ACCESS_DENIED';

	// Thirty recipients keep the measured worst case within two seconds. The response
	// reports truncation, so a hand-made request cannot turn linear checks into a long scan.
	public const RECIPIENT_CHECK_LIMIT = 30;
	// A department costs a few queries of its own, about the price of a person: the same bound holds.
	public const DEPARTMENT_CHECK_LIMIT = 30;

	private readonly DigestSetupRightChecker $rightChecker;

	public function __construct(
		private readonly DigestRecipientAccessProvider $accessProvider,
		?DigestSetupRightChecker $rightChecker = null,
	)
	{
		$this->rightChecker = $rightChecker ?? new DigestSetupRightChecker();
	}

	/**
	 * @param array $recipients Recipient ids as the form sent them.
	 * @param array $scopeValues Scope parameter code => chosen value id, as the form sent them.
	 * @param array $departments list<array{id: int, withSubdepartments: bool}>, as the form sent them.
	 *
	 * @return Result Data: array{
	 *     recipients: list<array{id: int, code: string, text: string}>,
	 *     setupIssues: list<array{code: string, text: string}>,
	 *     requestedCount: int,
	 *     checkedCount: int,
	 *     isTruncated: bool,
	 *     departments: list<array{id: int, state: string, withoutAccess?: int, total?: int}>,
	 *     requestedDepartmentCount: int,
	 *     checkedDepartmentCount: int,
	 *     isDepartmentTruncated: bool,
	 * }
	 */
	public function check(
		Model\Dashboard $dashboard,
		int $userId,
		array $recipients,
		array $scopeValues,
		array $departments,
	): Result
	{
		if (
			$userId <= 0
			|| !$this->rightChecker->canViewDashboard($userId, $dashboard)
			|| !$this->rightChecker->canCreateAgent($userId)
		)
		{
			return (new Result())->addError(new Error('Access denied.', self::ERROR_ACCESS_DENIED));
		}

		$requestedIds = $this->normalizeRecipients($recipients);
		$checkedIds = array_slice($requestedIds, 0, self::RECIPIENT_CHECK_LIMIT);
		[$recipientIssues, $setupIssues] = $this->findRecipientIssues(
			$dashboard,
			$checkedIds,
			$this->normalizeScopeValues($scopeValues),
		);

		$requestedDepartments = $this->normalizeDepartments($departments);
		$checkedDepartments = array_slice($requestedDepartments, 0, self::DEPARTMENT_CHECK_LIMIT);

		return (new Result())->setData([
			'recipients' => $recipientIssues,
			'setupIssues' => $setupIssues,
			'requestedCount' => count($requestedIds),
			'checkedCount' => count($checkedIds),
			'isTruncated' => count($checkedIds) < count($requestedIds),
			'departments' => $this->findDepartmentIssues(
				$dashboard,
				$checkedDepartments,
				$this->rightChecker->canManageRights($userId),
			),
			'requestedDepartmentCount' => count($requestedDepartments),
			'checkedDepartmentCount' => count($checkedDepartments),
			'isDepartmentTruncated' => count($checkedDepartments) < count($requestedDepartments),
		]);
	}

	/**
	 * @param list<int> $recipientIds
	 * @param array<string, int> $scopeValues
	 *
	 * @return array{0: list<array{id: int, code: string, text: string}>, 1: list<array{code: string, text: string}>}
	 */
	private function findRecipientIssues(Model\Dashboard $dashboard, array $recipientIds, array $scopeValues): array
	{
		$recipientIssues = [];
		$setupIssues = [];
		foreach ($this->accessProvider->findIssues($dashboard->getId(), $recipientIds, $scopeValues) as $id => $issue)
		{
			if ($issue === null)
			{
				continue;
			}

			if ($issue->isAboutSetup())
			{
				$setupIssues[$issue->code] = ['code' => $issue->code, 'text' => $issue->text];

				continue;
			}

			$recipientIssues[] = ['id' => $id, 'code' => $issue->code, 'text' => $issue->text];
		}

		return [$recipientIssues, array_values($setupIssues)];
	}

	/**
	 * @param list<array{id: int, withSubdepartments: bool}> $departments
	 *
	 * @return list<array{id: int, state: string, withoutAccess?: int, total?: int}>
	 */
	private function findDepartmentIssues(Model\Dashboard $dashboard, array $departments, bool $withCounts): array
	{
		$issues = [];
		foreach ($this->accessProvider->findDepartmentIssues($dashboard->getId(), $departments) as $access)
		{
			$issue = ['id' => $access->nodeId, 'state' => $access->state];
			if ($withCounts)
			{
				$issue['withoutAccess'] = $access->withoutAccess;
				$issue['total'] = $access->total;
			}

			$issues[] = $issue;
		}

		return $issues;
	}

	/**
	 * @return list<int> Distinct recipient ids, in the order the form named them.
	 */
	private function normalizeRecipients(array $recipients): array
	{
		$ids = [];
		foreach ($recipients as $recipient)
		{
			if (!is_scalar($recipient))
			{
				continue;
			}

			$id = (int)$recipient;
			if ($id > 0)
			{
				$ids[$id] = $id;
			}
		}

		return array_values($ids);
	}

	/**
	 * A department named both with and without its subdepartments is checked with them: that is the wider
	 * set of recipients the run will reach.
	 *
	 * @return list<array{id: int, withSubdepartments: bool}> Distinct departments, in the order the form named them.
	 */
	private function normalizeDepartments(array $departments): array
	{
		$result = [];
		foreach ($departments as $department)
		{
			if (!is_array($department) || !is_scalar($department['id'] ?? null))
			{
				continue;
			}

			$id = (int)$department['id'];
			if ($id <= 0)
			{
				continue;
			}

			$withSubdepartments = $department['withSubdepartments'] ?? false;
			$result[$id] = [
				'id' => $id,
				'withSubdepartments' => ($result[$id]['withSubdepartments'] ?? false)
					|| (is_scalar($withSubdepartments) && filter_var($withSubdepartments, FILTER_VALIDATE_BOOLEAN))
				,
			];
		}

		return array_values($result);
	}

	/**
	 * @return array<string, int>
	 */
	private function normalizeScopeValues(array $scopeValues): array
	{
		$values = [];
		foreach ($scopeValues as $code => $value)
		{
			if (is_scalar($value))
			{
				$values[(string)$code] = (int)$value;
			}
		}

		return $values;
	}
}
