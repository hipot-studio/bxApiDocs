<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Error;

use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Enum\GraphErrorCode;
use Bitrix\Main\Error;

/**
 * Single shape of the domain errors of the agent graph: the address of the problem goes to Error::code,
 * the class of the error to customData['errorCode'], the server-side dictionaries to customData['details'].
 *
 * The address is the full path of the offending value in the submitted graph ('blocks.3.settings.1.name'),
 * the same notation the REST core uses to address a malformed field, so an agent reads one notation for
 * both. It stays repeated inside the message on purpose: the Marta tool flattens the errors into one
 * string, and there the in-text address is the only hint the model gets. Hence the message is passed in
 * whole and is never rebuilt from the address here.
 *
 * Every branch either addresses its problem or declares it unaddressed: an error built past these two
 * entries keeps the integer 0 Error::code defaults to, which the report used to publish as the address "0".
 */
final class GraphError
{
	/**
	 * @param array|null $details dictionaries of the server explaining the problem - allowed and required keys,
	 *        the outputs a block has, the violated constraint, the bounds. Only what DTO-02 publishes leaves the
	 *        server ({@see \Bitrix\BizprocDesigner\Infrastructure\Rest\Service\GraphValidationErrorMapper}); a
	 *        value the agent submitted never belongs here.
	 */
	public static function at(
		string $path,
		string $message,
		?GraphErrorCode $errorCode = null,
		?array $details = null,
	): Error
	{
		return new Error($message, $path, self::customData($errorCode, null, $details));
	}

	/**
	 * Error about the graph as a whole. The empty address keeps the problem from being bound to a place
	 * in the payload it does not have.
	 *
	 * A problem with no address may still be about a known block - an unclosed loop is about its loop node.
	 * That block is carried explicitly, because the report recovers the block from the address and an
	 * unaddressed error leaves it nothing to recover from.
	 */
	public static function unaddressed(
		string $message,
		?GraphErrorCode $errorCode = null,
		?string $blockId = null,
		?array $details = null,
	): Error
	{
		return new Error($message, '', self::customData($errorCode, $blockId, $details));
	}

	private static function customData(
		?GraphErrorCode $errorCode,
		?string $blockId = null,
		?array $details = null,
	): ?array
	{
		$customData = [];
		if ($errorCode !== null)
		{
			$customData['errorCode'] = $errorCode->value;
		}
		if ($blockId !== null)
		{
			$customData['blockId'] = $blockId;
		}
		if ($details !== null && $details !== [])
		{
			$customData['details'] = $details;
		}

		return $customData === [] ? null : $customData;
	}
}
