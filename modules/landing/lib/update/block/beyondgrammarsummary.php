<?php
namespace Bitrix\Landing\Update\Block;

use Bitrix\Landing\Block as BlockCore;
use Bitrix\Landing\Block\ContentRenderer;
use Bitrix\Landing\Debug;
use Bitrix\Landing\Internals\BlockTable;
use Bitrix\Landing\UpdateBlock;
use Bitrix\Main\Update\Stepper;

/**
 * Closing summary of a migration of saved block instances: once the queue of
 * \Bitrix\Landing\Update\Block is drained, walks the table chunk by chunk and writes one record of
 * how many instances still hold php beyond the render grammar, by code. The migration binds it next
 * to its registration; the stepper of the queue itself knows nothing of it.
 */
class BeyondGrammarSummary extends Stepper
{
	/**
	 * Delay of the first step after the bind, in seconds.
	 */
	private const BIND_DELAY = 900;

	/**
	 * Period of a step while the queue still drains, in seconds: the check is cheap, but there is
	 * no point in making it on every hit of the agents.
	 */
	private const WAIT_PERIOD = 600;

	/**
	 * How long the summary waits for the queue, in seconds: a queue stuck for weeks would hold the
	 * agent forever, while the record of what is left beyond the grammar is useful all the same.
	 */
	private const WAIT_LIMIT = 30 * 86400;

	/**
	 * Opening of the record of the summary, whether the walk ended or failed.
	 */
	private const SUMMARY_HEAD = 'landing block update migration is finished';

	/**
	 * Audit type of the record of the summary.
	 */
	private const SUMMARY_LOG_TYPE = 'LANDING_UPDATE_BEYOND_GRAMMAR';

	/**
	 * Rows of the table one step steps over.
	 */
	private const DEBT_CHUNK = 500;

	/**
	 * Upper bound of the whole walk, in rows stepped over: the walk of a huge table is cut off
	 * instead of running for the rest of the update.
	 */
	private const DEBT_MAX_ROWS = 100000;

	/**
	 * Codes the summary names one by one; the rest of them is counted, not named.
	 */
	private const DEBT_TOP_CODES = 20;

	/**
	 * Key of the option of the stepper which holds the state of the walk.
	 */
	private const OPTION_STATE = 'beyondGrammar';

	/**
	 * Target module for stepper.
	 * @var string
	 */
	protected static $moduleId = 'landing';

	/**
	 * Binds the summary to the end of the queue of the block migration.
	 * @return void
	 */
	public static function register(): void
	{
		static::bind(self::BIND_DELAY);
	}

	/**
	 * One step: waits while the queue of the migration drains, then walks one chunk of the table;
	 * the last chunk writes the record. A failed walk ends the stepper all the same: the record of
	 * the finished migration is what an operator looks for, not an agent stuck on it.
	 * @param array &$option Option of the stepper, carried from one step to the next one.
	 * @return bool
	 */
	public function execute(array &$option): bool
	{
		$state = self::makeState(is_array($option[self::OPTION_STATE] ?? null) ? $option[self::OPTION_STATE] : []);

		if ($state['waitSince'] === 0)
		{
			$state['waitSince'] = time();
		}
		if (self::isQueueBusy() && time() - $state['waitSince'] < self::WAIT_LIMIT)
		{
			$option[self::OPTION_STATE] = $state;
			self::delayNextStep();

			return self::CONTINUE_EXECUTION;
		}

		try
		{
			$state = self::walkChunk($state);
		}
		catch (\Throwable $e)
		{
			Debug::log(
				0,
				self::SUMMARY_HEAD . '; summary walk failed: ' . $e->getMessage(),
				self::SUMMARY_LOG_TYPE
			);

			return self::FINISH_EXECUTION;
		}

		if (!$state['finished'])
		{
			$option[self::OPTION_STATE] = $state;
			$option['steps'] = $state['scanned'];

			return self::CONTINUE_EXECUTION;
		}

		Debug::log(0, self::describe($state), self::SUMMARY_LOG_TYPE);

		return self::FINISH_EXECUTION;
	}

	/**
	 * The debt read while a code is still queued is not the final one; a row of any migration
	 * counts, as the queue is shared and its rows are not tagged by the migration they belong to.
	 * @return bool
	 */
	private static function isQueueBusy(): bool
	{
		return (bool)UpdateBlock::getList([
			'select' => [
				'ID',
			],
			'limit' => 1,
		])->fetch();
	}

	/**
	 * Spaces out the steps of waiting; Stepper sets the period of the agent itself only for a step
	 * which ran too long.
	 * @return void
	 */
	private static function delayNextStep(): void
	{
		global $pPERIOD;

		$pPERIOD = self::WAIT_PERIOD;
	}

	/**
	 * The state travels through the option of the stepper, so every field of it is read back the
	 * way the walk wrote it and never by what the option happens to hold.
	 * @param array $state State of the walk.
	 * @return array
	 */
	private static function makeState(array $state = []): array
	{
		return [
			'counts' => is_array($state['counts'] ?? null) ? $state['counts'] : [],
			'scanned' => (int)($state['scanned'] ?? 0),
			'lastId' => (int)($state['lastId'] ?? 0),
			'truncated' => (bool)($state['truncated'] ?? false),
			'finished' => (bool)($state['finished'] ?? false),
			'waitSince' => (int)($state['waitSince'] ?? 0),
		];
	}

	/**
	 * Counts the instances beyond the render grammar by their code over one chunk of the primary key:
	 * only rows holding a php tag reach analyze, while the bound of the walk counts the rows it
	 * stepped over, matching or not.
	 * @param array $state State of the walk, as the previous chunk left it.
	 * @return array State after the chunk.
	 */
	private static function walkChunk(array $state): array
	{
		$state = self::makeState($state);
		if ($state['finished'])
		{
			return $state;
		}

		$ids = self::readIdChunk($state['lastId']);
		if (empty($ids))
		{
			$state['finished'] = true;

			return $state;
		}

		$chunkEnd = (int)end($ids);
		$state['scanned'] += count($ids);

		$counts = self::countChunk(new ContentRenderer(), $state['lastId'], $chunkEnd);
		foreach ($counts as $code => $count)
		{
			$state['counts'][$code] = ($state['counts'][$code] ?? 0) + $count;
		}

		$state['lastId'] = $chunkEnd;

		if (count($ids) < self::DEBT_CHUNK)
		{
			$state['finished'] = true;
		}
		elseif ($state['scanned'] >= self::DEBT_MAX_ROWS)
		{
			$state['truncated'] = true;
			$state['finished'] = true;
		}

		return $state;
	}

	/**
	 * Reads the next chunk of the primary key itself, so that the walk moves on by the rows of the
	 * table and not by the rare rows which match the content of a block.
	 * @param int $lastId Id the previous chunk ended on.
	 * @return array Ids of the chunk, in ascending order.
	 */
	private static function readIdChunk(int $lastId): array
	{
		$ids = [];
		$rows = BlockTable::query()
			->setSelect(['ID'])
			->where('ID', '>', $lastId)
			->setOrder(['ID' => 'ASC'])
			->setLimit(self::DEBT_CHUNK)
			->exec()
		;
		while ($row = $rows->fetch())
		{
			$ids[] = (int)$row['ID'];
		}

		return $ids;
	}

	/**
	 * A deleted row never reaches a page, and a repo block is printed as the html it is: neither is
	 * a debt of the migration.
	 * @param ContentRenderer $renderer Renderer of the walk.
	 * @param int $lastId Id the previous chunk ended on.
	 * @param int $chunkEnd Id this chunk ends on.
	 * @return array Counts of the chunk by code.
	 */
	private static function countChunk(ContentRenderer $renderer, int $lastId, int $chunkEnd): array
	{
		$counts = [];
		$rows = BlockTable::query()
			->setSelect(['CODE', 'CONTENT'])
			->where('ID', '>', $lastId)
			->where('ID', '<=', $chunkEnd)
			->where('DELETED', 'N')
			->whereLike('CONTENT', '%<?%')
			->exec()
		;
		while ($row = $rows->fetch())
		{
			$code = (string)$row['CODE'];
			if (preg_match(BlockCore::REPO_MASK, $code))
			{
				continue;
			}

			if ($renderer->analyze((string)$row['CONTENT']) === ContentRenderer::VERDICT_OUT_OF_GRAMMAR)
			{
				$counts[$code] = ($counts[$code] ?? 0) + 1;
			}
		}

		return $counts;
	}

	/**
	 * One line, so that an operator sees at a glance whether the migration left anything behind and
	 * which blocks that was.
	 * @param array $state State of the finished walk.
	 * @return string
	 */
	private static function describe(array $state): string
	{
		$counts = is_array($state['counts'] ?? null) ? $state['counts'] : [];
		arsort($counts);
		$named = array_slice($counts, 0, self::DEBT_TOP_CODES, true);

		$parts = [];
		foreach ($named as $code => $count)
		{
			$parts[] = $code . '=' . $count;
		}

		$unnamed = count($counts) - count($named);
		if ($unnamed > 0)
		{
			$parts[] = 'and ' . $unnamed . ' more codes';
		}

		return self::SUMMARY_HEAD
			. '; instances beyond render grammar: ' . array_sum($counts)
			. '; scanned: ' . ($state['scanned'] ?? 0) . (($state['truncated'] ?? false) ? ' (cut off)' : '')
			. '; by code: ' . ($parts ? implode(', ', $parts) : 'none');
	}
}
