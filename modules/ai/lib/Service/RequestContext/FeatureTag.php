<?php

declare(strict_types=1);

namespace Bitrix\AI\Service\RequestContext;

use Bitrix\AI\Context;

/**
 * Feature label of an outbound AI request: the owning module together with the scenario
 * behind the call, as `ai.{moduleId}.{contextId}`.
 *
 * The single place where the shape of the label is born. A send point that assembled the
 * value itself would also decide which characters are allowed and where the value is cut;
 * the check in front of the header drops a value that disagrees, so the scenario would
 * vanish from the label leaving nothing but a log line behind.
 *
 * Nothing beyond the given context is read: no option, no database, no current user. That is
 * what lets a send point build the label before anything that can fail has run.
 */
final class FeatureTag
{
	private const PREFIX = 'ai';
	private const UNKNOWN = 'unknown';

	/** The two dots between the three parts: they are spent from the same cap as the parts. */
	private const SEPARATORS = 2;

	private function __construct(
		public readonly string $value,
	)
	{
	}

	/**
	 * The module part is normalized first and keeps a budget of its own; the scenario part is
	 * given everything the module part left of the cap, because a module id is a short word from
	 * a known set while a context id carries the scenario together with the ids inside it.
	 *
	 * Splitting the cap in equal halves instead would cut a scenario the whole label still has
	 * room for, and two scenarios that differ only past that cut would reach the receiver as one.
	 * The remainder is never smaller than the module budget, so the substitution for an empty
	 * part fits under any module id.
	 */
	public static function fromContext(Context $context): self
	{
		$module = self::normalizePart($context->getModuleId(), self::modulePartMax());
		$contextMax = ContextHeaders::FEATURE_MAX_LENGTH - strlen(self::PREFIX) - self::SEPARATORS - strlen($module);

		return new self(
			self::PREFIX
			. '.' . $module
			. '.' . self::normalizePart($context->getContextId(), $contextMax)
		);
	}

	/**
	 * Bytewise on purpose: the cap is counted in bytes and so is the check in front of the
	 * header, so a part measured in characters would pass here and be dropped there.
	 *
	 * That is also why the cut comes before the replacement: one byte outside the alphabet
	 * becomes exactly one underscore, so the value is the same either way and the work no
	 * longer follows the length of a context id that arrives unbounded.
	 */
	private static function normalizePart(string $raw, int $max): string
	{
		$clean = (string)preg_replace('/[^A-Za-z0-9_-]/', '_', substr($raw, 0, $max));
		if ($clean === '')
		{
			$clean = self::UNKNOWN;
		}

		return $clean;
	}

	/**
	 * Derived rather than assigned, so the module part cannot outgrow the cap the header check
	 * applies even when it is the only part that has to be cut.
	 */
	private static function modulePartMax(): int
	{
		return intdiv(ContextHeaders::FEATURE_MAX_LENGTH - strlen(self::PREFIX) - self::SEPARATORS, 2);
	}
}
