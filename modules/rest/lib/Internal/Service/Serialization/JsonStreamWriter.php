<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Service\Serialization;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Web\Json;
use Closure;
use JsonException;

/**
 * Serializes a ready structure into JSON and hands the bytes to a sink in chunks
 * as the internal buffer fills up.
 *
 * Structural characters are the only thing produced here: every value that is not a PHP array
 * and every object key goes through json_encode() with Json::DEFAULT_OPTIONS. That is what keeps
 * the result byte-for-byte equal to Json::encode() of the whole structure.
 *
 * Only the upper levels are walked node by node. Deeper down a compact subtree is encoded by a
 * single call instead: walking it would buy no streaming (the subtree is small) while costing
 * one encoder call per scalar inside it.
 */
final class JsonStreamWriter
{
	/** Mirrors the default $depth of json_encode(), so the writer fails exactly where Json::encode() fails. */
	public const MAX_DEPTH = 512;

	public const DEFAULT_BUFFER_SIZE = 8192;

	/**
	 * The levels that carry the response envelope: walking them is what lets the body leave in
	 * pieces at all, regardless of how few children they have.
	 */
	private const STREAMED_LEVELS = 3;

	/** Above this an array is treated as a collection worth walking, below it as a leaf worth encoding whole. */
	private const WHOLE_SUBTREE_CHILDREN = 64;

	private const DEPTH_ERROR_MESSAGE = 'JSON error: Maximum stack depth exceeded [' . JSON_ERROR_DEPTH . ']';

	private Closure $sink;

	private string $buffer = '';

	public function __construct(
		callable $sink,
		private readonly int $bufferSize = self::DEFAULT_BUFFER_SIZE,
		private readonly int $maxDepth = self::MAX_DEPTH,
	)
	{
		$this->sink = Closure::fromCallable($sink);
	}

	/**
	 * Streams the structure to the sink and flushes whatever is left in the buffer.
	 *
	 * @param mixed $node Any value acceptable to Json::encode().
	 * @return void
	 * @throws ArgumentException
	 */
	public function writeDocument(mixed $node): void
	{
		$this->write($node, $this->maxDepth, 0);
		$this->flush();
	}

	/**
	 * @throws ArgumentException
	 */
	private function write(mixed $node, int $depthLeft, int $level): void
	{
		if (!is_array($node))
		{
			$this->emit($this->encode($node, $depthLeft));

			return;
		}

		// json_encode() spends a nesting level on an empty array too, so the guard runs before the shortcut
		if ($depthLeft <= 0)
		{
			throw new ArgumentException(self::DEPTH_ERROR_MESSAGE, 'data');
		}

		if ($node === [])
		{
			$this->emit('[]');

			return;
		}

		if ($level >= self::STREAMED_LEVELS && count($node) <= self::WHOLE_SUBTREE_CHILDREN)
		{
			$this->emit($this->encode($node, $depthLeft));

			return;
		}

		$isList = array_is_list($node);
		$this->emit($isList ? '[' : '{');

		$first = true;
		foreach ($node as $key => $value)
		{
			if (!$first)
			{
				$this->emit(',');
			}
			$first = false;

			if (!$isList)
			{
				$this->emit($this->encode((string)$key, $depthLeft));
				$this->emit(':');
			}

			$this->write($value, $depthLeft - 1, $level + 1);
		}

		$this->emit($isList ? ']' : '}');
	}

	/**
	 * Json::encode() cannot be used directly here: it has no way to pass the remaining nesting
	 * budget, so a subtree encoded whole would silently get the full 512 levels of its own and
	 * accept a document the encoder rejects. The options and the error text stay the encoder's.
	 *
	 * The budget goes over as it is, an exhausted one included: json_encode() takes a depth of zero
	 * to mean that only a value opening no structure of its own still fits, and an object here is
	 * exactly the case that must not fit.
	 *
	 * @throws ArgumentException
	 */
	private function encode(mixed $node, int $depthLeft): string
	{
		try
		{
			return json_encode($node, Json::DEFAULT_OPTIONS | JSON_THROW_ON_ERROR, $depthLeft);
		}
		catch (JsonException $exception)
		{
			throw new ArgumentException(
				sprintf('JSON error: %s [%d]', $exception->getMessage(), $exception->getCode()),
				'data',
			);
		}
	}

	private function emit(string $chunk): void
	{
		$this->buffer .= $chunk;

		if (strlen($this->buffer) >= $this->bufferSize)
		{
			$this->flush();
		}
	}

	private function flush(): void
	{
		if ($this->buffer === '')
		{
			return;
		}

		($this->sink)($this->buffer);
		$this->buffer = '';
	}
}
