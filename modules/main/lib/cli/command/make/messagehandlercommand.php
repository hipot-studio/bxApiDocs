<?php

namespace Bitrix\Main\Cli\Command\Make;

use Bitrix\Main\Cli\Command\Make\Service\MessageHandler\GenerateDto;
use Bitrix\Main\Cli\Command\Make\Service\MessageHandlerService;
use Bitrix\Main\Cli\Helper\AskQuestionTrait;
use Bitrix\Main\Cli\Helper\Namespaces\NamespaceVariationsCommandTrait;
use Bitrix\Main\Cli\Helper\PathGenerator\ChangeablePathGeneratorTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class MessageHandlerCommand extends Command
{
	use AskQuestionTrait;
	use ChangeablePathGeneratorTrait;
	use NamespaceVariationsCommandTrait;

	protected function configure(): void
	{
		$this
			->setName('make:messagehandler')
			->addArgument('name', InputArgument::REQUIRED)
			->addOption('handler-module', 'm', InputOption::VALUE_REQUIRED, 'Module id for handler')
			->addOption('message-module', null, InputOption::VALUE_REQUIRED, 'Module id for handled message')
			->addOption('psr4', null, InputOption::VALUE_NEGATABLE, default: true)
			->setDescription('Make message handler object class')
			->setHelp(<<<DESCRIPTION
			Make message handler object class.

			Example of simple creation. In interactive mode, the system will ask you for the necessary parameters:
			<question>php bitrix.php make:messagehandler updateRating</>

			Example of creation with modules options:
			<question>php bitrix.php make:messagehandler updateRating --handler-module=my.module --message-module=another.module</>

			If you want to disable the interactive mode, then the `-n` option is used, then no questions will be asked:
			<question>php bitrix.php make:messagehandler updateRating --handler-module=my.module --message-module=another.module -n</>

			By default, the class will be placed in the standard namespace.
			If you want to place it in a specific sub-namespace of the module, use the `-P` option.:
			<question>php bitrix.php make:messagehandler updateRating -P V2</>

			If for some reason you do not want to generate the namespace and path in the PSR-4 format, you can send the `--no-psr` option.
			<question>php bitrix.php make:messagehandler updateRating --no-psr</>
			DESCRIPTION)
		;
		$this->configureNamespaceVariationsOptions($this);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$eventName = $input->getArgument('name');
		$handlerModuleId = $this->askOrGetOption('handler-module', $input, $output);
		$messageModuleId = $this->askOrGetOption('message-module', $input, $output);

		$pathGenerator = $this->getPathGenerator();
		$pathGenerator->setCamelCase(
			$input->getOption('psr4') === true,
		);

		$service = new MessageHandlerService($pathGenerator);

		$dto = new GenerateDto(
			$eventName,
			$handlerModuleId,
			$messageModuleId,
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
