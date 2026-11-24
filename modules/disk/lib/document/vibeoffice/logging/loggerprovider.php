<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice\Logging;

use Bitrix\Main\DI\ServiceLocator;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class LoggerProvider extends AbstractLogger
{
	private const EVENTS = [
		'disk.vibeoffice.open' => [
			'vibeoffice open', 'vibeoffice editor failure', 'vibeoffice editor recovered',
			'vibeoffice editor recovery risk', 'vibeoffice editor loading diagnostic',
		],
		'disk.vibeoffice.webhook' => ['vibeoffice webhook received', 'vibeoffice webhook diagnostic'],
		'disk.vibeoffice.state' => 'vibeoffice state',
		'disk.vibeoffice.protocol' => 'vibeoffice platform request',
		'disk.vibeoffice.finalizer' => 'vibeoffice finalizer',
		'disk.vibeoffice.editslot' => 'vibeoffice finalizer',
		'disk.vibeoffice.component' => 'vibeoffice component',
		'disk.vibeoffice.maintenance' => 'vibeoffice maintenance',
	];

	private function __construct(
		private readonly string $id,
		private readonly LoggerInterface $sink,
		private readonly SafeEventFormatter $formatter,
	)
	{}

	public static function get(string $id): LoggerInterface
	{
		try
		{
			$sink = isset(self::EVENTS[$id]) ? ServiceLocator::getInstance()->get($id) : null;
			return new self(
				$id,
				$sink instanceof LoggerInterface ? $sink : new NullLogger(),
				new SafeEventFormatter(),
			);
		}
		catch (\Throwable)
		{
			return new self($id, new NullLogger(), new SafeEventFormatter());
		}
	}

	public function log($level, string|\Stringable $message, array $context = []): void
	{
		try
		{
			if ($this->id === 'disk.vibeoffice.maintenance' && $message !== 'vibeoffice maintenance')
			{
				return;
			}
			$event = is_string($message) && in_array($message, (array)(self::EVENTS[$this->id] ?? []), true)
				? $message
				: 'vibeoffice unknown';
			$entry = $this->formatter->format($event, ['level' => $level] + $context);
			@$this->sink->log($level, $entry, []);
		}
		catch (\Throwable)
		{
			// Diagnostics must not change the result of the observed operation.
		}
	}
}
