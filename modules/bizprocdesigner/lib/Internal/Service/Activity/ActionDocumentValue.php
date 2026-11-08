<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Service\Activity;

/**
 * Who gets the document field of a node action and what it may hold, for both contours that write one: the
 * settings panel and the REST surface of the external agent. One rule in one place - the two used to
 * disagree, the panel accepting a bare id and the agent refusing it, so the same template was valid in one
 * and invalid in the other.
 *
 * The rule is deliberately loose. The value is dynamic: an expression on a constant, a variable or an
 * output of another node is not computable until the process runs, and a bare id names a document that may
 * not exist yet. Only the obvious rubbish is refused - what could never name a document however the process
 * turns out. Everything else is settled at runtime, where a value that names nothing skips the node with a
 * reason in the journal ({@see \CBPWorkflow::runExecuteActivityOperation()}).
 */
final class ActionDocumentValue
{
	/**
	 * What `(string)` leaves behind when a collection reaches a text field. Never an id anyone meant to
	 * write, and the one spelling the converter can produce by accident.
	 */
	private const ARRAY_CAST_ARTEFACT = 'Array';

	/** Whether the field holds a value at all. An absent one is "not bound", which is a question of its own. */
	public static function isBound(mixed $value): bool
	{
		return $value !== null && $value !== '' && !(is_string($value) && trim($value) === '');
	}

	/** Whether a bound value could name a document at all. */
	public static function isAcceptable(mixed $value): bool
	{
		if (!is_string($value))
		{
			// A document-type triplet or any other array is not a value of this field.
			return false;
		}

		$value = trim($value);

		return $value !== '' && $value !== self::ARRAY_CAST_ARTEFACT;
	}
}
