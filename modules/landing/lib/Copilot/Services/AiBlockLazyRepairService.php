<?php

declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Services;

use Bitrix\Landing\Block;
use Bitrix\Landing\Copilot\Services\HtmlBlock\CoverageRepairer;
use Bitrix\Landing\Copilot\Services\HtmlBlock\NodeTagger;
use Bitrix\Landing\Copilot\Services\Manifest\Builder;
use Bitrix\Landing\Copilot\Services\Manifest\RepoManifestMerger;
use Bitrix\Landing\Landing;
use Bitrix\Landing\Repo;

/**
 * Lazy additive repair of ai repo blocks on editor render: applies current tagging
 * rules to the repo template and the draft block copy, stamps `taggerVersion`
 * into the repo manifest. Never renumbers existing nodes, never writes history.
 */
class AiBlockLazyRepairService
{
	public function repairIfNeeded(Block $block, Landing $landing): bool
	{
		try
		{
			return $this->repairInternal($block, $landing);
		}
		catch (\Throwable)
		{
			return false;
		}
	}

	private function repairInternal(Block $block, Landing $landing): bool
	{
		$repoId = (int)$block->getRepoId();
		if ($repoId <= 0 || $block->getAccess() < Block::ACCESS_W)
		{
			return false;
		}

		$manifest = $this->getRepoManifest($repoId);
		$types = array_map('mb_strtolower', (array)($manifest['block']['type'] ?? []));
		if (!in_array('ai', $types, true))
		{
			return false;
		}
		if ((int)($manifest['taggerVersion'] ?? 0) >= NodeTagger::VERSION)
		{
			return false;
		}
		if (!$this->isAiSite((int)$landing->getSiteId()))
		{
			return false;
		}

		$repoRow = $this->getRepoRow($repoId);
		if (!$repoRow)
		{
			return false;
		}

		$repairer = new CoverageRepairer();
		$meta = [
			'name' => (string)($repoRow['NAME'] ?? $block->getCode()),
			'sections' => $this->resolveRepoSections((string)($repoRow['SECTIONS'] ?? '')),
		];

		$currentManifest = unserialize((string)($repoRow['MANIFEST'] ?? ''), ['allowed_classes' => false]);
		$currentManifest = is_array($currentManifest) ? $currentManifest : [];

		// repo template is the manifest source of truth
		$repoContent = (string)($repoRow['CONTENT'] ?? '');
		$repairedRepoContent = $repairer->repair($repoContent);
		if ($repairedRepoContent === $repoContent)
		{
			// nothing to repair: only stamp the version to skip this block next time
			$nextManifest = $currentManifest;
			$nextManifest['taggerVersion'] = NodeTagger::VERSION;
			$updateFields = ['MANIFEST' => serialize($nextManifest)];
		}
		else
		{
			$builder = $this->createManifestBuilder();
			$builtManifest = $builder->build($repairedRepoContent, $meta);
			$repairedRepoContent = (string)$builder->getSanitizedHtml();
			$nextManifest = (new RepoManifestMerger())->merge($currentManifest, $builtManifest, $repairedRepoContent);
			$updateFields = ['MANIFEST' => serialize($nextManifest)];
			if ($repairedRepoContent !== '')
			{
				$updateFields['CONTENT'] = $repairedRepoContent;
			}
		}
		if (!$this->updateRepo($repoId, $updateFields))
		{
			return false;
		}

		// draft copy gets the same additive markup, codes follow deterministically
		$draftContent = (string)$block->getContent();
		$repairedDraftContent = $draftContent !== '' ? $repairer->repair($draftContent) : '';
		if ($repairedDraftContent !== '' && $repairedDraftContent !== $draftContent)
		{
			$draftBuilder = $this->createManifestBuilder();
			$draftBuilder->build($repairedDraftContent, $meta);
			$repairedDraftContent = (string)$draftBuilder->getSanitizedHtml();
			if ($repairedDraftContent !== '')
			{
				$this->saveBlockContent($block, $repairedDraftContent);
			}
		}

		$this->clearCaches($repoId, (int)$landing->getId());

		return true;
	}

	protected function getRepoManifest(int $repoId): array
	{
		return (array)Repo::getBlock($repoId);
	}

	protected function getRepoRow(int $repoId): ?array
	{
		$row = Repo::getById($repoId)->fetch();

		return is_array($row) ? $row : null;
	}

	protected function createManifestBuilder(): Builder
	{
		return new Builder();
	}

	protected function updateRepo(int $repoId, array $fields): bool
	{
		return Repo::update($repoId, $fields)->isSuccess();
	}

	protected function saveBlockContent(Block $block, string $content): void
	{
		$block->saveContent($content);
		$block->save();
	}

	protected function isAiSite(int $siteId): bool
	{
		return (new CreateAiSiteChecker())->isSiteCreated($siteId);
	}

	protected function clearCaches(int $repoId, int $landingId): void
	{
		Repo::clearBlockCache($repoId);
		Landing\Cache::clear($landingId);
	}

	private function resolveRepoSections(string $rawSections): array
	{
		$sections = array_values(array_filter(array_map(
			static fn($value) => trim((string)$value),
			explode(',', $rawSections),
		)));

		return $sections !== [] ? $sections : ['ai'];
	}
}
