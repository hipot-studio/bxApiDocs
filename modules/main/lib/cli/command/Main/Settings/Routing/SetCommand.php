<?php

namespace Bitrix\Main\Cli\Command\Main\Settings\Routing;

use Bitrix\Main\Cli\Command\AbstractFormattedCommand;
use Bitrix\Main\Cli\Command\Main\Settings\SettingsConfigurator;
use Bitrix\Main\Cli\Output\OutputFormatter;
use InvalidArgumentException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class SetCommand extends AbstractFormattedCommand
{
	public function __construct(
		private readonly SettingsConfigurator $settingsConfigurator,
		OutputFormatter $outputFormatter,
	)
	{
		parent::__construct($outputFormatter);
	}

	protected function configure(): void
	{
		$this
			->setName('main:settings:routing-set')
			->setDescription('Patch routing section in .settings.php')
			->addOption(
				'add',
				null,
				InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED,
				'Route file name to add',
			)
			->addOption(
				'remove',
				null,
				InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED,
				'Route file name to remove',
			)
			->addOption(
				'readonly',
				null,
				InputOption::VALUE_NEGATABLE,
				'Write section as readonly (use --no-readonly to keep it writable)',
				true,
			)
			->setHelp(<<<'HELP'
Patches the routing config file list. Removes are applied first, then adds. Other keys in the section are left unchanged.

Examples:

  php bitrix.php main:settings:routing-set --add=web.php
  php bitrix.php main:settings:routing-set --add=api.php --add=admin.php
  php bitrix.php main:settings:routing-set --remove=api.php --no-readonly
HELP
			)
		;
	}

	protected function executeFormatted(InputInterface $input, OutputInterface $output): int
	{
		try
		{
			$readonly = (bool)$input->getOption('readonly');
			$value = $this->settingsConfigurator->applyRouting($this->collectOptions($input), $readonly);
		}
		catch (InvalidArgumentException $exception)
		{
			return $this->renderError($output, 'INVALID_ARGUMENT', $exception->getMessage(), self::INVALID);
		}

		return $this->renderSuccess(
			$output,
			[
				'section' => SettingsConfigurator::SECTION_ROUTING,
				'value' => $value,
			],
			columns: [
				'section' => 'Section',
				'value' => 'Value',
			],
		);
	}

	private function collectOptions(InputInterface $input): array
	{
		$add = $input->getOption('add');
		$remove = $input->getOption('remove');

		return [
			'add' => is_array($add) ? $add : [],
			'remove' => is_array($remove) ? $remove : [],
		];
	}
}
