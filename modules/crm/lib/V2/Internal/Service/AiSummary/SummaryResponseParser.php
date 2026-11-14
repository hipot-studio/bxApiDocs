<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\AiSummary;

use Bitrix\Crm\Integration\AI\Dto\ClientSummaryData;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Web\Json;

/**
 * Reads the answer of the model and either hands over the checked summary or says that there is
 * none.
 *
 * The caller has two branches and no third one: a successful result carries {@see ClientSummaryData}
 * under {@see self::SUMMARY_KEY}, a failed one carries the reason. Nothing is thrown on the ordinary
 * path - an answer of a model is expected to be wrong now and then, and a wrong one is an error of
 * generation, not an exception.
 *
 * The reason is written for the log and never carries the text of the model: an answer that broke the
 * contract is not shown to a person in any form (AC-020), so the message about the failure is written
 * by the sender of the chat and not taken from here.
 *
 * The contract itself is stated once, by {@see ClientSummaryData} - the framework of the AI operations
 * checks the payload by itself, and a second statement of the same rules here would be a second
 * verdict on the same answer.
 *
 * @internal
 */
final class SummaryResponseParser
{
	public const SUMMARY_KEY = 'summary';

	public const ERROR_ANSWER_IS_NOT_JSON = 'CLIENT_SUMMARY_ANSWER_IS_NOT_JSON';
	public const ERROR_ANSWER_BREAKS_CONTRACT = 'CLIENT_SUMMARY_ANSWER_BREAKS_CONTRACT';

	public static function parse(?string $rawAnswer): Result
	{
		$result = new Result();

		$fields = self::decodeObject((string)$rawAnswer);
		if ($fields === null)
		{
			return $result->addError(new Error(
				'The answer of the model carries no JSON object',
				self::ERROR_ANSWER_IS_NOT_JSON,
			));
		}

		$summary = new ClientSummaryData($fields);
		if ($summary->hasValidationErrors())
		{
			return $result->addError(new Error(
				'The answer of the model does not match the contract of the summary',
				self::ERROR_ANSWER_BREAKS_CONTRACT,
				['violations' => $summary->getValidationErrors()->toArray()],
			));
		}

		return $result->setData([self::SUMMARY_KEY => $summary]);
	}

	/**
	 * The text around the object is cut off the same way the framework of the operations cuts it
	 * ({@see \Bitrix\Crm\Integration\AI\Operation\AbstractOperation::extractPayloadPrettifiedData()}).
	 * The parser is given the answer as it came, and a model that wrapped a valid object in a sentence
	 * of its own is not a reason to refuse a summary the data is fit for.
	 */
	private static function decodeObject(string $rawAnswer): ?array
	{
		$start = strpos($rawAnswer, '{');
		$end = strrpos($rawAnswer, '}');
		if ($start === false || $end === false || $end < $start)
		{
			return null;
		}

		try
		{
			$decoded = Json::decode(substr($rawAnswer, $start, $end - $start + 1));
		}
		catch (ArgumentException)
		{
			return null;
		}

		return is_array($decoded) ? $decoded : null;
	}
}
