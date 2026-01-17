<?php

namespace Bitrix\Main\Cli\Command\Make;

use Bitrix\Main\Cli\Command\Make\Service\Module\GenerateDto;
use Bitrix\Main\Cli\Command\Make\Service\ModuleService;
use Bitrix\Main\Cli\Helper\AskQuestionTrait;
use Bitrix\Main\Loader;
use DomainException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command for generate module folder.
 */
final class ModuleCommand extends Command
{
	use AskQuestionTrait;

	private ModuleService $service;

	public function __construct(?string $pathToModulesFolder = null)
	{
		$this->service = new ModuleService(
			$pathToModulesFolder ?? Loader::getDocumentRoot() . '/local/modules'
		);

		parent::__construct();
	}

	protected function configure(): void
	{
		$this
			->setName('make:module')
			->setDescription('Make module folder with general files')
			->addArgument('id', InputArgument::REQUIRED)
			->addOption('name', null, InputOption::VALUE_REQUIRED, 'Module name')
			->addOption('description', null, InputOption::VALUE_REQUIRED, 'Module description')
			->addOption('module-version', null, InputOption::VALUE_REQUIRED, 'Module version', default: '1.0.0')
		;
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$dto = new GenerateDto(
			id: $input->getArgument('id'),
			version: $this->askOrGetOption('module-version', $input, $output),
			name: $this->askOrGetOption('name', $input, $output),
			description: $this->askOrGetOption('description', $input, $output, isRequired: false),
		);

		$result = $this->service->generate($dto);
		if (!$result->isSuccess())
		{
			throw new DomainException(
				$result->getError()->getMessage()
			);
		}

		$output->writeln("\nThe files have been created:\n");
		foreach ($result->getData() as $path)
		{
			$output->writeln(" - {$path}");
		}

		$output->writeln("\n");

		return self::SUCCESS;
	}
}
