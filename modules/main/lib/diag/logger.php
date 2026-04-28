<?php

/**
 * Bitrix Framework
 * @package bitrix
 * @subpackage main
 * @copyright 2001-2025 Bitrix
 */

namespace Bitrix\Main\Diag;

use Psr\Log;
use Psr\Log\LogLevel;
use Bitrix\Main\Config;
use Bitrix\Main\DI;

abstract class Logger extends Log\AbstractLogger
{
	protected static $supportedLevels = [
		LogLevel::EMERGENCY => LOG_EMERG,
		LogLevel::ALERT => LOG_ALERT,
		LogLevel::CRITICAL => LOG_CRIT,
		LogLevel::ERROR => LOG_ERR,
		LogLevel::WARNING => LOG_WARNING,
		LogLevel::NOTICE => LOG_NOTICE,
		LogLevel::INFO => LOG_INFO,
		LogLevel::DEBUG => LOG_DEBUG,
	];

	protected $level;

	/** @var LogFormatterInterface */
	protected $formatter;

	protected array $context;
	protected string $message;

	protected function interpolate()
	{
		$formatter = $this->getFormatter();

		return $formatter->format($this->message, $this->context);
	}

	/**
	 * @inheritdoc
	 */
	public function log($level, string|\Stringable $message, array $context = []): void
	{
		// Calling this method with a level not defined by this specification MUST throw a Psr\Log\InvalidArgumentException if the implementation does not know about the level.
		if (!isset(static::$supportedLevels[$level]))
		{
			throw new Log\InvalidArgumentException("Log level {$level} is unsupported.");
		}

		if ($this->level !== null && static::$supportedLevels[$level] > $this->level)
		{
			// shouldn't log anything because of the maximum verbose level
			return;
		}

		$this->context = $context;
		$this->message = $message;

		// The message MAY contain placeholders which implementors MAY replace with values from the context array.
		$message = $this->interpolate();

		if ($message != '')
		{
			// actual logging - MUST be defined by a child class
			$this->logMessage($level, $message);
		}
	}

	abstract protected function logMessage(string $level, string $message);

	/**
	 * Sets the maximun verbose level of the logger.
	 * @param string $level One of LogLevel constants.
	 * @return $this
	 */
	public function setLevel(string $level)
	{
		if (isset(static::$supportedLevels[$level]))
		{
			$this->level = static::$supportedLevels[$level];
		}

		return $this;
	}

	/**
	 * Sets a formatter for the logger.
	 * @param LogFormatterInterface $formatter
	 * @return $this
	 */
	public function setFormatter(LogFormatterInterface $formatter)
	{
		$this->formatter = $formatter;

		return $this;
	}

	/**
	 * @deprecated 26.300.0 use LoggerFactory
	 * @see \Bitrix\Main\Diag\LoggerFactory
	 *
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
	 * @param string $id A logger ID.
	 * @param array $params An optional params to be passed to a closure in settings.
	 *
	 * @return static|null
	 */
	public static function create(string $id, $params = [])
	{
		return (new LoggerFactory(false))->createById($id, $params, false, false);
	}

	protected function getFormatter()
	{
		if ($this->formatter === null)
		{
			$this->formatter = new LogFormatter();
		}

		return $this->formatter;
	}
}
