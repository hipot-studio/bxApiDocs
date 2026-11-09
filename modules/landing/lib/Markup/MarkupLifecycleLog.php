<?php

declare(strict_types=1);

namespace Bitrix\Landing\Markup;

use Bitrix\Landing\Integration\AiAssistant\Log;

/**
 * Lifecycle journal of a markup generation: one line per event of the chat reservation and of the site
 * the generation builds - submission, start, finalization, reaction on the terminal outcome, removal of a
 * partial site, cancel, sweep, refusal of the file controller. Every line carries the correlation fields
 * first, so the chain of one generation is restored from the journal by its jobId.
 *
 * The journal lives in the file journal of the AI sites tools and is written only while that one is
 * enabled. Nothing of the package reaches it: a content field is replaced with its length, and so is
 * any long string - a base64 part or an attachment passed by mistake.
 */
class MarkupLifecycleLog
{
	public const EVENT_SUBMIT = 'submit';
	public const EVENT_START = 'start';
	public const EVENT_FINALIZE = 'finalize';
	public const EVENT_REACTION = 'reaction';
	public const EVENT_REMOVAL = 'removal';
	public const EVENT_CANCEL = 'cancel';
	public const EVENT_SWEEP = 'sweep';
	public const EVENT_CONTROLLER_REFUSAL = 'controller_refusal';

	/**
	 * Fields every event is correlated by, in the order they are written.
	 */
	public const CORRELATION_FIELDS = [
		'jobId',
		'bindingId',
		'uploadId',
		'siteId',
		'userId',
		'errorType',
		'errorCode',
		'step',
		'reason',
	];

	private const MASKED_KEY = 'content';
	private const MAX_VALUE_LENGTH = 200;

	/**
	 * Writes one event. Never throws: the journal must not change the outcome of what it describes.
	 */
	public function write(string $event, array $fields): void
	{
		try
		{
			if (!$this->isEnabled())
			{
				return;
			}

			$this->emit($this->format($event, $fields));
		}
		catch (\Throwable)
		{
			// a journal that cannot be written loses the line, not the operation
		}
	}

	public function format(string $event, array $fields): string
	{
		$ordered = [];
		foreach (self::CORRELATION_FIELDS as $name)
		{
			if (array_key_exists($name, $fields))
			{
				$ordered[$name] = $fields[$name];
			}
		}
		$ordered += $fields;

		$encoded = json_encode(self::mask($ordered), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

		return 'Markup lifecycle ' . date('Y-m-d H:i:s') . ' ' . $event . ' '
			. (is_string($encoded) ? $encoded : '{}');
	}

	/**
	 * Replaces the content fields and every long string with its length, at any depth.
	 */
	public static function mask(array $fields): array
	{
		foreach ($fields as $key => $value)
		{
			if (is_array($value))
			{
				$fields[$key] = self::mask($value);

				continue;
			}

			if (!is_string($value))
			{
				continue;
			}

			if (
				(is_string($key) && strtolower($key) === self::MASKED_KEY)
				|| strlen($value) > self::MAX_VALUE_LENGTH
			)
			{
				$fields[$key] = '[masked ' . strlen($value) . ' bytes]';
			}
		}

		return $fields;
	}

	protected function isEnabled(): bool
	{
		return Log::isEnabled();
	}

	protected function emit(string $line): void
	{
		(new Log())->add($line);
	}
}
