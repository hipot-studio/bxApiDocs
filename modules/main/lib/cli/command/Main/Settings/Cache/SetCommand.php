<?php

namespace Bitrix\Main\Cli\Command\Main\Settings\Cache;

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
			->setName('main:settings:cache-set')
			->setDescription('Replace cache section in .settings.php')
			->addOption('engine', null, InputOption::VALUE_REQUIRED, 'Cache engine')
			->addOption('class-name', null, InputOption::VALUE_REQUIRED, 'Custom cache engine class name')
			->addOption('extension', null, InputOption::VALUE_REQUIRED, 'PHP extension for custom cache engine')
			->addOption('required-file', null, InputOption::VALUE_REQUIRED, 'Required local file for custom cache engine')
			->addOption('required-remote-file', null, InputOption::VALUE_REQUIRED, 'Required remote file for custom cache engine')
			->addOption('redis-host', null, InputOption::VALUE_REQUIRED, 'Redis host')
			->addOption('redis-port', null, InputOption::VALUE_REQUIRED, 'Redis port')
			->addOption('redis-password', null, InputOption::VALUE_REQUIRED, 'Redis password')
			->addOption('memcache-host', null, InputOption::VALUE_REQUIRED, 'Memcache host')
			->addOption('memcache-port', null, InputOption::VALUE_REQUIRED, 'Memcache port')
			->addOption('memcached-host', null, InputOption::VALUE_REQUIRED, 'Memcached host')
			->addOption('memcached-port', null, InputOption::VALUE_REQUIRED, 'Memcached port')
			->addOption(
				'readonly',
				null,
				InputOption::VALUE_NEGATABLE,
				'Write section as readonly (use --no-readonly to keep it writable)',
				true,
			)
			->setHelp(<<<'HELP'
Replaces the entire cache section in .settings.php.

Examples:

  php bitrix.php main:settings:cache-set --engine=redis --redis-host=localhost --redis-port=6379
  php bitrix.php main:settings:cache-set --engine=files --no-readonly
  php bitrix.php main:settings:cache-set --class-name='Bitrix\Main\Data\CacheEngineRedis' --extension=redis
HELP
			)
		;
	}

	protected function executeFormatted(InputInterface $input, OutputInterface $output): int
	{
		try
		{
			$readonly = (bool)$input->getOption('readonly');
			$value = $this->settingsConfigurator->applyCache($this->collectOptions($input), $readonly);
		}
		catch (InvalidArgumentException $exception)
		{
			return $this->renderError($output, 'INVALID_ARGUMENT', $exception->getMessage(), self::INVALID);
		}

		return $this->renderSuccess(
			$output,
			[
				'section' => SettingsConfigurator::SECTION_CACHE,
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
			'engine' => $this->getOptionalOption($input, 'engine'),
			'class_name' => $this->getOptionalOption($input, 'class-name'),
			'extension' => $this->getOptionalOption($input, 'extension'),
			'required_file' => $this->getOptionalOption($input, 'required-file'),
			'required_remote_file' => $this->getOptionalOption($input, 'required-remote-file'),
			'redis_host' => $this->getOptionalOption($input, 'redis-host'),
			'redis_port' => $this->getOptionalOption($input, 'redis-port'),
			'redis_password' => $this->getOptionalOption($input, 'redis-password'),
			'memcache_host' => $this->getOptionalOption($input, 'memcache-host'),
			'memcache_port' => $this->getOptionalOption($input, 'memcache-port'),
			'memcached_host' => $this->getOptionalOption($input, 'memcached-host'),
			'memcached_port' => $this->getOptionalOption($input, 'memcached-port'),
		];
	}

	private function getOptionalOption(InputInterface $input, string $name): ?string
	{
		$value = $input->getOption($name);

		return is_string($value) ? $value : null;
	}
}
