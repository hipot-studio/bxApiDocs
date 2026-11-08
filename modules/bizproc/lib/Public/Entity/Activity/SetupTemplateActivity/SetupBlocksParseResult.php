<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Entity\Activity\SetupTemplateActivity;

/**
 * Result of parsing a setup wizard form, as seen from outside the bizproc module.
 *
 * Immutable snapshot: on success it carries the declarations the form produces and how many form
 * items of every type it holds (delimiters, titles and descriptions are counted but not described,
 * as no rule judges their content); on failure it carries the structural errors instead. It never
 * carries localized texts or the internal form entities.
 */
final readonly class SetupBlocksParseResult
{
	/**
	 * @param list<SetupConstantDeclaration> $declarations
	 * @param int $itemCount Form items of every type, not only the constants.
	 * @param list<SetupParseError> $errors
	 */
	public function __construct(
		public array $declarations = [],
		public int $itemCount = 0,
		public array $errors = [],
	) {}

	/**
	 * A form of headers and descriptions alone parses fine and declares nothing, so an empty
	 * declaration list is not a failure - only the error list decides.
	 */
	public function isSuccess(): bool
	{
		return $this->errors === [];
	}
}
