<?php

namespace Bitrix\Landing\History\Action;

use Bitrix\Landing\Block;
use Bitrix\Landing\File;
use Bitrix\Landing\History;
use Bitrix\Landing\Internals\HistoryTable;
use Bitrix\Landing\Markup\Manifest;
use Bitrix\Main\Security\Random;

/**
 * One step = one markup package version. The step keeps the manifest after the change and
 * a link to the previous version: the id of the step that wrote it and its file ids. A single
 * manifest keeps a step of a large package within the history params column.
 * Undo/redo swaps the block manifest - the files of both versions are already stored,
 * nothing is downloaded or re-saved. The step owns releasing the files of the retired
 * version: delete() is the single point called on redo-branch eviction and retention.
 * Steps written before carry both manifests (manifestBefore) and are still executed.
 */
class UpdateMarkupPackageAction extends BaseAction
{
	protected const JS_COMMAND = 'updateMarkupPackage';

	private const ACTION = 'UPDATE_MARKUP_PACKAGE';

	/**
	 * Params of the step for a new block version.
	 * @param string $manifestBefore Manifest column value the version replaces, empty for the first version.
	 * @return array{
	 *     block: int,
	 *     landing: int,
	 *     manifestAfter: string,
	 *     previousStepId: ?int,
	 *     previousFileIds: int[],
	 *     stepUid: string
	 * }
	 */
	public static function buildParams(
		int $blockId,
		int $landingId,
		string $manifestBefore,
		Manifest $manifestAfter
	): array
	{
		$before = Manifest::fromJson($manifestBefore);

		return [
			'block' => $blockId,
			'landing' => $landingId,
			'manifestAfter' => $manifestAfter->toJson(),
			'previousStepId' => $manifestBefore !== ''
				? self::findStepWritingManifest($blockId, $landingId, $manifestBefore)
				: null,
			'previousFileIds' => $before ? $before->getFileIds() : [],
			// the step identifies itself: delete() excludes its own row when collecting alive files
			'stepUid' => Random::getString(12),
		];
	}

	protected function doExecute(bool $undo = true): bool
	{
		$blockId = (int)($this->params['block'] ?? 0);
		if (!$blockId)
		{
			return false;
		}

		$block = new Block($blockId);
		if (!$block->exist())
		{
			return false;
		}

		$manifestJson = $undo ? $this->resolveManifestBefore() : (string)($this->params['manifestAfter'] ?? '');
		if ($manifestJson === null)
		{
			return false;
		}

		return $block->saveMarkupManifest($manifestJson !== '' ? $manifestJson : null);
	}

	public static function enrichParams(array $params): array
	{
		return $params;
	}

	/**
	 * Releases the files of this step: a file is unbound only when neither the current
	 * block version nor any other remaining step of the landing references it.
	 */
	public function delete(): bool
	{
		$blockId = (int)($this->params['block'] ?? 0);
		$landingId = (int)($this->params['landing'] ?? 0);
		if (!$blockId || !$landingId)
		{
			return true;
		}

		$deadIds = self::getStepFileIds($this->params);
		if (!$deadIds)
		{
			return true;
		}

		$aliveIds = [];
		$block = new Block($blockId);
		if ($block->exist())
		{
			$aliveIds = self::getManifestFileIds((string)$block->getMarkupManifest());
		}

		foreach (self::getOtherSteps($landingId, (string)($this->params['stepUid'] ?? '')) as $params)
		{
			$aliveIds = array_merge($aliveIds, self::getStepFileIds($params));
		}

		$obsolete = array_diff($deadIds, array_unique($aliveIds));
		if ($obsolete)
		{
			File::deleteFromBlock($blockId, array_values($obsolete));
		}

		return true;
	}

	/**
	 * The manifest the step replaced: the one written by the previous step; when that step
	 * is gone, a version with the same files kept by another step. Null - the version is lost.
	 */
	private function resolveManifestBefore(): ?string
	{
		if (array_key_exists('manifestBefore', $this->params))
		{
			return (string)$this->params['manifestBefore'];
		}

		$blockId = (int)($this->params['block'] ?? 0);
		$landingId = (int)($this->params['landing'] ?? 0);
		$previousStepId = (int)($this->params['previousStepId'] ?? 0);
		if ($previousStepId > 0)
		{
			$params = self::getStepParams($previousStepId, $landingId);
			if ($params !== null && (int)($params['block'] ?? 0) === $blockId)
			{
				return (string)($params['manifestAfter'] ?? '');
			}
		}

		$previousFileIds = array_values(array_filter(
			array_map('intval', (array)($this->params['previousFileIds'] ?? []))
		));
		if (!$previousFileIds)
		{
			return '';
		}
		sort($previousFileIds);

		foreach (self::getOtherSteps($landingId, (string)($this->params['stepUid'] ?? '')) as $params)
		{
			if ((int)($params['block'] ?? 0) !== $blockId)
			{
				continue;
			}
			foreach (['manifestAfter', 'manifestBefore'] as $key)
			{
				$manifestJson = (string)($params[$key] ?? '');
				$fileIds = self::getManifestFileIds($manifestJson);
				sort($fileIds);
				if ($fileIds && $fileIds === $previousFileIds)
				{
					return $manifestJson;
				}
			}
		}

		return null;
	}

	/**
	 * Id of the latest step of the block whose version is the given manifest.
	 */
	private static function findStepWritingManifest(int $blockId, int $landingId, string $manifestJson): ?int
	{
		$res = HistoryTable::getList([
			'select' => ['ID', 'ACTION_PARAMS'],
			'filter' => [
				'ENTITY_ID' => $landingId,
				'=ENTITY_TYPE' => History::ENTITY_TYPE_LANDING,
				'=ACTION' => self::ACTION,
			],
			'order' => ['ID' => 'DESC'],
		]);
		while ($row = $res->fetch())
		{
			$params = is_array($row['ACTION_PARAMS']) ? $row['ACTION_PARAMS'] : [];
			if (
				(int)($params['block'] ?? 0) === $blockId
				&& (string)($params['manifestAfter'] ?? '') === $manifestJson
			)
			{
				return (int)$row['ID'];
			}
		}

		return null;
	}

	private static function getStepParams(int $stepId, int $landingId): ?array
	{
		$row = HistoryTable::getList([
			'select' => ['ACTION_PARAMS'],
			'filter' => [
				'=ID' => $stepId,
				'ENTITY_ID' => $landingId,
				'=ENTITY_TYPE' => History::ENTITY_TYPE_LANDING,
				'=ACTION' => self::ACTION,
			],
		])->fetch();

		return ($row && is_array($row['ACTION_PARAMS'])) ? $row['ACTION_PARAMS'] : null;
	}

	/**
	 * Params of the markup steps of the landing, except the step with the given uid.
	 * @return \Generator<array>
	 */
	private static function getOtherSteps(int $landingId, string $stepUid): \Generator
	{
		$res = HistoryTable::getList([
			'select' => ['ID', 'ACTION_PARAMS'],
			'filter' => [
				'ENTITY_ID' => $landingId,
				'=ENTITY_TYPE' => History::ENTITY_TYPE_LANDING,
				'=ACTION' => self::ACTION,
			],
		]);
		while ($row = $res->fetch())
		{
			$params = is_array($row['ACTION_PARAMS']) ? $row['ACTION_PARAMS'] : [];
			if ($stepUid !== '' && ($params['stepUid'] ?? '') === $stepUid)
			{
				continue;
			}

			yield $params;
		}
	}

	/**
	 * Files of both versions a step links: the one it wrote and the one it replaced.
	 * @return int[]
	 */
	private static function getStepFileIds(array $params): array
	{
		return array_values(array_unique(array_filter(array_merge(
			self::getManifestFileIds((string)($params['manifestAfter'] ?? '')),
			self::getManifestFileIds((string)($params['manifestBefore'] ?? '')),
			array_map('intval', (array)($params['previousFileIds'] ?? []))
		))));
	}

	/**
	 * @return int[]
	 */
	private static function getManifestFileIds(string $manifestJson): array
	{
		$manifest = Manifest::fromJson($manifestJson);

		return $manifest ? $manifest->getFileIds() : [];
	}
}
