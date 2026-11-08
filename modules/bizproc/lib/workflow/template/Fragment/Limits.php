<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Workflow\Template\Fragment;

/**
 * The declared limits of the library of the fragments, kept in one place because more than one side reads
 * each of them: the three ceilings of SQL-02 are the ones the codec refuses a write and a read against, and
 * the ceilings of the surface below them are checked by the action and by the service alike, each on the
 * layer it answers for. A number written twice drifts apart at the first change of it, and a ceiling is
 * exactly the kind of number that is changed once and read everywhere.
 *
 * A fragment is written by one user and walked recursively by another, so every ceiling of SQL-02 is a
 * ceiling on untrusted input rather than a capacity of the storage. Which is why each is measured on the
 * work it stands for and not on the shape the row happens to keep: the nodes over the whole tree rather
 * than over the blocks of the top level, and the bytes on the serialized form rather than on the
 * compressed one ({@see FragmentCodec}).
 */
final class Limits
{
	/**
	 * Every node of the fragment, at any depth: a nested level is as untrusted as the root one and costs
	 * the reader the same.
	 */
	public const MAX_NODES = 200;

	public const MAX_CHILDREN_DEPTH = 10;

	/**
	 * Half the capacity of the mediumBlob the envelope is stored in, so it fits with a margin. Measured on
	 * the serialized form, the one the parse walks; compression only makes the stored row smaller than this.
	 */
	public const MAX_ENVELOPE_BYTES = 8 * 1024 * 1024;

	/**
	 * The width of the name column of the schema (SQL-01). The validator of the entity holds it, and so does
	 * the form of the request: a refusal of a validator would reach the author as an unknown error.
	 */
	public const MAX_NAME_LENGTH = 255;

	/** The page of the library one request may ask for (API-01). */
	public const MAX_PAGE_SIZE = 50;

	/**
	 * How deep a page may lie, composed of the ceiling above: as many pages as a page holds items (API-01,
	 * errata of 2026-09-08), and no number of its own - the way the ceiling of the links of a fragment is
	 * composed of the ceiling of its nodes. The list is read by a scan and not by an index - the predicate of
	 * the visibility joins the authors - so the depth of a page is the length of that scan, and a depth a
	 * caller names freely is a scan it names freely.
	 *
	 * Unlike the size of a page, a depth past the ceiling is refused and not brought back to it: a page
	 * trimmed to the ceiling is a smaller answer to the question that was asked, while a page moved to it is
	 * an answer to another question.
	 */
	public const MAX_OFFSET = self::MAX_PAGE_SIZE * self::MAX_PAGE_SIZE;
}
