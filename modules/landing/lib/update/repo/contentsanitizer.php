<?php

declare(strict_types=1);

namespace Bitrix\Landing\Update\Repo;

use Bitrix\Landing\Block;
use Bitrix\Landing\Internals\BlockTable;
use Bitrix\Landing\Internals\RepoTable;
use Bitrix\Landing\Landing;
use Bitrix\Landing\Rights;
use Bitrix\Landing\Sanitizer;
use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Update\Stepper;

/**
 * Cleans up the block rows the portal stores from before Mantis #252937 was closed.
 *
 * Until the fix, landing.repo.register and the import of a site archive put repo_info.MANIFEST
 * and repo_info.CONTENT into b_landing_repo as they came, and every page built from such a block
 * keeps a copy of that content in b_landing_block. The reading side is closed now, but the
 * content of a repo block is echoed on a public page as is, so the stored rows have to be walked
 * once: the manifest loses the keys which name code to execute, and both contents go through the
 * same Sanitizer the write path uses.
 *
 * The walk has two stages - the repository and then the block instances of repo blocks - and the
 * upper bound of each is fixed when its stage opens, so the rows written while the walk is
 * running do not widen its range: those are already normalized. A row is rewritten only when its
 * value really changes, which is what makes a repeated run free and an interrupted one safe.
 *
 * The repository is read along its primary key. The instances are picked out of b_landing_block by
 * their code, and the only index over that column is (CODE, ID), so this is the order the stage
 * walks and the pair its cursor carries: reading them by ID instead leaves the whole repo% range to
 * be scanned and sorted on every single step.
 */
final class ContentSanitizer extends Stepper
{
	/**
	 * Rows one step reads. One step is one run of the agent, so this is also the pace of the walk;
	 * what holds it back is the memory of a single fetch and the writes one step may fire.
	 */
	public const LIMIT = 200;

	private const STAGE_REPOSITORY = 'repo';
	private const STAGE_BLOCK = 'block';

	/** LIKE reads '_' as any character, so the underscore of a repo code is escaped here. */
	private const REPO_CODE_MASK = 'repo\_%';

	protected static $moduleId = 'landing';

	private ?Sanitizer $sanitizer = null;
	private bool $sanitizerResolved = false;

	public function execute(array &$option): bool
	{
		if (empty($option['stage']))
		{
			$option['steps'] = 0;
			$option['count'] = 0;
			$this->openStage($option, self::STAGE_REPOSITORY, RepoTable::query());
		}

		Rights::setGlobalOff();

		try
		{
			return $option['stage'] === self::STAGE_BLOCK
				? $this->walkBlocks($option)
				: $this->walkRepository($option)
			;
		}
		finally
		{
			Rights::setGlobalOn();
		}
	}

	private function walkRepository(array &$option): bool
	{
		$rows = RepoTable::query()
			->setSelect(['ID', 'MANIFEST', 'CONTENT'])
			->where('ID', '>', (int)$option['lastId'])
			->where('ID', '<=', (int)$option['maxId'])
			->setOrder(['ID' => 'ASC'])
			->setLimit(self::LIMIT)
			->exec()
		;

		$processed = 0;
		$rewritten = false;

		while ($row = $rows->fetch())
		{
			$processed++;
			$option['lastId'] = (int)$row['ID'];

			$fields = $this->buildRepositoryUpdate($row);
			if ($fields !== [])
			{
				RepoTable::update((int)$row['ID'], $fields);
				$rewritten = true;
			}
		}

		$option['steps'] = (int)$option['steps'] + $processed;

		if ($rewritten)
		{
			Block::clearRepositoryCache();
		}

		if ($processed < self::LIMIT)
		{
			$this->openStage($option, self::STAGE_BLOCK, $this->queryRepoBlocks());
		}

		return self::CONTINUE_EXECUTION;
	}

	private function walkBlocks(array &$option): bool
	{
		if ($this->getSanitizer() === null)
		{
			// an instance stores no manifest, so without the filter this stage has nothing to do
			return self::FINISH_EXECUTION;
		}

		$this->adoptCursorOfOlderPass($option);

		$rows = $this->queryBlocksAhead($option)->exec();

		$processed = 0;
		$landingIds = [];

		while ($row = $rows->fetch())
		{
			$processed++;
			$option['lastCode'] = (string)$row['CODE'];
			$option['lastId'] = (int)$row['ID'];

			// the mask of the query is the range of the index, this is the code of a repo block
			if (!preg_match(Block::REPO_MASK, (string)$row['CODE']))
			{
				continue;
			}

			$content = $this->cleanContent((string)$row['CONTENT']);
			if ($content === null)
			{
				continue;
			}

			// through the table and not by filter: prepareChange() keeps the disk links of the block
			BlockTable::update((int)$row['ID'], ['CONTENT' => $content]);
			Block\Cache::clear((int)$row['ID']);
			$landingIds[(int)$row['LID']] = true;
		}

		// one page holds many blocks, and every clear walks b_cache_tag and the disk of the portal
		foreach (array_keys($landingIds) as $landingId)
		{
			Landing\Cache::clear($landingId);
		}

		$option['steps'] = (int)$option['steps'] + $processed;

		return $processed < self::LIMIT ? self::FINISH_EXECUTION : self::CONTINUE_EXECUTION;
	}

	/**
	 * The rows of the stage the cursor has not reached yet, in the order of the index over CODE.
	 */
	private function queryBlocksAhead(array $option): Query
	{
		$query = $this->queryRepoBlocks()
			->setSelect(['ID', 'LID', 'CODE', 'CONTENT'])
			->where('ID', '>', (int)($option['minId'] ?? 0))
			->where('ID', '<=', (int)$option['maxId'])
			->setOrder(['CODE' => 'ASC', 'ID' => 'ASC'])
			->setLimit(self::LIMIT)
		;

		$lastCode = (string)($option['lastCode'] ?? '');
		if ($lastCode !== '')
		{
			$query->where(
				Query::filter()
					->logic('or')
					->where('CODE', '>', $lastCode)
					->where(
						Query::filter()
							->where('CODE', $lastCode)
							->where('ID', '>', (int)$option['lastId'])
					)
			);
		}

		return $query;
	}

	/**
	 * A stage which was opened before the cursor moved onto (CODE, ID) says how far the walk got
	 * along the ID order alone. Everything up to that ID is done, so it becomes the lower bound of
	 * what is left and the cursor opens over it: no row of the stage is read twice or skipped.
	 */
	private function adoptCursorOfOlderPass(array &$option): void
	{
		if (isset($option['lastCode']))
		{
			return;
		}

		$option['minId'] = (int)($option['lastId'] ?? 0);
		$option['lastCode'] = '';
		$option['lastId'] = 0;
	}

	/**
	 * Fields of the row which have to be rewritten, an empty array when the row is already clean.
	 */
	private function buildRepositoryUpdate(array $row): array
	{
		$fields = [];

		$manifest = $this->cleanManifest((string)$row['MANIFEST']);
		if ($manifest !== null)
		{
			$fields['MANIFEST'] = $manifest;
		}

		$content = $this->cleanContent((string)$row['CONTENT']);
		if ($content !== null)
		{
			$fields['CONTENT'] = $content;
		}

		return $fields;
	}

	/**
	 * Drops the manifest keys Block::getAsset() turns into a path to include and
	 * Block::createFromRepository() calls. Returns null when there is nothing to drop.
	 */
	private function cleanManifest(string $stored): ?string
	{
		$manifest = unserialize($stored, ['allowed_classes' => false]);
		if (!is_array($manifest) || serialize($manifest) !== $stored)
		{
			// a row the decode does not reproduce is left to the module instead of being rebuilt
			return null;
		}

		$cleaned = $manifest;
		unset($cleaned['callbacks']);
		if (isset($cleaned['assets']) && is_array($cleaned['assets']))
		{
			unset($cleaned['assets']['class']);
		}

		return $cleaned === $manifest ? null : serialize($cleaned);
	}

	/**
	 * Returns the sanitized markup, or null when the row must be left as it is. Legitimate markup
	 * alone raises the flag of the Sanitizer - a plain <svg> does - so what decides here is the
	 * value: nothing is rewritten unless the filter had something to take out of it.
	 */
	private function cleanContent(string $stored): ?string
	{
		$sanitizer = $this->getSanitizer();
		if ($sanitizer === null)
		{
			return null;
		}

		$bad = false;
		$content = (string)$sanitizer->sanitizeText($stored, $bad);

		if (!$bad || $content === $stored || $content === '')
		{
			return null;
		}

		return $content;
	}

	/**
	 * Null on a portal without the security module: there the Sanitizer runs no filter at all and
	 * its reverse pass alone would only restore the tags an earlier pass had split.
	 */
	private function getSanitizer(): ?Sanitizer
	{
		if (!$this->sanitizerResolved)
		{
			$this->sanitizerResolved = true;
			$this->sanitizer = Loader::includeModule('security') ? new Sanitizer() : null;
		}

		return $this->sanitizer;
	}

	private function queryRepoBlocks(): Query
	{
		return BlockTable::query()->whereLike('CODE', self::REPO_CODE_MASK);
	}

	/**
	 * Opens a stage over the rows which exist right now: its upper bound is the last row of the
	 * table at this moment, and everything written later comes normalized already.
	 */
	private function openStage(array &$option, string $stage, Query $query): void
	{
		$bounds = $query
			->registerRuntimeField(new ExpressionField('MAX_ID', 'MAX(%s)', 'ID'))
			->registerRuntimeField(new ExpressionField('CNT', 'COUNT(%s)', 'ID'))
			->setSelect(['MAX_ID', 'CNT'])
			->exec()
			->fetch()
		;

		$option['stage'] = $stage;
		$option['lastCode'] = '';
		$option['lastId'] = 0;
		$option['minId'] = 0;
		$option['maxId'] = (int)($bounds['MAX_ID'] ?? 0);
		$option['count'] = (int)($option['count'] ?? 0) + (int)($bounds['CNT'] ?? 0);
	}
}
