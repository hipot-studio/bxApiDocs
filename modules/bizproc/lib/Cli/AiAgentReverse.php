<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Cli;

use Bitrix\Bizproc\Internal\AI\Agent\Generator\TemplateBuilder\ActivityRegistry;
use Bitrix\Bizproc\Internal\AI\Agent\Generator\TemplateReverser\TemplateReverser;
use Bitrix\Main\IO\Directory;
use Bitrix\Main\IO\File;
use Bitrix\Main\Loader;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Reverses an existing template.json back into a template.source.json.
 *
 * Usage: php bitrix/cli.php bizproc:ai-agent-reverse <agent_name> [--force] [--verify]
 */
final class AiAgentReverse extends Command
{
	public function isEnabled(): bool
	{
		return Loader::includeModule('bizproc');
	}

	protected function configure(): void
	{
		$this
			->setName('bizproc:ai-agent-reverse')
			->setDescription('Reverse-engineer template.source.json from template.json')
			->addArgument('name', InputArgument::REQUIRED, 'Agent directory name (e.g. bitrix_ai_project_pulse)')
			->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite existing template.source.json')
			->addOption('verify', null, InputOption::VALUE_NONE, 'After reverse, regenerate in-memory and report link drift')
		;
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);

		$agentName = (string)$input->getArgument('name');
		if (!preg_match('/^[a-z0-9_]+$/', $agentName))
		{
			$io->error('Invalid agent name: only lowercase letters, digits and underscores allowed');

			return self::FAILURE;
		}

		$nodesDir = $this->getNodesDir();
		$agentDir = $nodesDir . '/' . $agentName;
		if (!Directory::isDirectoryExists($agentDir))
		{
			$io->error("Agent directory not found: {$agentDir}");

			return self::FAILURE;
		}

		$templatePath = $agentDir . '/template.json';
		if (!File::isFileExists($templatePath))
		{
			$io->error("template.json not found: {$templatePath}");

			return self::FAILURE;
		}

		$sourcePath = $agentDir . '/template.source.json';
		if (File::isFileExists($sourcePath) && !$input->getOption('force'))
		{
			$io->error("template.source.json already exists: {$sourcePath}. Use --force to overwrite.");

			return self::FAILURE;
		}

		try
		{
			$raw = File::getFileContents($templatePath);
			if (!is_string($raw))
			{
				$io->error("Failed to read {$templatePath}");

				return self::FAILURE;
			}
			$template = \Bitrix\Main\Web\Json::decode($raw);
			if (!is_array($template))
			{
				$io->error("template.json must decode to an object: {$templatePath}");

				return self::FAILURE;
			}

			$reverser = new TemplateReverser(new ActivityRegistry());
			$source = $reverser->reverse($template, $agentName);

			$json = \Bitrix\Main\Web\Json::encode($source, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			$bytes = File::putFileContents($sourcePath, $json . PHP_EOL);
			if ($bytes === false || $bytes === 0)
			{
				$io->error("Failed to write template.source.json: {$sourcePath}");

				return self::FAILURE;
			}
		}
		catch (\Throwable $e)
		{
			$io->error($e->getMessage());

			return self::FAILURE;
		}

		$io->success([
			"Agent '{$agentName}' reversed successfully:",
			"  - {$sourcePath}",
		]);

		if ($input->getOption('verify'))
		{
			$this->verifyRoundTrip($io, $template, $source);
		}

		return self::SUCCESS;
	}

	private function verifyRoundTrip(SymfonyStyle $io, array $originalTemplate, array $reversedSource): void
	{
		try
		{
			$reverser = new TemplateReverser(new ActivityRegistry());
			$report = $reverser->verifyRoundTrip($originalTemplate, $reversedSource);
		}
		catch (\Throwable $e)
		{
			$io->warning('Verify: cannot rebuild reversed source — ' . $e->getMessage());

			return;
		}

		if ($report->isClean())
		{
			$io->success('Verify: round-trip clean — all known-node links preserved.');

			return;
		}

		$lines = ['Verify: round-trip drift detected.'];
		$lines[] = sprintf('Lost (%d) / Extra (%d) links between known activities.', count($report->lost), count($report->extra));
		if ($report->lost !== [])
		{
			$lines[] = 'Lost (in original, not in regenerated):';
			foreach (array_slice($report->lost, 0, 10) as $l)
			{
				$lines[] = '  - ' . $l;
			}
			if (count($report->lost) > 10)
			{
				$lines[] = '  ... +' . (count($report->lost) - 10) . ' more';
			}
		}
		if ($report->extra !== [])
		{
			$lines[] = 'Extra (in regenerated, not in original):';
			foreach (array_slice($report->extra, 0, 10) as $l)
			{
				$lines[] = '  - ' . $l;
			}
			if (count($report->extra) > 10)
			{
				$lines[] = '  ... +' . (count($report->extra) - 10) . ' more';
			}
		}
		$io->warning(implode("\n", $lines));
	}

	private function getNodesDir(): string
	{
		$documentRoot = (string)\Bitrix\Main\Application::getInstance()
			->getContext()
			->getServer()
			->getDocumentRoot();

		return $documentRoot . '/bitrix/modules/bizproc/nodes/AI_AGENT';
	}
}
