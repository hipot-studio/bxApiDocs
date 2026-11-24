<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Chat\Tree;

/**
 * The columns one level of a query answers a rule of a system folder by: the type pair the section
 * condition lands on, and the parent the nesting frame is compared with
 * ({@see SubtreeUnreadFilter::excludeCovered()}).
 *
 * The three travel together because a section rule needs all of them at once: a level joined by no
 * row of its own carries none, and the rule is then answered by chat id alone.
 */
final readonly class SectionColumns
{
	public function __construct(
		public string $typeField,
		public string $entityTypeField,
		public string $parentField,
	) {}

	public static function forChatAlias(string $alias): self
	{
		return new self("{$alias}.TYPE", "{$alias}.ENTITY_TYPE", "{$alias}.PARENT_ID");
	}
}
