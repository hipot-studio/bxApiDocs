<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation\Step;

use Bitrix\Landing\Copilot\Generation\Step\Base\TaskStep;
use Bitrix\Landing\AI\SiteBuilder\Html\AiSiteAnchorContract;
use Bitrix\Landing\Copilot\Generation\GenerationException;
use Bitrix\Landing\Copilot\Generation\Scenario\CreateAiSiteState;
use Bitrix\Landing\Copilot\Generation\Type\GenerationErrors;
use Bitrix\Landing\Copilot\Services\Manifest\AiBlockTemplateFactory;
use Bitrix\Landing\Landing;
use Bitrix\Landing\Rights;

class TaskCreateHtmlBlocks extends TaskStep
{
	public function execute(): bool
	{
		parent::execute();

		$landingId = $this->resolveLandingId();
		$landing = $this->resolveLandingInstance($landingId);
		$htmlBlocks = CreateAiSiteState::getHtmlBlocks($this->generation);
		if (!$htmlBlocks)
		{
			throw new GenerationException(
				GenerationErrors::dataValidation,
				'No HTML blocks found for CreateAiSite.',
			);
		}

		$activeHtmlBlocks = $this->filterCreatableHtmlBlocks($htmlBlocks);
		if ($activeHtmlBlocks === [])
		{
			throw new GenerationException(
				GenerationErrors::dataValidation,
				'No generated HTML blocks are available for landing insertion after retry/skip handling.',
			);
		}

		$requiredHtmlCount = count($activeHtmlBlocks);
		$readyHtmlCount = $this->countReadyHtmlBlocks($activeHtmlBlocks);
		if ($readyHtmlCount < $requiredHtmlCount)
		{
			throw new GenerationException(
				GenerationErrors::dataValidation,
				sprintf(
					'Not all non-skipped generated HTML blocks are ready for landing insertion (%d/%d).',
					$readyHtmlCount,
					$requiredHtmlCount,
				),
			);
		}

		$this->sortHtmlBlocksByPosition($htmlBlocks);
		$this->sortHtmlBlocksByPosition($activeHtmlBlocks);

		$existingByAnchor = $this->collectExistingBlocksByAnchor($landing);
		$afterId = 0;
		$rightsBefore = Rights::isOn();
		Rights::setGlobalOff();
		try
		{
			foreach ($activeHtmlBlocks as $item)
			{
				$position = (int)($item['position'] ?? 0);
				$index = $this->findHtmlBlockIndexByPosition($htmlBlocks, $position);
				if ($index === null)
				{
					continue;
				}

				$anchor = $this->buildAnchor($position);
				if (isset($existingByAnchor[$anchor]))
				{
					$restoredBlockId = (int)$existingByAnchor[$anchor];
					if ($this->applyHtmlBlockStatePatch($htmlBlocks, $index, [
						'blockId' => $restoredBlockId,
						'anchor' => $anchor,
					]))
					{
						$this->checkpointHtmlBlocksState($htmlBlocks, $position, [
							'blockId' => $restoredBlockId,
							'anchor' => $anchor,
							'status' => 'restored',
						]);
					}
					$afterId = $restoredBlockId;
					continue;
				}

				$existingBlockId = (int)($item['blockId'] ?? 0);
				if ($existingBlockId > 0 && $this->hasExistingLandingBlock($landing, $existingBlockId))
				{
					if ($this->applyHtmlBlockStatePatch($htmlBlocks, $index, [
						'blockId' => $existingBlockId,
					]))
					{
						$this->checkpointHtmlBlocksState($htmlBlocks, $position, [
							'blockId' => $existingBlockId,
							'status' => 'restored',
						]);
					}
					$afterId = $existingBlockId;

					if ($this->trySetBlockAnchor($landing, $existingBlockId, $anchor))
					{
						$this->applyHtmlBlockStatePatch($htmlBlocks, $index, [
							'anchor' => $anchor,
						]);
						$this->checkpointHtmlBlocksState($htmlBlocks, $position, [
							'blockId' => $existingBlockId,
							'anchor' => $anchor,
							'status' => 'anchor_set',
						]);
						$existingByAnchor[$anchor] = $existingBlockId;
					}
					continue;
				}

				$repoId = $this->resolveTemplateRepoId($htmlBlocks, $index, $item, $position);
				$blockId = $this->addTemplateToLanding($landingId, $repoId, $afterId);
				$this->applyHtmlBlockStatePatch($htmlBlocks, $index, [
					'repoId' => $repoId,
					'blockId' => $blockId,
				]);
				$this->checkpointHtmlBlocksState($htmlBlocks, $position, [
					'repoId' => $repoId,
					'blockId' => $blockId,
					'status' => 'block_added',
				]);
				$afterId = $blockId;

				if ($this->trySetBlockAnchor($landing, $blockId, $anchor))
				{
					$this->applyHtmlBlockStatePatch($htmlBlocks, $index, [
						'anchor' => $anchor,
					]);
					$this->checkpointHtmlBlocksState($htmlBlocks, $position, [
						'repoId' => $repoId,
						'blockId' => $blockId,
						'anchor' => $anchor,
						'status' => 'anchor_set',
					]);
					$existingByAnchor[$anchor] = $blockId;
				}
			}
		}
		finally
		{
			if ($rightsBefore)
			{
				Rights::setGlobalOn();
			}
			else
			{
				Rights::setGlobalOff();
			}
		}

		return true;
	}

	private function resolveLandingId(): int
	{
		$landingId = (int)$this->siteData->getLandingId();
		if ($landingId > 0)
		{
			return $landingId;
		}

		throw new GenerationException(
			GenerationErrors::dataValidation,
			'Landing is missing for HTML block creation.',
		);
	}

	protected function resolveLandingInstance(int $landingId): Landing
	{
		$landing = Landing::createInstance($landingId, [
			'check_permissions' => false,
		]);
		if ($landing->exist())
		{
			return $landing;
		}

		throw new GenerationException(
			GenerationErrors::dataValidation,
			'Landing not found for HTML block creation.',
		);
	}

	private function filterCreatableHtmlBlocks(array $htmlBlocks): array
	{
		return array_values(array_filter($htmlBlocks, static function (array $item): bool
		{
			return empty($item['htmlSkipped']);
		}));
	}

	private function countReadyHtmlBlocks(array $htmlBlocks): int
	{
		$readyHtmlCount = 0;
		foreach ($htmlBlocks as $item)
		{
			if (trim((string)($item['html'] ?? '')) !== '')
			{
				$readyHtmlCount++;
			}
		}

		return $readyHtmlCount;
	}

	private function findHtmlBlockIndexByPosition(array $htmlBlocks, int $position): ?int
	{
		foreach ($htmlBlocks as $index => $item)
		{
			if ((int)($item['position'] ?? -1) === $position)
			{
				return $index;
			}
		}

		return null;
	}

	private function sortHtmlBlocksByPosition(array &$htmlBlocks): void
	{
		usort($htmlBlocks, static function (array $left, array $right): int
		{
			return ((int)($left['position'] ?? 0)) <=> ((int)($right['position'] ?? 0));
		});
	}

	protected function createLandingBlock(int $landingId, int $afterId, array $item, int $position): int
	{
		return $this->addTemplateToLanding(
			$landingId,
			$this->createTemplateRepoId($item, $position),
			$afterId,
		);
	}

	private function resolveTemplateRepoId(array &$htmlBlocks, int $index, array $item, int $position): int
	{
		$repoId = max(0, (int)($item['repoId'] ?? 0));
		if ($repoId > 0)
		{
			return $repoId;
		}

		$repoId = $this->createTemplateRepoId($item, $position);
		$this->applyHtmlBlockStatePatch($htmlBlocks, $index, [
			'repoId' => $repoId,
		]);
		$this->checkpointHtmlBlocksState($htmlBlocks, $position, [
			'repoId' => $repoId,
			'status' => 'repo_created',
		]);

		return $repoId;
	}

	protected function createTemplateRepoId(array $item, int $position): int
	{
		$html = trim((string)($item['html'] ?? ''));
		if ($html === '')
		{
			throw new GenerationException(
				GenerationErrors::dataValidation,
				'Generated HTML block payload is empty.',
			);
		}

		$templateRes = $this->createAiBlockTemplateFactory()->createTemplate($html, [
			'name' => $this->buildBlockName($item, $position),
			'sections' => ['ai'],
		]);
		if (!$templateRes->isSuccess())
		{
			throw new GenerationException(
				GenerationErrors::dataValidation,
				'Failed to create template from generated HTML block.',
			);
		}

		$templateData = $templateRes->getResult();
		$repoId = (int)($templateData['repoId'] ?? 0);
		if ($repoId <= 0)
		{
			throw new GenerationException(
				GenerationErrors::dataValidation,
				'Generated template repo ID is missing.',
			);
		}

		return $repoId;
	}

	protected function addTemplateToLanding(int $landingId, int $repoId, int $afterId): int
	{
		$addRes = $this->createAiBlockTemplateFactory()->addToLanding($landingId, $repoId, $afterId);
		if (!$addRes->isSuccess())
		{
			throw new GenerationException(
				GenerationErrors::dataValidation,
				'Failed to add generated HTML block to landing.',
			);
		}

		$blockId = (int)($addRes->getResult()['blockId'] ?? 0);
		if ($blockId <= 0)
		{
			throw new GenerationException(
				GenerationErrors::dataValidation,
				'Generated block ID is missing.',
			);
		}

		return $blockId;
	}

	protected function createAiBlockTemplateFactory(): AiBlockTemplateFactory
	{
		return new AiBlockTemplateFactory();
	}

	protected function collectExistingBlocksByAnchor(Landing $landing): array
	{
		$blocksByAnchor = [];
		foreach ($landing->getBlocks() as $blockId => $block)
		{
			$anchor = trim((string)$block->getLocalAnchor());
			if ($anchor !== '')
			{
				$blocksByAnchor[$anchor] = (int)$blockId;
			}
		}

		return $blocksByAnchor;
	}

	protected function trySetBlockAnchor(Landing $landing, int $blockId, string $anchor): bool
	{
		$block = $landing->getBlockById($blockId);
		if (!$block)
		{
			return false;
		}

		if (!$block->setAnchor($anchor))
		{
			return false;
		}

		$block->save();

		return true;
	}

	protected function hasExistingLandingBlock(Landing $landing, int $blockId): bool
	{
		if ($blockId <= 0)
		{
			return false;
		}

		return $landing->getBlockById($blockId) !== null;
	}

	private function buildAnchor(int $position): string
	{
		return AiSiteAnchorContract::buildLocalAnchor($position);
	}

	private function buildBlockName(array $item, int $position): string
	{
		$spec = isset($item['spec']) && is_array($item['spec']) ? $item['spec'] : [];
		$name = trim((string)($spec['goal'] ?? ''));
		$name = preg_replace('/\s+/u', ' ', $name) ?: $name;
		if ($name === '')
		{
			$name = 'AI block ' . ($position + 1);
		}
		if (mb_strlen($name) > 80)
		{
			$name = rtrim(mb_substr($name, 0, 79)) . '…';
		}

		return $name;
	}

	private function saveHtmlBlocksState(array $htmlBlocks): void
	{
		CreateAiSiteState::setHtmlBlocks($this->generation, $htmlBlocks);
		$this->changed = true;
	}

	private function checkpointHtmlBlocksState(array $htmlBlocks, int $position, array $blockJournalPatch): void
	{
		$siteId = max(0, (int)($this->siteData->getSiteId() ?? 0));
		$landingId = max(0, (int)($this->siteData->getLandingId() ?? 0));

		$this->saveHtmlBlocksState($htmlBlocks);
		CreateAiSiteState::mergeCreationJournal($this->generation, [
			'siteId' => $siteId,
			'landingId' => $landingId,
			'stage' => (string)($blockJournalPatch['status'] ?? 'blocks_created'),
			'lastSuccessfulStep' => max(0, (int)($this->stepId ?? 120)),
			'recoverablePartial' => $siteId > 0 || $landingId > 0,
		]);
		CreateAiSiteState::mergeCreationJournalBlock($this->generation, $position, $blockJournalPatch);
		$this->persistHtmlBlocksState();
	}

	protected function persistHtmlBlocksState(): void
	{
		if (!$this->generation->persistState())
		{
			throw new GenerationException(
				GenerationErrors::dataValidation,
				'Failed to persist generated HTML blocks creation state.',
			);
		}
	}

	private function applyHtmlBlockStatePatch(array &$htmlBlocks, int $index, array $patch): bool
	{
		if (!isset($htmlBlocks[$index]) || $patch === [])
		{
			return false;
		}

		$changed = false;
		foreach ($patch as $field => $value)
		{
			if (($htmlBlocks[$index][$field] ?? null) === $value)
			{
				continue;
			}

			$htmlBlocks[$index][$field] = $value;
			$changed = true;
		}

		return $changed;
	}
}
