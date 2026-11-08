<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\DarkTheme;

use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Loads a message body into a DOM tree without touching the network.
 *
 * Broken markup is the norm in mail, so libxml complaints are swallowed instead of failing the load:
 * only a body that produced no tree at all is a failure.
 */
class DocumentLoader
{
	public const DOCUMENT_KEY = 'document';

	public const ERROR_PARSE_FAILED = 'PARSE_FAILED';

	private const ENCODING_DECLARATION = '<?xml encoding="UTF-8">';
	private const PARSE_OPTIONS = LIBXML_NONET | LIBXML_COMPACT | LIBXML_HTML_NODEFDTD;

	/**
	 * Parses the body into a document.
	 *
	 * @param string $body
	 * @return Result Data: the parsed document.
	 */
	public function load(string $body): Result
	{
		$result = new Result();

		$previousErrorState = libxml_use_internal_errors(true);
		libxml_clear_errors();

		try
		{
			$document = new \DOMDocument('1.0', 'UTF-8');
			$loaded = $document->loadHTML(self::ENCODING_DECLARATION . $body, self::PARSE_OPTIONS);
		}
		finally
		{
			libxml_clear_errors();
			libxml_use_internal_errors($previousErrorState);
		}

		if (!$loaded)
		{
			return $result->addError(
				new Error('Message body produced no document.', self::ERROR_PARSE_FAILED)
			);
		}

		$this->removeEncodingDeclaration($document);

		return $result->setData([
			self::DOCUMENT_KEY => $document,
		]);
	}

	private function removeEncodingDeclaration(\DOMDocument $document): void
	{
		foreach ($document->childNodes as $childNode)
		{
			if ($childNode->nodeType === XML_PI_NODE && strtolower($childNode->nodeName) === 'xml')
			{
				$document->removeChild($childNode);

				break;
			}
		}
	}
}
