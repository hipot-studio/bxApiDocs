<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Logger;

use Bitrix\Main\Type\DateTime;
use DateTimeZone;
use Psr\Log\LoggerInterface;
use Stringable;

/**
 * Wrapper for underlying logger
 *
 * Can add extra context, such as date time
 * Allows remove checks for the existence of logger instance before writing
 */
class LoggerWrapper implements LoggerInterface
{
	public const DATE_TIME_CONTEXT_KEY = 'dateTime';

	/**
	 * @param LoggerInterface|null $logger
	 * @param array $context
	 * @param bool $shouldAddDateTime
	 */
	public function __construct(
		protected readonly ?LoggerInterface $logger,
		protected array $context = [],
		protected bool $shouldAddDateTime = true,
	)
	{
	}

	/**
	 * {@inheritDoc}
	 */
	public function emergency(Stringable|string $message, array $context = []): void
	{
		$this->logger?->emergency($message, $this->prepareContext($context));
	}

	/**
	 * {@inheritDoc}
	 */
	public function alert(Stringable|string $message, array $context = []): void
	{
		$this->logger?->alert($message, $this->prepareContext($context));
	}

	/**
	 * {@inheritDoc}
	 */
	public function critical(Stringable|string $message, array $context = []): void
	{
		$this->logger?->critical($message, $this->prepareContext($context));
	}

	/**
	 * {@inheritDoc}
	 */
	public function error(Stringable|string $message, array $context = []): void
	{
		$this->logger?->error($message, $this->prepareContext($context));
	}

	/**
	 * {@inheritDoc}
	 */
	public function warning(Stringable|string $message, array $context = []): void
	{
		$this->logger?->warning($message, $this->prepareContext($context));
	}

	/**
	 * {@inheritDoc}
	 */
	public function notice(Stringable|string $message, array $context = []): void
	{
		$this->logger?->notice($message, $this->prepareContext($context));
	}

	/**
	 * {@inheritDoc}
	 */
	public function info(Stringable|string $message, array $context = []): void
	{
		$this->logger?->info($message, $this->prepareContext($context));
	}

	/**
	 * {@inheritDoc}
	 */
	public function debug(Stringable|string $message, array $context = []): void
	{
		$this->logger?->debug($message, $this->prepareContext($context));
	}

	/**
	 * {@inheritDoc}
	 */
	public function log($level, Stringable|string $message, array $context = []): void
	{
		$this->logger?->log($level, $message, $this->prepareContext($context));
	}

	/**
	 * Merge existing and provided contexts.
	 *
	 * @param array $context
	 * @return array
	 */
	protected function prepareContext(array $context): array
	{
		$fullContext = array_merge($this->context, $context);

		if ($this->shouldAddDateTime && !array_key_exists(static::DATE_TIME_CONTEXT_KEY, $fullContext))
		{
			$fullContext[static::DATE_TIME_CONTEXT_KEY] =
				DateTime
					::createFromTimestamp(time())
					->setTimeZone(new DateTimeZone('UTC'))
					->format(DATE_ATOM)
			;
		}

		return $fullContext;
	}
}