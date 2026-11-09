<?php

namespace Bitrix\Landing\Markup\Repair;

/**
 * Classification of the intake error codes: semantic (repairable) codes are the only
 * ones the repair cycle handles; hard codes (limits, forbidden types, broken structure)
 * are not repaired - the package must be rebuilt by its source.
 *
 * The registry is the single place both the repair step and the REST/tool responses
 * (the `repairable` flag of an intake error) rely on. The semantic codes are emitted
 * by the detectors of the package validation, which run only after every hard check
 * of the package has passed.
 */
class RepairableCodes
{
	/** An orphan file: its path is not found by the reference matcher in any recognizable form. */
	public const UNREFERENCED_FILE = 'MARKUP_UNREFERENCED_FILE';

	/** An image token used by the markup and missing from the image declaration. */
	public const UNDECLARED_TOKEN = 'MARKUP_UNDECLARED_TOKEN';

	/** Code of the repair outcome class: it breaks the package itself, not the portal. */
	public const REPAIRABLE_CODE = 'MARKUP_REPAIRABLE_CODE';

	private const CODES = [
		self::UNREFERENCED_FILE,
		self::UNDECLARED_TOKEN,
		self::REPAIRABLE_CODE,
	];

	public static function isRepairable(string $code): bool
	{
		return in_array($code, self::CODES, true);
	}
}
