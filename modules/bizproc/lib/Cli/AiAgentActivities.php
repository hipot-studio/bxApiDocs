<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Cli;

use Bitrix\Main\Loader;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Lists available workflow activities with their properties.
 *
 * Usage:
 *   php bitrix/bitrix.php bizproc:ai-agent-activities                       # list all
 *   php bitrix/bitrix.php bizproc:ai-agent-activities --search="calendar"    # search
 *   php bitrix/bitrix.php bizproc:ai-agent-activities CalendarGetInform      # details + examples
 */
final class AiAgentActivities extends Command
{
	public function isEnabled(): bool
	{
		return Loader::includeModule('bizproc');
	}

	protected function configure(): void
	{
		$this
			->setName('bizproc:ai-agent-activities')
			->setDescription('List available workflow activities for AI agent configs')
			->addArgument('code', InputArgument::OPTIONAL, 'Activity code to show details (e.g. CalendarGetInform)')
			->addOption('search', 's', InputOption::VALUE_REQUIRED, 'Search activities by name or description')
			->addOption('type', 't', InputOption::VALUE_REQUIRED, 'Filter by type: node, trigger, activity, node_action', 'node,trigger')
		;
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$code = $input->getArgument('code');
		if ($code !== null)
		{
			return $this->showDetails($code, $output);
		}

		return $this->listActivities($input, $output);
	}

	private function listActivities(InputInterface $input, OutputInterface $output): int
	{
		$types = array_map('trim', explode(',', $input->getOption('type')));
		$search = $input->getOption('search');
		$searchLower = $search !== null ? mb_strtolower($search) : null;

		$result = [];
		foreach ($types as $type)
		{
			$activities = \CBPRuntime::getRuntime()->searchActivitiesByType($type);
			foreach ($activities as $actCode => $desc)
			{
				$name = $desc['NAME'] ?? '';
				$description = $desc['DESCRIPTION'] ?? '';

				if ($searchLower !== null)
				{
					$haystack = mb_strtolower($name . ' ' . $description);
					if (!str_contains($haystack, $searchLower))
					{
						continue;
					}
				}

				$returnFields = array_keys($desc['RETURN'] ?? []);

				$result[] = [
					'code' => $desc['CLASS'] ?? $actCode,
					'name' => $name,
					'description' => $description,
					'type' => implode(',', $desc['TYPE'] ?? []),
					'return' => $returnFields,
				];
			}
		}

		usort($result, fn($a, $b) => strcmp($a['name'], $b['name']));

		$output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

		return self::SUCCESS;
	}

	/**
	 * Shows activity details + real usage examples from template.json files.
	 */
	private function showDetails(string $code, OutputInterface $output): int
	{
		$runtime = \CBPRuntime::getRuntime();
		$desc = $runtime->getActivityDescription($code);
		if ($desc === null)
		{
			$output->writeln("<error>Activity not found: {$code}</error>");

			return self::FAILURE;
		}

		$activityCode = $desc['CLASS'] ?? $code;

		$result = [
			'code' => $activityCode,
			'name' => $desc['NAME'] ?? '',
			'description' => $desc['DESCRIPTION'] ?? '',
			'type' => $desc['TYPE'] ?? [],
			'return' => $desc['RETURN'] ?? [],
		];

		$registry = new \Bitrix\Bizproc\Internal\AI\Agent\Generator\TemplateBuilder\ActivityRegistry();

		if ($registry->isConfigurable($activityCode))
		{
			$result['configurable'] = true;
			$result['properties_note'] = 'Properties may be incomplete — activity uses extended configuration. Read source code for full property set.';
		}

		$dialogMap = $registry->getDialogMap($activityCode);
		if ($dialogMap !== null)
		{
			$result['properties'] = $dialogMap;
		}

		$sourcePath = $registry->getSourcePath($activityCode);
		if ($sourcePath !== null)
		{
			$result['source_path'] = $sourcePath;
		}

		$output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

		return self::SUCCESS;
	}
}
