<?php

namespace Bitrix\Search\Cli;

use Symfony\Component\Console;
use Symfony\Component\Console\Input\InputOption;

/**
 * Class ReindexCommand
 *
 * Symfony Console command for performing full or incremental reindexing of search content.
 * This command supports options for full reindex, continuation after interruption,
 * site/module filtering, and clearing search phrase suggestions.
 *
 * @package Bitrix\Search\Cli
 */
class ReindexCommand extends Console\Command\Command
{
	/**
	 * Configures the command options for the reindexing process.
	 *
	 * @return void
	 */
	protected function configure()
	{
		$this
			->setName('search:reindex')
			->setDescription('Reindexing site.')
			->addOption('full', null, InputOption::VALUE_NEGATABLE, 'Completely clean the search index and then build it from scratch. If not supplied, only modified content is re-indexed.', false)
			->addOption('continue', null, InputOption::VALUE_NEGATABLE, 'Continue reindexing after the process was interrupted.', false)
			->addOption('step-duration', null, InputOption::VALUE_REQUIRED, 'Step duration, in seconds.')
			->addOption('site', null, InputOption::VALUE_REQUIRED, 'Re-index only the specified site.')
			->addOption('module', null, InputOption::VALUE_REQUIRED, 'Re-index only the specified module.')
			->addOption('clear-suggest', null, InputOption::VALUE_NEGATABLE, 'Clear search phrase suggestions.', false)
			->addOption('trace', null, InputOption::VALUE_NEGATABLE, 'Output executed SQL queries (debug only).', false)
		;
	}

	/**
	 * Executes the reindexing command.
	 *
	 * @param Console\Input\InputInterface $input The input instance.
	 * @param Console\Output\OutputInterface $output The output instance.
	 * @return int Command status code (Console\Command\Command::SUCCESS for success).
	 */
	protected function execute(Console\Input\InputInterface $input, Console\Output\OutputInterface $output): int
	{
		global $DB;
		$searchDB = \CDatabase::GetModuleConnection('search');
		\Bitrix\Main\Loader::requireModule('search');

		$storage = \Bitrix\Main\DI\ServiceLocator::getInstance()->get(\Bitrix\Main\Data\Storage\PersistentStorageInterface::class);
		$maxExecutionTime = $input->getOption('step-duration') ?: (int)\Bitrix\Main\Config\Option::get('search', 'max_execution_time');
		$trace = $input->getOption('trace');

		if ($input->getOption('continue'))
		{
			$step = $storage->get('search_reindex', []);
		}
		else
		{
			$step = [];
			if ($input->getOption('site'))
			{
				$step['SITE_ID'] = $input->getOption('site');
			}
			if ($input->getOption('module'))
			{
				$step['MODULE_ID'] = $input->getOption('module');
			}
		}

		\CPageOption::SetOptionString('search', 'full_text_delay_index', 'N');

		if ($input->getOption('full'))
		{
			define('BX_SEARCH_FULL_REINDEXING', true);
		}

		// Init sql tracker
		$sqlTracker = null;
		if ($trace)
		{
			/** @var \Bitrix\Main\DB\Connection $connection */
			$connection = \Bitrix\Main\Application::getConnection();
			$sqlTracker = $connection->startTracker();
			$DB->ShowSqlStat = true;
			$searchDB->ShowSqlStat = true;
		}

		$cnt = $step['CNT'] ?? 0;
		while (true)
		{
			\CSearchCallback::$startTime = microtime(1);
			if ($trace)
			{
				$sqlTracker->reset();
			}
			$stime = microtime(1);
			$step = \CSearch::ReIndexAll($input->getOption('full'), $maxExecutionTime, $step, $input->getOption('clear-suggest'));
			$etime = microtime(1);

			if ($trace)
			{
				foreach ($sqlTracker->getQueries() as $query)
				{
					$output->writeln($query->getSql());
					$output->writeln('TIME: ' . $query->getTime());
				}
				$output->writeln(str_repeat('-', 40));
			}

			if (is_array($step))
			{
				$storage->set('search_reindex', $step, 3600 * 24);
				$delta = $etime - $stime;
				$rps = $delta > 0 ? ($step['CNT'] - $cnt) / $delta : 0;
				$output->writeln(
					date('c')
					. ' module: ' . $step['MODULE'] . ';'
					. ' last id: ' . $step['ID'] . ';'
					. ' total count: ' . $step['CNT'] . ';'
					. ' step count: ' . ($step['CNT'] - $cnt) . ';'
					. ' mem: ' . \CFile::FormatSize(memory_get_usage()) . ';'
					. ' rps: ' . round($rps, 2) . ';'
				);
				$cnt = $step['CNT'];
			}
			else
			{
				$output->writeln(date('c') . ' all done; total count: ' . $step);
				break;
			}
		}

		return Console\Command\Command::SUCCESS;
	}
}
