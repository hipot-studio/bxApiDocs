<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Digest\Service;

use Bitrix\BIConnector\Access\Permission\PermissionDictionary;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Internal\Digest\Dto\DashboardSnapshotDto;
use Bitrix\BIConnector\Internal\Digest\Provider\DashboardGrantingAccessCodeProvider;
use Bitrix\Bitrix24\Feature;
use Bitrix\Main\Loader;
use Bitrix\Main\UserAccessTable;

/**
 * Tells which of the digest recipients can view the report, answered for all of them at once.
 *
 * With the rights feature on, the rules of {@see \Bitrix\BIConnector\Access\AccessController} come down to
 * holding a code a role granting the right on a group of the report is related to, or the code of an
 * administrators group. The right is the view right for a published report and the edit right for a draft,
 * as {@see \Bitrix\BIConnector\Access\Rule\DashboardViewRule} decides. One query per chunk of people answers
 * that, the way {@see DepartmentDashboardAccessEvaluator} answers it for a department, instead of a controller
 * with its own queries per person. With the feature off the rules let everybody through.
 *
 * Left open for extension so tests can switch the rights feature off.
 */
class DigestRecipientViewFilter
{
	private const USER_CODE_PREFIX = 'user_';
	private const CHUNK_SIZE = 500;
	private const IGNORED_PROVIDER_ID = 'imchat';

	private readonly DashboardGrantingAccessCodeProvider $accessCodeProvider;

	public function __construct(?DashboardGrantingAccessCodeProvider $accessCodeProvider = null)
	{
		$this->accessCodeProvider = $accessCodeProvider ?? new DashboardGrantingAccessCodeProvider();
	}

	/**
	 * @param string[] $recipients User codes as {@see DigestRecipientExpander} gives them.
	 *
	 * @return string[] The codes of the people who can view the report, in the given order.
	 */
	public function filter(array $recipients, DashboardSnapshotDto $dashboard): array
	{
		$userIds = [];
		foreach ($recipients as $code)
		{
			$userId = self::toUserId($code);
			if ($userId > 0)
			{
				$userIds[$userId] = $userId;
			}
		}

		if ($userIds === [])
		{
			return [];
		}

		$viewerIds = array_flip($this->findViewerIds(array_values($userIds), $dashboard));

		return array_values(array_filter(
			$recipients,
			static fn($code): bool => isset($viewerIds[self::toUserId($code)]),
		));
	}

	protected function isRightsFeatureEnabled(): bool
	{
		if (!Loader::includeModule('bitrix24'))
		{
			return true;
		}

		return Feature::isFeatureEnabled('bi_constructor_rights');
	}

	/**
	 * @param int[] $userIds
	 *
	 * @return int[]
	 */
	private function findViewerIds(array $userIds, DashboardSnapshotDto $dashboard): array
	{
		if (!$this->isRightsFeatureEnabled())
		{
			return $userIds;
		}

		$relationCodes = $this->accessCodeProvider->getRoleRelationCodes(
			$dashboard->id,
			$dashboard->status === SupersetDashboardTable::DASHBOARD_STATUS_DRAFT
				? PermissionDictionary::BIC_DASHBOARD_EDIT
				: PermissionDictionary::BIC_DASHBOARD_VIEW
			,
		);
		$grantingCodes = array_values(array_unique([
			...$relationCodes,
			...$this->accessCodeProvider->getAdminAccessCodes(),
		]));

		$viewerIds = [];
		foreach (array_chunk($userIds, self::CHUNK_SIZE) as $chunk)
		{
			$rows = UserAccessTable::query()
				->setSelect(['USER_ID'])
				->setDistinct()
				->whereIn('USER_ID', $chunk)
				->whereIn('ACCESS_CODE', $grantingCodes)
				->whereNot('PROVIDER_ID', self::IGNORED_PROVIDER_ID)
				->fetchAll()
			;
			foreach ($rows as $row)
			{
				$viewerIds[] = (int)$row['USER_ID'];
			}
		}

		return $viewerIds;
	}

	private static function toUserId(mixed $code): int
	{
		if (!is_string($code) || !str_starts_with($code, self::USER_CODE_PREFIX))
		{
			return 0;
		}

		return (int)substr($code, strlen(self::USER_CODE_PREFIX));
	}
}
