<?php

namespace Bitrix\BIConnector\Access\Component\PermissionSection;

use Bitrix\Main\Result;

/**
 * A set of sections of the rights screen.
 *
 * The screen works with two of them: the sections of the portal, which are all drawn, and the sections
 * the person may set up, which are the only ones saved and the ones that let him in. A shown section
 * outside the second set is drawn read only.
 */
final class SectionSet
{
	/**
	 * @param PermissionSection[] $sections
	 */
	private function __construct(private readonly array $sections)
	{
	}

	/**
	 * Sections the current user may set up: what the screen saves and what opens it.
	 */
	public static function forCurrentUser(): self
	{
		return new self(SectionFactory::getAvailable());
	}

	/**
	 * Sections the portal has, whoever is asking: what the screen draws.
	 */
	public static function forPortal(): self
	{
		return new self(SectionFactory::getSupported());
	}

	/**
	 * @param PermissionSection[] $sections
	 */
	public static function of(array $sections): self
	{
		return new self(array_values($sections));
	}

	/**
	 * @return PermissionSection[] in the order of the screen
	 */
	public function getSections(): array
	{
		return $this->sections;
	}

	public function isEmpty(): bool
	{
		return $this->sections === [];
	}

	public function hasSection(string $code): bool
	{
		foreach ($this->sections as $section)
		{
			if ($section->getCode() === $code)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the permission id belongs to a section of the set.
	 *
	 * An id of a section outside the set is an id of rows drawn to the person read only, so the save
	 * of it is a save he may not make.
	 */
	public function ownsPermissionId(int|string $permissionId): bool
	{
		foreach ($this->sections as $section)
		{
			if ($section->ownsPermissionId($permissionId))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Checks of every section of the set, stopped at the first refusal.
	 *
	 * @param array $userGroups roles of the payload
	 */
	public function preSaveChecks(array $userGroups): Result
	{
		foreach ($this->sections as $section)
		{
			$result = $section->preSaveChecks($userGroups);
			if (!$result->isSuccess())
			{
				return $result;
			}
		}

		return new Result();
	}
}
