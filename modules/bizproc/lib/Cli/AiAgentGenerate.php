<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Cli;

use Bitrix\Bizproc\Internal\AI\Agent\Generator\AgentTemplateGenerator;
use Bitrix\Main\IO\Directory;
use Bitrix\Main\IO\File;
use Bitrix\Main\Loader;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Generates template.json, installer.php, and lang skeleton from template.source.json.
 *
 * Usage: php bitrix/cli.php bizproc:ai-agent-generate <agent_name>
 * Example: php bitrix/cli.php bizproc:ai-agent-generate bitrix_ai_day_planner
 */
final class AiAgentGenerate extends Command
{
	public function isEnabled(): bool
	{
		return Loader::includeModule('bizproc');
	}

	protected function configure(): void
	{
		$this
			->setName('bizproc:ai-agent-generate')
			->setDescription('Generate AI agent template from template.source.json')
			->addArgument('name', InputArgument::REQUIRED, 'Agent directory name (e.g. bitrix_ai_day_planner)')
		;
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);

		$agentName = $input->getArgument('name');
		if (!preg_match('/^[a-z0-9_]+$/', $agentName))
		{
			$io->error("Invalid agent name: only lowercase letters, digits and underscores allowed");

			return self::FAILURE;
		}

		$nodesDir = $this->getNodesDir();
		$agentDir = $nodesDir . '/' . $agentName;
		if (!Directory::isDirectoryExists($agentDir))
		{
			$io->error("Agent directory not found: {$agentDir}");

			return self::FAILURE;
		}

		$configPath = $agentDir . '/template.source.json';
		if (!File::isFileExists($configPath))
		{
			$io->error("Config file not found: {$configPath}");

			return self::FAILURE;
		}

		try
		{
			$generator = new AgentTemplateGenerator($nodesDir);
			$generatedFiles = $generator->generate($agentName);
		}
		catch (\Throwable $e)
		{
			$io->error($e->getMessage());

			return self::FAILURE;
		}

		$warnings = $generator->getPropertyWarnings();
		foreach ($warnings as $warning)
		{
			$io->warning($warning);
		}

		$message = ["Agent '{$agentName}' generated successfully:"];
		foreach ($generatedFiles as $file)
		{
			$message[] = "  - {$file}";
		}
		$io->success($message);

		return self::SUCCESS;
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
