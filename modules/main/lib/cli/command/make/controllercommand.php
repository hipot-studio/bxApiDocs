<?php

namespace Bitrix\Main\Cli\Command\Make;

use Bitrix\Main\Cli\Command\Make\Service\Controller\GenerateDto;
use Bitrix\Main\Cli\Command\Make\Service\ControllerService;
use Bitrix\Main\Cli\Helper\AskQuestionTrait;
use Bitrix\Main\Cli\Helper\Namespaces\NamespaceVariationsCommandTrait;
use Bitrix\Main\Cli\Helper\PathGenerator\ChangeablePathGeneratorTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command for generate controller class.
 */
final class ControllerCommand extends Command
{
	use AskQuestionTrait;
	use ChangeablePathGeneratorTrait;
	use NamespaceVariationsCommandTrait;

	protected function configure(): void
	{
		$this
			->setName('make:controller')
			->addArgument('name', InputArgument::REQUIRED, 'controller name')
			->addOption('module', 'm', InputOption::VALUE_REQUIRED, 'Module id')
			->addOption('psr4', null, InputOption::VALUE_NEGATABLE, default: true)
			->addOption('alias', null, InputArgument::OPTIONAL, 'Controller\'s namespace alias from .settings.php')
			->addOption('actions', null, InputArgument::OPTIONAL, 'Comma-separated names of actions. Use alias "crud" for CRUD actions')
			->setDescription('Make controller class')
			->setHelp(<<<DESCRIPTION
			Make controller class.

			Example of simple creation. In interactive mode, the system will ask you for the necessary parameters:
			<question>php bitrix.php make:controller post</>

			Example of creation with module option:
			<question>php bitrix.php make:controller post -m my.module</>

			Example of creation with module option:
			<question>php bitrix.php make:controller post -m my.module</>

			You can specify a comma-separated list of required actions that will be generated:
			<question>php bitrix.php make:controller post -m my.module --actions=list,get,add,update,delete</>

			You can also use the shortcode `crud` to generate standard actions:
			<question>php bitrix.php make:controller post -m my.module --actions=crud</>

			If you want to disable the interactive mode, then the `-n` option is used, then no questions will be asked:
			<question>php bitrix.php make:controller post -m my.module -n</>

			By default, the class will be placed in the standard namespace.
			If you want to place it in a specific sub-namespace of the module, use the `-P` option.:
			<question>php bitrix.php make:controller post -P V2</>

			If for some reason you do not want to generate the namespace and path in the PSR-4 format, you can send the `--no-psr` option.
			<question>php bitrix.php make:controller post --no-psr</>
			DESCRIPTION)
		;

		$this->configureNamespaceVariationsOptions($this);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$name = $input->getArgument('name');

		$pathGenerator = $this->getPathGenerator();
		$pathGenerator->setCamelCase(
			$input->getOption('psr4') === true,
		);

		$service = new ControllerService($pathGenerator);

		$dto = new GenerateDto(
			name: $name,
			moduleId: $this->askOrGetOption('module', $input, $output),
			actions: $this->resolveActions(
				(string)$this->askOrGetOption('actions', $input, $output, false)
			),
			alias: $this->askOrGetOption('alias', $input, $output, false),
		);
		$this->fillNamespaceVariationsOptions($dto, $input, $output);

		$result = $service->generateFile($dto);

		$error = $result->getError()?->getMessage();
		if ($error)
		{
			$output->writeln("<error>{$error}</error>");

			return self::FAILURE;
		}

		$output->writeln($result->getSuccessMessage());

		return self::SUCCESS;
	}

	private function resolveActions(string $actions): array
	{
		$actions = explode(',', $actions);

		if (in_array('crud', $actions, true))
		{
			$actions = array_filter($actions, static fn(string$action): bool => $action !== 'crud');

			$actions = array_merge(
				$actions,
				['list', 'get', 'add', 'update', 'delete']
			);

			return array_unique($actions);
		}

		return $actions;
	}
}
