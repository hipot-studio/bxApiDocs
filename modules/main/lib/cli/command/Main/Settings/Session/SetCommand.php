<?php

namespace Bitrix\Main\Cli\Command\Main\Settings\Session;

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
			->setName('main:settings:session-set')
			->setDescription('Replace session section in .settings.php')
			->addOption('mode', null, InputOption::VALUE_REQUIRED, 'Session mode: default or separated')
			->addOption('general', null, InputOption::VALUE_REQUIRED, 'General session handler type')
			->addOption('kernel', null, InputOption::VALUE_REQUIRED, 'Kernel session handler for separated mode')
			->addOption('redis-host', null, InputOption::VALUE_REQUIRED, 'Redis host for general handler')
			->addOption('redis-port', null, InputOption::VALUE_REQUIRED, 'Redis port for general handler')
			->addOption('redis-password', null, InputOption::VALUE_REQUIRED, 'Redis password for general handler')
			->addOption('memcache-host', null, InputOption::VALUE_REQUIRED, 'Memcache host for general handler')
			->addOption('memcache-port', null, InputOption::VALUE_REQUIRED, 'Memcache port for general handler')
			->addOption(
				'readonly',
				null,
				InputOption::VALUE_NEGATABLE,
				'Write section as readonly (use --no-readonly to keep it writable)',
				true,
			)
			->setHelp(<<<'HELP'
Replaces the entire session section in .settings.php.
Separated mode requires a crypto key: php bitrix.php main:settings:crypto-set

Examples:

  php bitrix.php main:settings:session-set --mode=separated --kernel=encrypted_cookies --general=memcache --memcache-host=localhost --memcache-port=11211
  php bitrix.php main:settings:session-set --mode=default --general=redis --redis-host=localhost --redis-port=6379
  php bitrix.php main:settings:session-set --mode=default --general=file --no-readonly
HELP
			)
		;
	}

	protected function executeFormatted(InputInterface $input, OutputInterface $output): int
	{
		try
		{
			$readonly = (bool)$input->getOption('readonly');
			$value = $this->settingsConfigurator->applySession($this->collectOptions($input), $readonly);
		}
		catch (InvalidArgumentException $exception)
		{
			return $this->renderError($output, 'INVALID_ARGUMENT', $exception->getMessage(), self::INVALID);
		}

		return $this->renderSuccess(
			$output,
			[
				'section' => SettingsConfigurator::SECTION_SESSION,
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
		return [
			'mode' => $this->getOptionalOption($input, 'mode'),
			'general' => $this->getOptionalOption($input, 'general'),
			'kernel' => $this->getOptionalOption($input, 'kernel'),
			'redis_host' => $this->getOptionalOption($input, 'redis-host'),
			'redis_port' => $this->getOptionalOption($input, 'redis-port'),
			'redis_password' => $this->getOptionalOption($input, 'redis-password'),
			'memcache_host' => $this->getOptionalOption($input, 'memcache-host'),
			'memcache_port' => $this->getOptionalOption($input, 'memcache-port'),
		];
	}

	private function getOptionalOption(InputInterface $input, string $name): ?string
	{
		$value = $input->getOption($name);

		return is_string($value) ? $value : null;
	}
}
