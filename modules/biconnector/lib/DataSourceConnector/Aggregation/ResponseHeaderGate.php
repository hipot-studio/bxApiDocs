<?php

namespace Bitrix\BIConnector\DataSourceConnector\Aggregation;

use Bitrix\Main\SystemException;

/**
 * Recognizes an aggregated answer of an external application as applied and counts its data rows
 * (ALG-02).
 *
 * The frame of the answer is part of the contract (API-02): line 1 is exactly `[`, the header line
 * naming the response columns is the second one, and the closing `]` is the last line as a whole,
 * with no line break after it. Checking only the first line would be pointless - it is always `[`.
 *
 * The body is never parsed: only the two first lines and the closing one are read, and the rest of
 * the body is measured by its line boundaries alone. The gate makes no copy of the body.
 *
 * The gate neither transports nor prints: the caller emits the body only after the check has
 * succeeded as a whole, because the entry point hands the spool to the consumer regardless of the
 * success of the execution and a refusal after the first byte would leave a partial body in the
 * answer.
 */
final class ResponseHeaderGate
{
	/** The line `[` and the header line are not data rows; the closing `]` carries no line break. */
	private const NON_DATA_LINE_BREAKS = 2;

	/**
	 * @param string[] $expectedAliases Response columns in wire order, as getResponseAliases() reports
	 *     them - with AVG already expanded into the sum/count pair.
	 */
	public function __construct(
		private readonly array $expectedAliases,
	)
	{
	}

	/**
	 * Verifies the frame and the header line of the answer and counts its data rows.
	 *
	 * @param string $body Answer of the application as it came, untrusted.
	 *
	 * @return int Number of data rows between the header line and the closing `]`.
	 *
	 * @throws SystemException On a broken frame or a header line other than the expected one.
	 */
	public function verify(string $body): int
	{
		$firstBreak = strpos($body, "\n");
		if ($firstBreak === false)
		{
			// An empty body, or a body shorter than three lines: there is no header line to check.
			throw new SystemException('aggregation header missing');
		}

		$secondBreak = strpos($body, "\n", $firstBreak + 1);
		if ($secondBreak === false)
		{
			throw new SystemException('aggregation header missing');
		}

		if (substr($body, 0, $firstBreak) !== '[')
		{
			// `[\r` belongs here as well: the separator of the protocol is exactly "\n".
			throw new SystemException('aggregation frame mismatch');
		}

		$header = substr($body, $firstBreak + 1, $secondBreak - $firstBreak - 1);
		if (str_ends_with($header, "\r"))
		{
			// Checked explicitly: a JSON parse would tolerate the trailing "\r", while CRLF would be a
			// broken frame - the gate reads two lines only, and the data rows travel as they came.
			throw new SystemException('aggregation frame mismatch');
		}

		// Decoded without the associative mode on purpose: the contract asks for an array of column
		// names, and an object with numeric keys would decode into exactly the expected list in that mode
		// while the body around it is in another frame. A JSON array still decodes into a list here.
		if (json_decode($header) !== $this->expectedAliases)
		{
			throw new SystemException('aggregation header mismatch');
		}

		$lastBreak = strrpos($body, "\n");
		if (substr($body, $lastBreak + 1) !== ']')
		{
			// A body cut off on a data row ends with `]` too, but that `]` is not the closing line, and a
			// line break after the closing one leaves an empty last line here.
			throw new SystemException('aggregation frame mismatch');
		}

		return substr_count($body, "\n") - self::NON_DATA_LINE_BREAKS;
	}
}
