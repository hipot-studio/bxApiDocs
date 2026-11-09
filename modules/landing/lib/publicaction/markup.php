<?php

namespace Bitrix\Landing\PublicAction;

use Bitrix\Landing\Error;
use Bitrix\Landing\Integration\AiAssistant\Exception\MarkupRequestRefusedException;
use Bitrix\Landing\Integration\AiAssistant\Rest\GetMarkupFileRestAction;
use Bitrix\Landing\Integration\AiAssistant\Rest\PutMarkupFileRestAction;
use Bitrix\Landing\PublicActionResult;

/**
 * REST facade of the markup package files for the AI assistant bridge: the upload and the read of a file.
 * Every public method of this class is a REST method: keep putFile() and getFile() the only ones.
 */
class Markup
{
	/**
	 * Puts one whole file of a markup package into the upload buffer.
	 * @param string|null $path File path inside the package.
	 * @param string|null $content Base64 of the whole file.
	 * @param string|null $uploadId Buffer to add the file to.
	 * @param string|null $mode Target mode of a new buffer: create or update.
	 * @param int|null $bindingId Chat binding of the page context a new buffer is opened by, in both modes.
	 * @param string|null $sha256 Hex sha256 of the decoded file, checked before the file is stored.
	 * @return PublicActionResult
	 */
	public static function putFile(
		$path = null,
		$content = null,
		$uploadId = null,
		$mode = null,
		$bindingId = null,
		$sha256 = null,
	): PublicActionResult
	{
		$result = new PublicActionResult();
		$fields = array_filter(
			[
				'path' => $path,
				'content' => $content,
				'uploadId' => $uploadId,
				'mode' => $mode,
				'bindingId' => $bindingId,
				'sha256' => $sha256,
			],
			static fn($value): bool => $value !== null,
		);

		try
		{
			$result->setResult((new PutMarkupFileRestAction())->execute($fields));
		}
		catch (MarkupRequestRefusedException $exception)
		{
			$error = new Error();
			$error->addError($exception->getErrorCode(), $exception->getMessage());
			$result->setError($error);
		}

		return $result;
	}

	/**
	 * Reads one whole file of the current package version of the page the binding names.
	 * @param int|null $bindingId Chat binding of the page context of the user.
	 * @param string|null $path File path inside the package.
	 * @return PublicActionResult Result: path, base64 content, sha256 hash and size of the file.
	 */
	public static function getFile($bindingId = null, $path = null): PublicActionResult
	{
		$result = new PublicActionResult();
		$fields = array_filter(
			[
				'bindingId' => $bindingId,
				'path' => $path,
			],
			static fn($value): bool => $value !== null,
		);

		try
		{
			$result->setResult((new GetMarkupFileRestAction())->execute($fields));
		}
		catch (MarkupRequestRefusedException $exception)
		{
			$error = new Error();
			$error->addError($exception->getErrorCode(), $exception->getMessage());
			$result->setError($error);
		}

		return $result;
	}
}
