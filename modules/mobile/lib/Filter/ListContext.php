<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Filter;

use Bitrix\Main\UI\Filter\Options;

/**
 * One list as the unified filter sees it, whatever module owns that list.
 *
 * The filter itself is module-agnostic: it needs the filter storage shared with the web, the raw
 * web descriptors of the fields and the permission to read the list at all. How a list is
 * identified - a list type and a project, an entity type and a funnel - stays with the module
 * that owns it.
 */
interface ListContext
{
	/**
	 * Field descriptors carry the titles of the stages, epics and list items of the list, so a
	 * context the user may not read must answer false rather than build them.
	 */
	public function canRead(): bool;

	/**
	 * Options of the list, always built with the presets the list ships with: a bare
	 * new Options() has no default presets, so reads lose the module presets and writes
	 * store them back empty.
	 *
	 * The address of the storage comes from here as well (Options::getId()): a list that answers
	 * its id separately would have to keep two derivations in step, and a divergence writes the
	 * filter into another list's storage.
	 */
	public function getOptions(): Options;

	/**
	 * Raw web descriptors of the fields, in the order the web filter shows them.
	 *
	 * @return array<int, array>
	 */
	public function getFieldArrays(): array;

	/**
	 * Groups the fields of the list fall into, in the order the web filter shows them. A field
	 * names its group by the sectionId the web provider already puts on it, so the list here only
	 * has to title the groups and order them.
	 *
	 * An empty array is the answer of a list the web does not group - the form then stays the flat
	 * list of fields it was.
	 *
	 * @return array<int, array{id: string, name: string}>
	 */
	public function getSections(): array;
}
