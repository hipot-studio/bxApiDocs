<?php

namespace Bitrix\Main\Diag;

use Bitrix\Main\Config\Configuration;
use Bitrix\Main\DI\ServiceLocator;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Factory for creation loggers.
 *
 * For injection logger to the service, you can use 'constructorParams' or 'constructor' in `.settings.php` file on module.
 * For example service class:
 * ```php
	class MyService
	{
		public function __construct(
			private readonly \Psr\Log\LoggerInterface $logger,
		)
		{}

		public function myAction()
		{
			$this->logger->info('MyService action called');
		}
	}
 * ```
 * Can be injected in `.settings.php` like this:
 * ```php
	return [
		// ...
		'services' => [
			'value' => [
				\MyService::class => [
					'className' => \MyService::class,
					'constructorParams' => static function() {
						return [
							(new \Bitrix\Main\Diag\LoggerFactory())->createById('my.module.myservice'),
						];
					},
				],
			],
			'readonly' => true,
		],
	];
 * ```
 *
 * Without configuration in `.settings.php` will be using default file logger will be logging to the file defined in `LOG_FILENAME` constant.
 * For configuration of loggers in `.settings.php` add new logger in 'loggers' section:
 * ```php
	return [
		// ...
		'loggers' => [
			'value' => [
				'my.module.myservice' => [
					'className' => \Bitrix\Main\Diag\FileLogger::class,
					'constructorParams' => ['path/to/log', true],
				],
			],
			'readonly' => true,
		],
	];
 * ```
 */
final class LoggerFactory
{
	/**
	 * @param  bool $alwaysReturnLogger - if false, the factory will return null if the logger is not found by id and default logger is not created. Otherwise, it will return NullLogger.
	 * @param  LoggerRegistry $loggerRegistry
	 */
	public function __construct(
		private readonly bool $alwaysReturnLogger = true,
		private readonly LoggerRegistry $loggerRegistry = new LoggerRegistry(),
		private ?array $configuration = null,
	)
	{}

	/**
	 * Create default logger.
	 *
	 * @param  bool                 $showArgs
	 *
	 * @return LoggerInterface|null
	 */
	public function createDefault(bool $showArgs = false): ?LoggerInterface
	{
		$logFileName = null;
		if (defined('LOG_FILENAME'))
		{
			$logFileName = LOG_FILENAME;
		}

		if (!empty($logFileName))
		{
			$logger = $this->createFromConfiguration('main.Default', [$logFileName, $showArgs]);
			if ($logger)
			{
				return $logger;
			}

			$logger = new FileLogger($logFileName, 0);
			$logger->setFormatter(
				new LogFormatter($showArgs)
			);

			return $logger;
		}

		if ($this->alwaysReturnLogger)
		{
			return new NullLogger();
		}

		return null;
	}

	/**
	 * Creates a logger by its ID based on .settings.php.
	 * 'loggers' => [
	 * 		'logger.id' => [
	 * 			'className' => 'name of the logger class',
	 * 			'constructorParams' => [] OR closure,
	 * 			OR
	 * 			'constructor' => function (...$param){},
	 * 			OPTIONAL
	 * 			'level' => 'verbose level',
	 * 			'formatter' => 'id of formatter in service locator',
	 * 		]
	 * ]
	 *
	 * @param  string               $id a logger ID.
	 * @param  array                $params an optional params to be passed to a closure in settings.
	 * @param  bool                 $isCheckEnabledFromRegistry - if true, the factory will check the logger registry to determine if a logger is enabled.
	 * @param  bool                 $returnDefaultLoggerIfNotExists - if true, the factory will return default logger if the logger with $id is enabled but not found in settings. Otherwise, it will return null.
	 *
	 * @return LoggerInterface|null
	 */
	public function createById(
		string $id,
		array $params = [],
		bool $isCheckEnabledFromRegistry = true,
		bool $returnDefaultLoggerIfNotExists = true,
	): ?LoggerInterface
	{
		$logger = null;

		$isLoggerEnabled =
			$isCheckEnabledFromRegistry === false
			|| $this->loggerRegistry->isEnabled($id)
		;
		if ($isLoggerEnabled)
		{
			$logger = $this->createFromConfiguration($id, $params);

			if (!$logger && $returnDefaultLoggerIfNotExists)
			{
				$logger = $this->createDefault();
			}
		}

		if (!$logger && $this->alwaysReturnLogger)
		{
			$logger = new NullLogger();
		}

		return $logger;
	}

	private function getConfiguration(): array
	{
		$this->configuration ??= (array)(Configuration::getValue('loggers') ?? []);

		return $this->configuration;
	}

	/**
	 * Creates a logger based on configuration data
	 *
	 * @param  string               $id
	 * @param  array                $params
	 *
	 * @return LoggerInterface|null
	 */
	private function createFromConfiguration(string $id, array $params = []): ?LoggerInterface
	{
		$logger = null;

		$config = $this->getConfiguration()[$id] ?? null;
		if (isset($config['className']))
		{
			$class = $config['className'];

			$args = $config['constructorParams'] ?? [];
			if ($args instanceof \Closure)
			{
				$args = $args();
			}

			$logger = new $class(...array_values($args));
		}
		elseif (isset($config['constructor']))
		{
			$closure = $config['constructor'];
			if ($closure instanceof \Closure)
			{
				$logger = $closure(...array_values($params));
			}
		}

		if ($logger instanceof Logger)
		{
			if (isset($config['level']))
			{
				$logger->setLevel($config['level']);
			}

			if (isset($config['formatter']))
			{
				$serviceLocator = ServiceLocator::getInstance();
				if ($serviceLocator->has($config['formatter']))
				{
					$logger->setFormatter($serviceLocator->get($config['formatter']));
				}
			}
		}

		return $logger;
	}
}
