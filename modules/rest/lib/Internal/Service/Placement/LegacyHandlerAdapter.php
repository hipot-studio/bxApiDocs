<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Service\Placement;

use Bitrix\Rest\Api\UserFieldType;
use Bitrix\Rest\AppTable;
use Bitrix\Rest\Internal\Access\App\AppBatchAccessChecker;
use Bitrix\Rest\Internal\Access\App\Model\AppModel;
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
		?int $userId = null,
	): array
	{
		$accessibleEmbeddingIds = array_fill_keys($accessibleEmbeddingIds, true);
		$isApplicationOnlyPlacement = $this->applicationOnlyPlacements->contains($placement);
		$batchChecker = $this->createBatchChecker($rows, HandlerReader::resolveUserId($userId));
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
			elseif (!$this->isApplicationVisible($row, $placement, $skipInstallCheck, $batchChecker))
			{
				continue;
			}

			unset($row['ACTIVE']);
			unset($row['APP_ACCESS_DATA']);
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
		$batchChecker = $this->createBatchChecker($rows, $userId);
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
			elseif (!$this->isApplicationAccessible(
				$row,
				$placement,
				$userId,
				$userAccessCodes,
				$isAdmin,
				$batchChecker,
			))
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

	private function isApplicationVisible(
		array $row,
		string $placement,
		bool $skipInstallCheck,
		?AppBatchAccessChecker $batchChecker,
	): bool
	{
		if (!$this->isApplicationActive($row))
		{
			return false;
		}
		if (!$skipInstallCheck && empty($row['INSTALLED']))
		{
			return false;
		}

		if ($placement === UserFieldType::PLACEMENT_UF_TYPE)
		{
			return true;
		}

		if (array_key_exists('APP_ACCESS_DATA', $row))
		{
			if (!is_array($row['APP_ACCESS_DATA']) || $batchChecker === null)
			{
				return false;
			}

			$app = AppModel::createFromAccessData($row['APP_ACCESS_DATA']);

			return $skipInstallCheck
				? $batchChecker->canAccessBeforeInstallationCompleted($app)
				: $batchChecker->canAccess($app);
		}

		return CRestUtil::checkAppAccess(
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
		?AppBatchAccessChecker $batchChecker,
	): bool
	{
		if (
			!$this->isApplicationActive($row)
			|| !$this->isInstalled($row['INSTALLED'] ?? null)
		)
		{
			return false;
		}

		if ($placement === UserFieldType::PLACEMENT_UF_TYPE)
		{
			return true;
		}
		if (array_key_exists('APP_ACCESS_DATA', $row))
		{
			return is_array($row['APP_ACCESS_DATA'])
				&& $batchChecker !== null
				&& $batchChecker->canAccess(AppModel::createFromAccessData($row['APP_ACCESS_DATA']));
		}

		$appAccess = (string)($row['APP_ACCESS'] ?? '');
		if ($appAccess === '')
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

	private function createBatchChecker(array $rows, int $userId): ?AppBatchAccessChecker
	{
		$apps = [];
		foreach ($rows as $row)
		{
			$data = $row['APP_ACCESS_DATA'] ?? null;
			if (is_array($data))
			{
				$apps[$data['ID']] = $data;
			}
		}

		return $apps === [] ? null : new AppBatchAccessChecker($userId, array_values($apps));
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
