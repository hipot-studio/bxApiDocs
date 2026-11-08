<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Service\Placement;

use Bitrix\Rest\Api\UserFieldType;
use Bitrix\Rest\AppTable;
use Bitrix\Rest\Internal\Access\User\Model\RestUserModel;
use Bitrix\Rest\Internal\Service\Embedding\ApplicationOnlyPlacements;
use Bitrix\Rest\Lang;
use Bitrix\Rest\PlacementTable;
use CRestUtil;

final class LegacyHandlerAdapter
{
	public function __construct(
		private readonly LegacyAppIdPresenter $legacyAppIdPresenter = new LegacyAppIdPresenter(),
		private readonly ApplicationOnlyPlacements $applicationOnlyPlacements = new ApplicationOnlyPlacements(),
	)
	{
	}

	public function makeHandlers(
		array $rows,
		string $placement,
		bool $skipInstallCheck,
		array $accessibleEmbeddingIds,
	): array
	{
		$accessibleEmbeddingIds = array_fill_keys($accessibleEmbeddingIds, true);
		$isApplicationOnlyPlacement = $this->applicationOnlyPlacements->contains($placement);
		$result = [];

		foreach ($rows as $row)
		{
			$isEmbedding = $this->isEmbedding($row);
			if ($isEmbedding)
			{
				if (!$this->isEmbeddingVisible($row, $accessibleEmbeddingIds, $isApplicationOnlyPlacement))
				{
					continue;
				}
			}
			elseif (!$this->isApplicationVisible($row, $placement, $skipInstallCheck))
			{
				continue;
			}

			unset($row['ACTIVE']);
			$row = Lang::mergeFromLangAll($row);
			if (empty($row['TITLE']))
			{
				$row['TITLE'] = PlacementTable::getDefaultTitle((int)$row['ID']);
			}
			if ($isEmbedding)
			{
				$row['APP_NAME'] = $row['TITLE'];
			}

			$result[] = $row;
		}

		return $result;
	}

	public function makeAccessibleIds(
		array $rows,
		string $placement,
		int $userId,
		array $accessibleEmbeddingIds,
	): array
	{
		$accessibleEmbeddingIds = array_fill_keys($accessibleEmbeddingIds, true);
		$isApplicationOnlyPlacement = $this->applicationOnlyPlacements->contains($placement);
		$result = [];
		$userAccessCodes = null;
		$isAdmin = null;

		foreach ($rows as $row)
		{
			if ($this->isEmbedding($row))
			{
				if (!$this->isEmbeddingVisible($row, $accessibleEmbeddingIds, $isApplicationOnlyPlacement))
				{
					continue;
				}
			}
			elseif (!$this->isApplicationAccessible($row, $placement, $userId, $userAccessCodes, $isAdmin))
			{
				continue;
			}

			$result[] = (int)$row['ID'];
		}

		return $result;
	}

	private function isEmbeddingVisible(
		array $row,
		array $accessibleEmbeddingIds,
		bool $isApplicationOnlyPlacement,
	): bool
	{
		return !$isApplicationOnlyPlacement && isset($accessibleEmbeddingIds[(int)$row['ID']]);
	}

	private function isApplicationVisible(array $row, string $placement, bool $skipInstallCheck): bool
	{
		if (!$this->isApplicationActive($row))
		{
			return false;
		}
		if (!$skipInstallCheck && empty($row['INSTALLED']))
		{
			return false;
		}

		return $placement === UserFieldType::PLACEMENT_UF_TYPE
			|| CRestUtil::checkAppAccess(
				(int)($row['APP_ID'] ?? 0),
				['ACCESS' => (string)($row['APP_ACCESS'] ?? '')],
			);
	}

	private function isApplicationAccessible(
		array $row,
		string $placement,
		int $userId,
		?array &$userAccessCodes,
		?bool &$isAdmin,
	): bool
	{
		if (
			!$this->isApplicationActive($row)
			|| !$this->isInstalled($row['INSTALLED'] ?? null)
		)
		{
			return false;
		}

		$appAccess = (string)($row['APP_ACCESS'] ?? '');
		if ($placement === UserFieldType::PLACEMENT_UF_TYPE || $appAccess === '')
		{
			return true;
		}

		$rights = explode(',', $appAccess);
		if (in_array('G2', $rights, true))
		{
			return true;
		}

		if ($userAccessCodes === null)
		{
			$userAccessCodes = $userId > 0 ? \CAccess::GetUserCodesArray($userId) : ['G2'];
			if ($userId > 0)
			{
				$userAccessCodes[] = 'AU';
			}
		}

		if (!empty(array_intersect($rights, $userAccessCodes)))
		{
			return true;
		}

		$isAdmin ??= RestUserModel::createFromId($userId)->isAdmin();

		return $isAdmin;
	}

	private function isInstalled(mixed $value): bool
	{
		return $value === true || $value === AppTable::INSTALLED;
	}

	private function isApplicationActive(array $row): bool
	{
		return !array_key_exists('ACTIVE', $row) || $row['ACTIVE'] === AppTable::ACTIVE;
	}

	private function isEmbedding(array $row): bool
	{
		return $this->legacyAppIdPresenter->decodeEmbeddingId((int)($row['APP_ID'] ?? 0)) !== null;
	}
}
