<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation\Step;

use Bitrix\Landing\AI\SiteBuilder\Html\AiSiteAnchorContract;
use Bitrix\Landing\Copilot\Generation\Step\Base\TaskStep;
use Bitrix\Landing\Block;
use Bitrix\Landing\Copilot\Generation\GenerationException;
use Bitrix\Landing\Copilot\Generation\Scenario\CreateAiSiteState;
use Bitrix\Landing\Copilot\Generation\Type\GenerationErrors;
use Bitrix\Landing\Internals\BlockTable;
use Bitrix\Landing\Landing;
use Bitrix\Landing\Rights;

class TaskPrepareCreatedHtmlBlocksMarkup extends TaskStep
{
	private const AI_BLOCK_ANCHOR_PREFIX = '#ai-site-block-';

	/**
	 * Fields required for safe Block::save() after content replacement.
	 * @var list<string>
	 */
	private const BLOCK_SELECT_FIELDS = [
		'ID',
		'LID',
		'CODE',
		'SORT',
		'ACTIVE',
		'DELETED',
		'DESIGNED',
		'ACCESS',
		'ANCHOR',
		'CONTENT',
		'ASSETS',
	];

	public function execute(): bool
	{
		parent::execute();

		$landingId = $this->resolveLandingId();

		$rightsBefore = Rights::isOn();
		Rights::setGlobalOff();
		try
		{
			$blocks = $this->loadOrderedBlocks($landingId);
			$orderToBlockId = $this->resolveOrderToBlockIdMap($blocks);
			if ($orderToBlockId === [])
			{
				return true;
			}

			$siteMap = AiSiteAnchorContract::buildSiteMap(CreateAiSiteState::getStructure($this->generation));
			if ($this->updateBlocksMarkup($blocks, $orderToBlockId, $siteMap))
			{
				$siteId = max(0, (int)($this->siteData->getSiteId() ?? 0));
				CreateAiSiteState::mergeCreationJournal($this->generation, [
					'siteId' => $siteId,
					'landingId' => $landingId,
					'stage' => 'created_markup_prepared',
					'lastSuccessfulStep' => max(0, (int)($this->stepId ?? 130)),
					'recoverablePartial' => $siteId > 0 || $landingId > 0,
				]);
				$this->changed = true;
			}
		}
		finally
		{
			if ($rightsBefore)
			{
				Rights::setGlobalOn();
			}
		}

		return true;
	}

	private function resolveLandingId(): int
	{
		$landingId = (int)$this->siteData->getLandingId();
		if ($landingId <= 0)
		{
			throw new GenerationException(
				GenerationErrors::dataValidation,
				'Landing is missing for post-creation HTML block preparation.',
			);
		}

		$landing = Landing::createInstance($landingId, [
			'check_permissions' => false,
		]);
		if ($landing->exist())
		{
			return $landingId;
		}

		throw new GenerationException(
			GenerationErrors::dataValidation,
			'Landing not found for post-creation HTML block preparation.',
		);
	}

	/**
	 * @param list<Block> $blocks
	 * @return array<int, int>
	 */
	private function resolveOrderToBlockIdMap(array $blocks): array
	{
		$orderToBlockId = $this->buildOrderToBlockIdMapFromState();
		if ($orderToBlockId !== [])
		{
			return $orderToBlockId;
		}

		// Fallback for atypical runs without populated CreateAiSiteState html blocks.
		return $this->buildOrderToBlockIdMap($blocks);
	}

	/**
	 * @param list<Block> $blocks
	 * @return array<int, int>
	 */
	private function buildOrderToBlockIdMap(array $blocks): array
	{
		$map = [];
		$order = 1;
		foreach ($blocks as $block)
		{
			$blockId = (int)$block->getId();
			if ($blockId <= 0)
			{
				continue;
			}

			$map[$order] = $blockId;
			$order++;
		}

		return $map;
	}

	/**
	 * Build deterministic mapping by generated block position from CreateAiSiteState.
	 * @return array<int, int>
	 */
	private function buildOrderToBlockIdMapFromState(): array
	{
		$items = [];
		foreach (CreateAiSiteState::getHtmlBlocks($this->generation) as $item)
		{
			$position = (int)($item['position'] ?? -1);
			$blockId = (int)($item['blockId'] ?? 0);
			if ($position < 0 || $blockId <= 0)
			{
				continue;
			}

			$items[$position] = $blockId;
		}

		if ($items === [])
		{
			return [];
		}

		ksort($items);

		$map = [];
		foreach ($items as $position => $blockId)
		{
			$map[((int)$position) + 1] = $blockId;
		}

		return $map;
	}

	/**
	 * @return list<Block>
	 */
	private function loadOrderedBlocks(int $landingId): array
	{
		$draftBlocks = $this->loadOrderedBlocksByPublicFlag($landingId, 'N');
		if ($draftBlocks !== [])
		{
			return $draftBlocks;
		}

		return $this->loadOrderedBlocksByPublicFlag($landingId, 'Y');
	}

	/**
	 * @return list<Block>
	 */
	private function loadOrderedBlocksByPublicFlag(int $landingId, string $publicFlag): array
	{
		$blocks = [];

		$res = BlockTable::getList([
			'select' => self::BLOCK_SELECT_FIELDS,
			'filter' => [
				'LID' => $landingId,
				'=DELETED' => 'N',
				'=PUBLIC' => $publicFlag,
			],
			'order' => [
				'SORT' => 'ASC',
				'ID' => 'ASC',
			],
		]);

		while ($row = $res->fetch())
		{
			$blockId = (int)($row['ID'] ?? 0);
			if ($blockId <= 0)
			{
				continue;
			}

			$block = new Block($blockId, $row);
			if ($block->exist())
			{
				$blocks[] = $block;
			}
		}

		return $blocks;
	}

	/**
	 * @param list<Block> $blocks
	 * @param array<int, int> $orderToBlockId
	 * @param list<array{position: int, navigation_title: string, anchor: string}> $siteMap
	 */
	private function updateBlocksMarkup(array $blocks, array $orderToBlockId, array $siteMap): bool
	{
		$changed = false;

		foreach ($blocks as $block)
		{
			$content = (string)$block->getContent();
			if ($content === '' || !str_contains($content, self::AI_BLOCK_ANCHOR_PREFIX))
			{
				continue;
			}

			$preparedContent = $this->replaceAiAnchorsWithBlockIds($content, $orderToBlockId, $siteMap);
			if ($preparedContent === $content)
			{
				continue;
			}

			$this->saveBlockContent($block, $preparedContent);

			$changed = true;
		}

		return $changed;
	}

	private function saveBlockContent(Block $block, string $content): void
	{
		$block->saveContent($content);
		if ($block->save())
		{
			return;
		}

		throw new GenerationException(
			GenerationErrors::dataValidation,
			$this->extractBlockSaveErrorMessage($block),
		);
	}

	/**
	 * @param array<int, int> $orderToBlockId
	 * @param list<array{position: int, navigation_title: string, anchor: string}> $siteMap
	 */
	private function replaceAiAnchorsWithBlockIds(string $content, array $orderToBlockId, array $siteMap = []): string
	{
		return AiSiteAnchorContract::normalizeCreatedAnchors($content, $siteMap, $orderToBlockId);
	}

	private function extractBlockSaveErrorMessage(Block $block): string
	{
		return 'Failed to save block content during post-creation HTML preparation.';
	}
}
