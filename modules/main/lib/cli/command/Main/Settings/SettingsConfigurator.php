<?php

namespace Bitrix\Main\Cli\Command\Main\Settings;

use Bitrix\Main\Config\Configuration;
use Bitrix\Main\Security\Random;
use InvalidArgumentException;
use LogicException;

class SettingsConfigurator
{
	public const SECTION_CACHE = 'cache';
	public const SECTION_SESSION = 'session';
	public const SECTION_CRYPTO = 'crypto';
	public const SECTION_SMTP = 'smtp';
	public const SECTION_EXCEPTION_HANDLING = 'exception_handling';
	public const SECTION_ROUTING = 'routing';

	private const ALLOWED_SECTIONS = [
		self::SECTION_CACHE,
		self::SECTION_SESSION,
		self::SECTION_CRYPTO,
		self::SECTION_SMTP,
		self::SECTION_EXCEPTION_HANDLING,
		self::SECTION_ROUTING,
	];

	private const CACHE_ENGINES = ['files', 'redis', 'memcache', 'memcached', 'apc', 'apcu'];

	private const SESSION_MODES = ['default', 'separated'];
	private const SESSION_GENERAL_TYPES = ['file', 'database', 'redis', 'memcache', 'array', 'null'];

	private readonly ?Configuration $configuration;

	public function __construct(?Configuration $configuration = null)
	{
		$this->configuration = $configuration ?? Configuration::getInstance();
	}

	public function applyCache(array $options, bool $readonly): array
	{
		$value = $this->buildCacheValue($options);

		return $this->save(self::SECTION_CACHE, $value, $readonly);
	}

	public function applySession(array $options, bool $readonly): array
	{
		$value = $this->buildSessionValue($options);

		return $this->save(self::SECTION_SESSION, $value, $readonly);
	}

	public function applyCrypto(array $options, bool $readonly): array
	{
		$value = $this->buildCryptoValue($options);

		return $this->save(self::SECTION_CRYPTO, $value, $readonly);
	}

	public function applySmtp(array $options, bool $readonly): array
	{
		$value = $this->buildSmtpValue($options);

		return $this->save(self::SECTION_SMTP, $value, $readonly);
	}

	public function applyExceptionHandling(array $options, bool $readonly): array
	{
		$value = $this->buildExceptionHandlingValue($options);

		return $this->save(self::SECTION_EXCEPTION_HANDLING, $value, $readonly);
	}

	public function applyRouting(array $options, bool $readonly): array
	{
		$value = $this->buildRoutingValue($options);

		return $this->save(self::SECTION_ROUTING, $value, $readonly);
	}

	public function save(string $section, array $value, bool $readonly): array
	{
		if (!in_array($section, self::ALLOWED_SECTIONS, true))
		{
			throw new InvalidArgumentException('Settings section is not supported');
		}

		if (!$readonly && $this->isSectionReadonly($section))
		{
			throw new InvalidArgumentException('Settings section is readonly; use --readonly to replace it');
		}

		$this->writeSection($section, $value, $readonly);

		return $value;
	}

	protected function isSectionReadonly(string $section): bool
	{
		if ($this->configuration === null)
		{
			throw new LogicException('Settings configuration is not initialized');
		}

		return $this->configuration->isReadonly($section);
	}

	protected function readSection(string $section): mixed
	{
		if ($this->configuration === null)
		{
			throw new LogicException('Settings configuration is not initialized');
		}

		return $this->configuration->get($section);
	}

	protected function writeSection(string $section, array $value, bool $readonly): void
	{
		if ($this->configuration === null)
		{
			throw new LogicException('Settings configuration is not initialized');
		}

		if ($readonly)
		{
			$this->configuration->addReadonly($section, $value);
		}
		else
		{
			$this->configuration->add($section, $value);
		}

		$this->configuration->saveConfiguration();
	}

	/**
	 * Builds the `cache` section written to .settings.php.
	 *
	 * `type` is selected in Cache::createCacheEngine(): string engines become
	 * CacheEngineRedis / CacheEngineMemcache / CacheEngineMemcached / CacheEngineApc / CacheEngineFiles;
	 * array `type` with `class_name` is instantiated as a custom engine.
	 * Host/port for redis|memcache|memcached live in a named block; redis also
	 * accepts password. They are read by KeyValueEngine::configure(), then by
	 * the matching connection configurator.
	 *
	 * @see \Bitrix\Main\Data\Cache::createCacheEngine()
	 * @see \Bitrix\Main\Data\Cache\KeyValueEngine::configure()
	 * @see \Bitrix\Main\Data\CacheEngineRedis
	 * @see \Bitrix\Main\Data\CacheEngineMemcache
	 * @see \Bitrix\Main\Data\CacheEngineMemcached
	 * @see \Bitrix\Main\Data\CacheEngineApc
	 * @see \Bitrix\Main\Data\CacheEngineFiles
	 * @see \Bitrix\Main\Data\Configurator\RedisConnectionConfigurator
	 */
	private function buildCacheValue(array $options): array
	{
		$engine = $this->getOptionalString($options, 'engine');
		$className = $this->getOptionalString($options, 'class_name');

		if ($engine === null && $className === null)
		{
			throw new InvalidArgumentException('Cache engine or class name is required');
		}

		if ($engine !== null && $className !== null)
		{
			throw new InvalidArgumentException('Cache engine and class name cannot be used together');
		}

		if ($className !== null)
		{
			$this->assertNoCacheEngineFlags($options);

			$type = ['class_name' => $className];
			$extension = $this->getOptionalString($options, 'extension');
			if ($extension !== null)
			{
				$type['extension'] = $extension;
			}
			$requiredFile = $this->getOptionalString($options, 'required_file');
			if ($requiredFile !== null)
			{
				$type['required_file'] = $requiredFile;
			}
			$requiredRemoteFile = $this->getOptionalString($options, 'required_remote_file');
			if ($requiredRemoteFile !== null)
			{
				$type['required_remote_file'] = $requiredRemoteFile;
			}

			return ['type' => $type];
		}

		if (!in_array($engine, self::CACHE_ENGINES, true))
		{
			throw new InvalidArgumentException('Cache engine is not supported');
		}

		$this->assertNoCustomCacheFlags($options);

		if ($engine === 'redis')
		{
			$this->assertNoMemcacheFlags($options, 'memcache');
			$this->assertNoMemcacheFlags($options, 'memcached');

			$block = $this->buildKeyValueBlock($options, 'redis');
			$value = ['type' => 'redis', 'redis' => $block];

			return $value;
		}

		if ($engine === 'memcache')
		{
			$this->assertNoMemcacheFlags($options, 'redis');
			$this->assertNoMemcacheFlags($options, 'memcached');

			$block = $this->buildKeyValueBlock($options, 'memcache');
			$value = ['type' => 'memcache', 'memcache' => $block];

			return $value;
		}

		if ($engine === 'memcached')
		{
			$this->assertNoMemcacheFlags($options, 'redis');
			$this->assertNoMemcacheFlags($options, 'memcache');

			$block = $this->buildKeyValueBlock($options, 'memcached');
			$value = ['type' => 'memcached', 'memcached' => $block];

			return $value;
		}

		$this->assertNoKeyValueFlags($options);

		return ['type' => $engine];
	}

	/**
	 * Builds the `session` section written to .settings.php.
	 *
	 * SessionConfigurationResolver::resolve() reads `mode` and `handlers`.
	 * `default` uses one Session with the general handler (KernelSessionProxy).
	 * `separated` uses Session for general data and KernelSession for kernel data;
	 * kernel handler must be `encrypted_cookies` (CookiesCrypter + crypto.crypto_key).
	 *
	 * @see \Bitrix\Main\Session\SessionConfigurationResolver::resolve()
	 * @see \Bitrix\Main\Session\SessionConfigurationResolver::buildSessionHandlerByOptions()
	 * @see \Bitrix\Main\Session\Session
	 * @see \Bitrix\Main\Session\KernelSession
	 * @see \Bitrix\Main\Web\CookiesCrypter
	 */
	private function buildSessionValue(array $options): array
	{
		$mode = $this->getRequiredString($options, 'mode');
		if (!in_array($mode, self::SESSION_MODES, true))
		{
			throw new InvalidArgumentException('Session mode is not supported');
		}

		$general = $this->getRequiredString($options, 'general');
		if (!in_array($general, self::SESSION_GENERAL_TYPES, true))
		{
			throw new InvalidArgumentException('Session general handler is not supported');
		}

		$kernel = $this->getOptionalString($options, 'kernel');

		if ($mode === 'separated')
		{
			if ($kernel === null)
			{
				throw new InvalidArgumentException('Session kernel handler is required for separated mode');
			}
			if ($kernel !== 'encrypted_cookies')
			{
				throw new InvalidArgumentException('Session kernel handler is not supported');
			}
			if (!$this->hasCryptoKey())
			{
				throw new InvalidArgumentException(
					'Separated session mode requires a crypto key. Run: php bitrix.php main:settings:crypto-set'
				);
			}
		}
		elseif ($kernel !== null)
		{
			throw new InvalidArgumentException('Session kernel handler cannot be used in default mode');
		}

		$generalHandler = $this->buildSessionGeneralHandler($general, $options);

		$value = [
			'mode' => $mode,
			'handlers' => [
				'general' => $generalHandler,
			],
		];

		if ($mode === 'separated')
		{
			$value['handlers']['kernel'] = 'encrypted_cookies';
		}

		return $value;
	}

	/**
	 * Builds `handlers.general`: redis/memcache with host/port, redis may include password.
	 *
	 * @see \Bitrix\Main\Session\SessionConfigurationResolver::buildSessionHandlerByOptions()
	 * @see \Bitrix\Main\Session\Handlers\RedisSessionHandler
	 * @see \Bitrix\Main\Session\Handlers\MemcacheSessionHandler
	 * @see \Bitrix\Main\Session\Handlers\NativeFileSessionHandler
	 * @see \Bitrix\Main\Session\Handlers\DatabaseSessionHandler
	 * @see \Bitrix\Main\Session\Handlers\NullSessionHandler
	 */
	private function buildSessionGeneralHandler(string $general, array $options): array
	{
		if ($general === 'redis')
		{
			$this->assertNoSessionMemcacheFlags($options, 'memcache');

			$handler = ['type' => 'redis'];
			$handler = array_merge($handler, $this->buildSessionConnectionOptions($options, 'redis'));

			return $handler;
		}

		if ($general === 'memcache')
		{
			$this->assertNoSessionMemcacheFlags($options, 'redis');

			$handler = ['type' => 'memcache'];
			$handler = array_merge($handler, $this->buildSessionConnectionOptions($options, 'memcache'));

			return $handler;
		}

		$this->assertNoSessionConnectionFlags($options);

		return ['type' => $general];
	}

	/**
	 * Builds `{crypto_key}` used to encrypt cookies of a separated kernel session.
	 *
	 * @see \Bitrix\Main\Web\CookiesCrypter::buildCipher()
	 * @see \Bitrix\Main\Session\KernelSession
	 */
	private function buildCryptoValue(array $options): array
	{
		$key = $this->getOptionalString($options, 'key');
		if ($key === '')
		{
			throw new InvalidArgumentException('Crypto key cannot be empty');
		}
		if ($key === null)
		{
			$key = Random::getString(32, false);
		}

		return ['crypto_key' => $key];
	}

	/**
	 * Patches the `smtp` section. `enabled` gates SMTP outside Bitrix24;
	 * host/port/login/password/from are applied by Mailer when sending.
	 *
	 * @see \Bitrix\Main\Mail\Smtp\Mailer::getActualConfiguration()
	 * @see \Bitrix\Main\Mail\Sender\UserSenderDataProvider
	 * @see \Bitrix\Main\Mail\Providers\ShowcaseParams
	 */
	private function buildSmtpValue(array $options): array
	{
		$fields = ['enabled', 'host', 'port', 'login', 'password', 'from'];
		$hasField = false;

		foreach ($fields as $field)
		{
			if (array_key_exists($field, $options) && $options[$field] !== null)
			{
				$hasField = true;
				break;
			}
		}

		if (!$hasField)
		{
			throw new InvalidArgumentException('At least one SMTP field is required');
		}

		$current = $this->readSection(self::SECTION_SMTP);
		$isNew = !is_array($current) || $current === [];
		$value = is_array($current) ? $current : [];

		if (array_key_exists('enabled', $options) && $options['enabled'] !== null)
		{
			$value['enabled'] = (bool)$options['enabled'];
		}

		foreach (['host', 'login', 'password', 'from'] as $field)
		{
			if (array_key_exists($field, $options) && $options[$field] !== null)
			{
				$value[$field] = (string)$options[$field];
			}
		}

		if (array_key_exists('port', $options) && $options['port'] !== null)
		{
			$value['port'] = (int)$options['port'];
		}

		if ($isNew)
		{
			$isDisableOnly = array_keys($value) === ['enabled'] && $value['enabled'] === false;
			if (!$isDisableOnly)
			{
				foreach (['host', 'login', 'password', 'from'] as $field)
				{
					if (!isset($value[$field]) || $value[$field] === '')
					{
						throw new InvalidArgumentException(
							'SMTP host, login, password and from are required for a new enabled section'
						);
					}
				}
			}
		}

		return $value;
	}

	/**
	 * Patches `debug` and `log.settings.file` of `exception_handling`.
	 *
	 * `debug` is passed to ExceptionHandler::setDebugMode() from Application::initializeExceptionHandler().
	 * `log.settings.file` is read by Application::createExceptionHandlerLog() and FileExceptionHandlerLog::initialize().
	 *
	 * @see \Bitrix\Main\Application::initializeExceptionHandler()
	 * @see \Bitrix\Main\Application::createExceptionHandlerLog()
	 * @see \Bitrix\Main\Diag\ExceptionHandler::setDebugMode()
	 * @see \Bitrix\Main\Diag\FileExceptionHandlerLog::initialize()
	 */
	private function buildExceptionHandlingValue(array $options): array
	{
		$hasDebug = array_key_exists('debug', $options) && $options['debug'] !== null;
		$hasLogFile = array_key_exists('log_file', $options) && $options['log_file'] !== null;

		if (!$hasDebug && !$hasLogFile)
		{
			throw new InvalidArgumentException('At least one exception handling field is required');
		}

		$current = $this->readSection(self::SECTION_EXCEPTION_HANDLING);
		$value = is_array($current) ? $current : [];

		if ($hasDebug)
		{
			$value['debug'] = (bool)$options['debug'];
		}

		if ($hasLogFile)
		{
			$log = $value['log'] ?? [];
			if (!is_array($log))
			{
				$log = [];
			}
			$settings = $log['settings'] ?? [];
			if (!is_array($settings))
			{
				$settings = [];
			}
			$settings['file'] = (string)$options['log_file'];
			$log['settings'] = $settings;
			$value['log'] = $log;
		}

		return $value;
	}

	/**
	 * Patches `routing.config` — user route files under `/local/routes/` and `/bitrix/routes/`.
	 *
	 * Loaded by Application::initializeRouter() into RoutingConfigurator / Router.
	 * System routes from `/bitrix/routes/.settings.php` are not modified here.
	 *
	 * @see \Bitrix\Main\Application::initializeRouter()
	 * @see \Bitrix\Main\Routing\RoutingConfigurator
	 * @see \Bitrix\Main\Routing\Router
	 */
	private function buildRoutingValue(array $options): array
	{
		$toAdd = $options['add'] ?? [];
		$toRemove = $options['remove'] ?? [];

		if (!is_array($toAdd))
		{
			$toAdd = [];
		}
		if (!is_array($toRemove))
		{
			$toRemove = [];
		}

		if ($toAdd === [] && $toRemove === [])
		{
			throw new InvalidArgumentException('At least one routing action is required');
		}

		$current = $this->readSection(self::SECTION_ROUTING);
		$value = is_array($current) ? $current : [];
		$config = $value['config'] ?? [];
		if (!is_array($config))
		{
			$config = [];
		}

		foreach ($toRemove as $name)
		{
			$name = (string)$name;
			$index = array_search($name, $config, true);
			if ($index !== false)
			{
				unset($config[$index]);
			}
		}

		$config = array_values($config);

		foreach ($toAdd as $name)
		{
			$name = (string)$name;
			if (!in_array($name, $config, true))
			{
				$config[] = $name;
			}
		}

		$value['config'] = $config;

		return $value;
	}

	/**
	 * Host/port for `cache.redis` / `cache.memcache` / `cache.memcached`.
	 * Password is stored only for redis — memcache configurators ignore it.
	 *
	 * @see \Bitrix\Main\Data\Cache\KeyValueEngine::configure()
	 * @see \Bitrix\Main\Data\RedisConnection
	 * @see \Bitrix\Main\Data\MemcacheConnection
	 * @see \Bitrix\Main\Data\MemcachedConnection
	 */
	private function buildKeyValueBlock(array $options, string $prefix): array
	{
		$host = $this->getRequiredPrefixedOption($options, $prefix, 'host');
		$port = $this->getRequiredPrefixedOption($options, $prefix, 'port');

		$block = [
			'host' => $host,
			'port' => (int)$port,
		];

		$password = $this->getOptionalPrefixedOption($options, $prefix, 'password');
		if ($password !== null)
		{
			if ($prefix !== 'redis')
			{
				throw new InvalidArgumentException(ucfirst($prefix) . ' password is not supported');
			}
			$block['password'] = $password;
		}

		return $block;
	}

	/**
	 * Host/port merged into `handlers.general` for redis/memcache sessions.
	 * Password is stored only for redis.
	 *
	 * @see \Bitrix\Main\Session\Handlers\RedisSessionHandler::__construct()
	 * @see \Bitrix\Main\Session\Handlers\MemcacheSessionHandler::__construct()
	 * @see \Bitrix\Main\Data\RedisConnection
	 */
	private function buildSessionConnectionOptions(array $options, string $prefix): array
	{
		$host = $this->getRequiredPrefixedOption($options, $prefix, 'host');
		$port = $this->getRequiredPrefixedOption($options, $prefix, 'port');

		$result = [
			'host' => $host,
			'port' => (int)$port,
		];

		$password = $this->getOptionalPrefixedOption($options, $prefix, 'password');
		if ($password !== null)
		{
			if ($prefix !== 'redis')
			{
				throw new InvalidArgumentException('Memcache password is not supported');
			}

			$result['password'] = $password;
		}

		return $result;
	}

	private function hasCryptoKey(): bool
	{
		$crypto = $this->readSection(self::SECTION_CRYPTO);
		if (!is_array($crypto))
		{
			return false;
		}

		$key = $crypto['crypto_key'] ?? null;

		return is_string($key) && $key !== '';
	}

	private function assertNoCacheEngineFlags(array $options): void
	{
		if ($this->getOptionalString($options, 'engine') !== null)
		{
			throw new InvalidArgumentException('Cache engine cannot be used with class name');
		}

		$this->assertNoKeyValueFlags($options);
	}

	private function assertNoCustomCacheFlags(array $options): void
	{
		if ($this->getOptionalString($options, 'class_name') !== null)
		{
			throw new InvalidArgumentException('Cache class name cannot be used with engine');
		}

		if (
			$this->getOptionalString($options, 'extension') !== null
			|| $this->getOptionalString($options, 'required_file') !== null
			|| $this->getOptionalString($options, 'required_remote_file') !== null
		)
		{
			throw new InvalidArgumentException('Custom cache options cannot be used with engine');
		}
	}

	private function assertNoKeyValueFlags(array $options): void
	{
		$this->assertNoMemcacheFlags($options, 'redis');
		$this->assertNoMemcacheFlags($options, 'memcache');
		$this->assertNoMemcacheFlags($options, 'memcached');
	}

	private function assertNoMemcacheFlags(array $options, string $prefix): void
	{
		foreach (['host', 'port', 'password'] as $suffix)
		{
			if ($this->getOptionalPrefixedOption($options, $prefix, $suffix) !== null)
			{
				throw new InvalidArgumentException('Cache connection options are invalid for selected engine');
			}
		}
	}

	private function assertNoSessionConnectionFlags(array $options): void
	{
		$this->assertNoSessionMemcacheFlags($options, 'redis');
		$this->assertNoSessionMemcacheFlags($options, 'memcache');
	}

	private function assertNoSessionMemcacheFlags(array $options, string $prefix): void
	{
		foreach (['host', 'port', 'password'] as $suffix)
		{
			if ($this->getOptionalPrefixedOption($options, $prefix, $suffix) !== null)
			{
				throw new InvalidArgumentException('Session connection options are invalid for selected handler');
			}
		}
	}

	private function getRequiredString(array $options, string $key): string
	{
		$value = $this->getOptionalString($options, $key);
		if ($value === null)
		{
			throw new InvalidArgumentException(ucfirst(str_replace('_', ' ', $key)) . ' is required');
		}

		return $value;
	}

	private function getOptionalString(array $options, string $key): ?string
	{
		if (!array_key_exists($key, $options) || $options[$key] === null)
		{
			return null;
		}

		return (string)$options[$key];
	}

	private function getRequiredPrefixedOption(array $options, string $prefix, string $suffix): string
	{
		$key = $prefix . '_' . $suffix;
		$value = $this->getOptionalString($options, $key);
		if ($value === null)
		{
			throw new InvalidArgumentException(ucfirst($prefix) . ' ' . $suffix . ' is required');
		}

		return $value;
	}

	private function getOptionalPrefixedOption(array $options, string $prefix, string $suffix): ?string
	{
		return $this->getOptionalString($options, $prefix . '_' . $suffix);
	}
}
