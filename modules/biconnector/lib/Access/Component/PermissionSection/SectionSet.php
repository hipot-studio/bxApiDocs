<?php

namespace Bitrix\BIConnector\Access\Component\PermissionSection;

use Bitrix\Main\Result;

/**
 * The sections one person works with: the screen draws these and saves these, and no others.
 *
 * The screen is one and the same for everybody, and what differs is its content: a person holding
 * the right of a single section sees that section alone, so the set answers both questions at once -
 * what to draw and what of an incoming payload may be written.
 */
final class SectionSet
{
	/**
	 * @param PermissionSection[] $sections
	 */
	private function __construct(private readonly array $sections)
	{
	}

	public static function forCurrentUser(): self
	{
		return new self(SectionFactory::getAvailable());
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
	 * An id of a section outside the set is an id of rows the person was never shown, so the save
	 * of it is a save nobody asked for.
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
