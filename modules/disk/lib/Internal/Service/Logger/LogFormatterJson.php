<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Logger;

use Bitrix\Main\Diag\LogFormatter;
use Bitrix\Main\Web\Json;
use JsonSerializable;
use Stringable;

class LogFormatterJson extends LogFormatter
{
	protected bool $lineBreakAfterEachMessage;

	/**
	 * @param bool $showArguments
	 * @param int $argMaxChars
	 * @param bool $lineBreakAfterEachMessage
	 */
	public function __construct(
		bool $showArguments = false,
		int $argMaxChars = 30,
		bool $lineBreakAfterEachMessage = false,
	)
	{
		parent::__construct($showArguments, $argMaxChars);

		$this->lineBreakAfterEachMessage = $lineBreakAfterEachMessage;
	}

	/**
	 * @inheritDoc
	 */
	public function format($message, array $context = []): string
	{
		$message = parent::format($message, $context);

		$preparedContext = [
			'message' => $message,
		];

		foreach ($context as $key => $value)
		{
			$preparedContext[$key] = $this->toJson($value);
		}

		$result = Json::encode($preparedContext);

		if ($this->lineBreakAfterEachMessage)
		{
			$result .= PHP_EOL;
		}

		return $result;
	}

	/**
	 * @param mixed $value
	 * @return mixed
	 */
	protected function toJson(mixed $value): mixed
	{
		if (!$value instanceof JsonSerializable && $value instanceof Stringable)
		{
			return (string)$value;
		}

		return $value;
	}

	/**
	 * @inheritDoc
	 */
	protected function formatMixed($value): string
	{
		return Json::encode($this->toJson($value));
	}
}