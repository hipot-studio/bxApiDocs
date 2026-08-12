<?php
declare(strict_types=1);

namespace Bitrix\Landing\Landing;

use Bitrix\Landing\Block;
use Bitrix\Landing\Landing;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

class BlockOrderService
{
	public const ERROR_SOURCE_NOT_FOUND = 'MOVE_BLOCK_SOURCE_NOT_FOUND_AT_APPLY';
	public const ERROR_TARGET_NOT_FOUND = 'MOVE_BLOCK_TARGET_NOT_FOUND_AT_APPLY';
	public const ERROR_UNSUPPORTED_PLACEMENT = 'MOVE_BLOCK_UNSUPPORTED_PLACEMENT';
	public const ERROR_NO_POSITION_CHANGE = 'MOVE_BLOCK_NO_POSITION_CHANGE';
	public const ERROR_ORDER_MISMATCH = 'MOVE_BLOCK_ORDER_MISMATCH';
	public const ERROR_APPLY_FAILED = 'MOVE_BLOCK_APPLY_FAILED';

	private const SORT_STEP = 500;

	public function move(Landing $landing, int $sourceBlockId, array $placement): Result
	{
		$result = new Result();
		$sourceBlockId = max(0, $sourceBlockId);
		if ($sourceBlockId <= 0)
		{
			$result->addError(new Error(self::ERROR_SOURCE_NOT_FOUND, self::ERROR_SOURCE_NOT_FOUND));

			return $result;
		}

		$blocks = $this->resolveOrderedBlocks($landing);
		if (!isset($blocks[$sourceBlockId]))
		{
			$result->addError(new Error(self::ERROR_SOURCE_NOT_FOUND, self::ERROR_SOURCE_NOT_FOUND));

			return $result;
		}

		$placement = $this->normalizePlacement($placement);
		if ($placement === null)
		{
			$result->addError(new Error(self::ERROR_UNSUPPORTED_PLACEMENT, self::ERROR_UNSUPPORTED_PLACEMENT));

			return $result;
		}

		$currentOrder = array_keys($blocks);
		$remainingOrder = array_values(array_filter(
			$currentOrder,
			static fn(int $blockId): bool => $blockId !== $sourceBlockId,
		));
		$fromIndex = array_search($sourceBlockId, $currentOrder, true);
		if (!is_int($fromIndex))
		{
			$result->addError(new Error(self::ERROR_SOURCE_NOT_FOUND, self::ERROR_SOURCE_NOT_FOUND));

			return $result;
		}

		$insertIndex = $this->resolveInsertIndex($remainingOrder, $placement, $result);
		if (!is_int($insertIndex))
		{
			return $result;
		}

		$newOrder = $remainingOrder;
		array_splice($newOrder, $insertIndex, 0, [$sourceBlockId]);
		if ($newOrder === $currentOrder)
		{
			$result->addError(new Error(self::ERROR_NO_POSITION_CHANGE, self::ERROR_NO_POSITION_CHANGE));

			return $result;
		}

		$applyResult = $this->applyResolvedOrder($landing, $blocks, $newOrder);
		if (!$applyResult->isSuccess())
		{
			return $applyResult;
		}

		$result->setData([
			'changed' => true,
			'fromIndex' => $fromIndex,
			'toIndex' => $insertIndex,
			'placement' => $placement,
			'resolvedTargetBlockId' => (int)($placement['blockId'] ?? 0),
			'orderBefore' => $currentOrder,
			'orderAfter' => $newOrder,
			'order' => $newOrder,
		]);

		return $result;
	}

	public function applyOrder(Landing $landing, array $order): Result
	{
		$result = new Result();
		$blocks = $this->resolveOrderedBlocks($landing);
		$currentOrder = array_keys($blocks);
		$normalizedOrder = $this->normalizeOrder($order);
		if ($normalizedOrder === [])
		{
			$result->addError(new Error(self::ERROR_ORDER_MISMATCH, self::ERROR_ORDER_MISMATCH));

			return $result;
		}

		if (
			count($normalizedOrder) !== count($currentOrder)
			|| array_diff($normalizedOrder, $currentOrder) !== []
			|| array_diff($currentOrder, $normalizedOrder) !== []
		)
		{
			$result->addError(new Error(self::ERROR_ORDER_MISMATCH, self::ERROR_ORDER_MISMATCH));

			return $result;
		}

		if ($normalizedOrder === $currentOrder)
		{
			$result->addError(new Error(self::ERROR_NO_POSITION_CHANGE, self::ERROR_NO_POSITION_CHANGE));

			return $result;
		}

		$applyResult = $this->applyResolvedOrder($landing, $blocks, $normalizedOrder);
		if (!$applyResult->isSuccess())
		{
			return $applyResult;
		}

		$result->setData([
			'changed' => true,
			'order' => $normalizedOrder,
		]);

		return $result;
	}

	/**
	 * @return array<int, Block>
	 */
	private function resolveOrderedBlocks(Landing $landing): array
	{
		$ordered = [];
		foreach ($landing->getBlocks() as $block)
		{
			if (!$block instanceof Block)
			{
				continue;
			}

			$blockId = (int)$block->getId();
			if ($blockId > 0)
			{
				$ordered[$blockId] = $block;
			}
		}

		return $ordered;
	}

	private function normalizePlacement(array $placement): ?array
	{
		$mode = mb_strtolower(trim((string)($placement['mode'] ?? '')));
		if (!in_array($mode, ['before', 'after', 'prepend', 'append'], true))
		{
			return null;
		}

		$blockId = max(0, (int)($placement['blockId'] ?? 0));
		if (in_array($mode, ['prepend', 'append'], true))
		{
			$blockId = 0;
		}
		elseif ($blockId <= 0)
		{
			return null;
		}

		return [
			'mode' => $mode,
			'blockId' => $blockId,
		];
	}

	private function resolveInsertIndex(array $remainingOrder, array $placement, Result $result): ?int
	{
		$mode = (string)($placement['mode'] ?? '');
		if ($mode === 'prepend')
		{
			return 0;
		}

		if ($mode === 'append')
		{
			return count($remainingOrder);
		}

		$targetBlockId = (int)($placement['blockId'] ?? 0);
		$targetIndex = array_search($targetBlockId, $remainingOrder, true);
		if (!is_int($targetIndex))
		{
			$result->addError(new Error(self::ERROR_TARGET_NOT_FOUND, self::ERROR_TARGET_NOT_FOUND));

			return null;
		}

		return $mode === 'before' ? $targetIndex : $targetIndex + 1;
	}

	/**
	 * @param array<int, Block> $blocks
	 * @param list<int> $order
	 */
	private function applyResolvedOrder(Landing $landing, array $blocks, array $order): Result
	{
		$result = new Result();
		try
		{
			foreach ($order as $index => $blockId)
			{
				$blocks[$blockId]->saveSort($index * self::SORT_STEP);
			}
			$landing->resortBlocks();
		}
		catch (\Throwable)
		{
			$result->addError(new Error(self::ERROR_APPLY_FAILED, self::ERROR_APPLY_FAILED));
		}

		return $result;
	}

	/**
	 * @return list<int>
	 */
	private function normalizeOrder(array $order): array
	{
		$normalized = [];
		foreach ($order as $blockId)
		{
			$blockId = (int)$blockId;
			if ($blockId <= 0 || in_array($blockId, $normalized, true))
			{
				return [];
			}

			$normalized[] = $blockId;
		}

		return $normalized;
	}
}
