<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\ExternalLink;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\Internal\Enum\ExternalLinkScenario;
use Bitrix\Disk\Internals\ObjectOptionsTable;

/**
 * Long term memory of the service nature of a public link (DTO-02).
 *
 * The mark lives on the object, not on the link row, and outlives deletion of the link: switching the
 * public link off from the interface deletes the row, while the mark stays. It grants nothing by
 * itself: {@see \Bitrix\Disk\BaseObject::addExternalLink()} reads it only for a manual link of an
 * object still lying inside the mail attachments folder tree.
 */
final class ExternalLinkScenarioRegistry
{
	public function getFor(BaseObject $object): ?ExternalLinkScenario
	{
		$value = $object->getObjectOptions()[ObjectOptionsTable::NAME_EXTERNAL_LINK_SCENARIO] ?? null;
		if (!is_string($value))
		{
			return null;
		}

		return ExternalLinkScenario::tryFrom($value);
	}

	/**
	 * {@see BaseObject::setObjectOption()} reports nothing about the write, so the answer comes from
	 * reading the mark back.
	 *
	 * @return bool whether the object carries the mark now
	 */
	public function mark(BaseObject $object, ExternalLinkScenario $scenario): bool
	{
		$object->setObjectOption(ObjectOptionsTable::NAME_EXTERNAL_LINK_SCENARIO, $scenario->value);

		return $this->getFor($object) === $scenario;
	}

	/**
	 * An object with no mark is left alone: the cleanup must not plant an empty row.
	 *
	 * @return bool whether the object carries no mark now
	 */
	public function unmark(BaseObject $object): bool
	{
		if ($this->getFor($object) === null)
		{
			return true;
		}

		$object->setObjectOption(ObjectOptionsTable::NAME_EXTERNAL_LINK_SCENARIO, null);

		return $this->getFor($object) === null;
	}
}
