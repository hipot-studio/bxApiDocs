<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\MailTemplate;

use Bitrix\Main\Web\DOM\Document;
use Bitrix\Main\Web\DOM\Element;
use Bitrix\Main\Web\DOM\Node;

/**
 * A template keeps the letter, not the signature of its author. The form that applies a template writes
 * its own signature block and knows it by the identifier of its own instance, so a signature arriving
 * inside the body is a second one: nothing owns it, the layout rule of the form does not reach it, and
 * the picker cannot name it. The blocks are recognised by the marks the forms leave on them
 * (`scheduled-body.ts`, `draft-compose-adapter.ts` and the old form).
 */
final class TemplateBody
{
	private const SIGNATURE_CLASSES = [
		'mail-compose-scheduled-signature',
		'mail-compose-draft-signature',
		'main-mail-form-signature',
	];

	private const SIGNATURE_ID_PATTERN = '/^mail-compose-signature-|_signature_/i';

	public static function stripSignature(string $bodyHtml): string
	{
		if (trim($bodyHtml) === '')
		{
			return $bodyHtml;
		}

		// A fragment loads without a wrapper: its nodes are children of the document itself.
		$document = new Document();
		$document->loadHTML($bodyHtml);

		$signatures = [];
		self::collectSignatures($document, $signatures);
		if ($signatures === [])
		{
			return $bodyHtml;
		}

		foreach ($signatures as $signature)
		{
			$signature->getParentNode()?->removeChild($signature);
		}

		return $document->saveHTML();
	}

	/** @param list<Node> $found */
	private static function collectSignatures(Node $node, array &$found): void
	{
		foreach ($node->getChildNodesArray() as $child)
		{
			if ($child instanceof Element && self::isSignature($child))
			{
				$found[] = $child;

				continue;
			}

			self::collectSignatures($child, $found);
		}
	}

	private static function isSignature(Element $element): bool
	{
		$classes = preg_split('/\s+/', (string)$element->getAttribute('class'), -1, PREG_SPLIT_NO_EMPTY) ?: [];
		foreach (self::SIGNATURE_CLASSES as $marker)
		{
			if (in_array($marker, $classes, true))
			{
				return true;
			}
		}

		$id = (string)$element->getAttribute('id');

		return $id !== '' && preg_match(self::SIGNATURE_ID_PATTERN, $id) === 1;
	}
}
