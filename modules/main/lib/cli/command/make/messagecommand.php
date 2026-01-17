<?php

namespace Bitrix\Main\Cli\Command\Make;

use Bitrix\Main\Cli\Command\Make\Service\Message\GenerateDto;
use Bitrix\Main\Cli\Command\Make\Service\MessageService;
use Bitrix\Main\Cli\Helper\AskQuestionTrait;
use Bitrix\Main\Cli\Helper\Namespaces\NamespaceVariationsCommandTrait;
use Bitrix\Main\Cli\Helper\PathGenerator\ChangeablePathGeneratorTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class MessageCommand extends Command
{
	use AskQuestionTrait;
	use ChangeablePathGeneratorTrait;
	use NamespaceVariationsCommandTrait;

	protected function configure(): void
	{
		$this
			->setName('make:message')
			->addArgument('name', InputArgument::REQUIRED)
			->addOption('module', 'm', InputOption::VALUE_REQUIRED, 'Module id')
			->addOption('psr4', null, InputOption::VALUE_NEGATABLE, default: true)
			->setDescription('Make message object class')
			->setHelp(<<<DESCRIPTION
			Make message object class.

			Example of simple creation. In interactive mode, the system will ask you for the necessary parameters:
			<question>php bitrix.php make:message updateRating</>

			Example of creation with module option:
			<question>php bitrix.php make:message updateRating -m my.module</>

			If you want to disable the interactive mode, then the `-n` option is used, then no questions will be asked:
			<question>php bitrix.php make:message updateRating -m my.module -n</>

			By default, the class will be placed in the standard namespace.
			If you want to place it in a specific sub-namespace of the module, use the `-P` option.:
			<question>php bitrix.php make:message updateRating -P V2</>

			If for some reason you do not want to generate the namespace and path in the PSR-4 format, you can send the `--no-psr` option.
			<question>php bitrix.php make:message updateRating --no-psr</>
			DESCRIPTION)
		;
		$this->configureNamespaceVariationsOptions($this);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$eventName = $input->getArgument('name');
		$moduleId = $this->askOrGetOption('module', $input, $output);

		$pathGenerator = $this->getPathGenerator();
		$pathGenerator->setCamelCase(
			$input->getOption('psr4') === true,
		);

		$service = new MessageService($pathGenerator);

		$dto = new GenerateDto(
			$eventName,
			$moduleId,
		);
		$this->fillNamespaceVariationsOptions($dto, $input, $output);

		$result = $service->generateFile(
			$dto,
		);

		$error = $result->getError()?->getMessage();
		if ($error)
		{
			$output->writeln("<error>{$error}</error>");

			return self::FAILURE;
		}

		$output->writeln($result->getSuccessMessage());

		return self::SUCCESS;
	}
}
