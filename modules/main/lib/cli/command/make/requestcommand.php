<?php

namespace Bitrix\Main\Cli\Command\Make;

use Bitrix\Main\Cli\Command\Make\Service\Request\GenerateDto;
use Bitrix\Main\Cli\Command\Make\Service\RequestService;
use Bitrix\Main\Cli\Helper\AskQuestionTrait;
use Bitrix\Main\Cli\Helper\Namespaces\NamespaceVariationsCommandTrait;
use Bitrix\Main\Cli\Helper\PathGenerator\ChangeablePathGeneratorTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class RequestCommand extends Command
{
	use AskQuestionTrait;
	use ChangeablePathGeneratorTrait;
	use NamespaceVariationsCommandTrait;

	protected function configure(): void
	{
		$this
			->setName('make:request')
			->addArgument('name', InputArgument::REQUIRED)
			->addOption('module', 'm', InputOption::VALUE_REQUIRED, 'Module id')
			->addOption('fields', 'f', InputOption::VALUE_OPTIONAL, 'List of fields separated `,`')
			->addOption('psr4', null, InputOption::VALUE_NEGATABLE, default: true)
			->setDescription('Make request object of controller')
			->setHelp(<<<DESCRIPTION
			Make request object of controller.

			Example of simple creation. In interactive mode, the system will ask you for the necessary parameters:
			<question>php bitrix.php make:request post</>

			Example of creation with module option:
			<question>php bitrix.php make:request post -m my.module</>

			When creating, you can immediately specify a list of fields for the request object:
			<question>php bitrix.php make:request post --fields=id,title,content</>

			If you want to disable the interactive mode, then the `-n` option is used, then no questions will be asked:
			<question>php bitrix.php make:request post -m my.module --fields=id,title,content -n</>

			By default, the class will be placed in the standard namespace.
			If you want to place it in a specific sub-namespace of the module, use the `-P` option.:
			<question>php bitrix.php make:request post -P V2</>
			DESCRIPTION)
		;
		$this->configureNamespaceVariationsOptions($this);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$requestName = $input->getArgument('name');
		$moduleId = $this->askOrGetOption('module', $input, $output);
		$fieldsStr = $this->askOrGetOption('fields', $input, $output, isRequired: false);
		$fields = array_filter(
			explode(',', $fieldsStr)
		);

		$dto = new GenerateDto(
			$requestName,
			$moduleId,
			$fields,
		);
		$this->fillNamespaceVariationsOptions($dto, $input, $output);

		$pathGenerator = $this->getPathGenerator();
		$pathGenerator->setCamelCase(
			$input->getOption('psr4') === true,
		);

		$service = new RequestService($pathGenerator);
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
