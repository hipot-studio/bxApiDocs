<?php

namespace Bitrix\Bizproc\Cli;

use Bitrix\Bizproc\Public\Service\Template\MakeTemplatePackageDto;
use Bitrix\Bizproc\Public\Service\Template\NodesInstallerService;
use Bitrix\Main;
use Bitrix\Main\Cli\Helper\AskQuestionTrait;
use Bitrix\Main\Loader;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Symfony command to export BizProc nodes (template.json + lang file).
 */
final class NodesExport extends Command
{
	use AskQuestionTrait;

	public function isEnabled(): bool
	{
		return Loader::includeModule('bizproc');
	}

	protected function configure(): void
	{
		$this
			->setName('bizproc:nodes-export')
			->setDescription('Export BizProc workflow template nodes to JSON and lang files')
			->addArgument('id', InputArgument::REQUIRED, 'Workflow template ID')
			->addOption('section', 's', InputOption::VALUE_REQUIRED, 'Target template section', 'bp')
			->addOption('code', 'c', InputOption::VALUE_REQUIRED, 'Target template code', 'system')
		;
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);

		$id = (int)$input->getArgument('id');
		$section = $this->askOrGetOption('section', $input, $output);
		$code = $this->askOrGetOption('code', $input, $output);

		$request = new MakeTemplatePackageDto($id, $section, $code);

		try
		{
			$directoryWithTemplate = (new NodesInstallerService())->makeTemplatePackage($request);
		}
		catch (Main\ArgumentException $e)
		{
			$io->error($e->getMessage());

			return self::FAILURE;
		}

		$io->success("Template exported to {$directoryWithTemplate->getPath()}");

		return self::SUCCESS;
	}
}
