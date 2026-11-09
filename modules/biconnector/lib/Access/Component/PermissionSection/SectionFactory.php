<?php

namespace Bitrix\BIConnector\Access\Component\PermissionSection;

use Bitrix\BIConnector\Configuration\Feature;

/**
 * Sections of the rights screen.
 *
 * The list of sections is not fixed twice over: a section belonging to a feature that is not
 * applied on the portal is no section of this screen at all, and a section the person holds no
 * right of is no section of his screen - it is not drawn and nothing of it is saved.
 */
final class SectionFactory
{
	/**
	 * Sections the current user may edit, in the order of the left column.
	 *
	 * @return PermissionSection[]
	 */
	public static function getAvailable(): array
	{
		return array_values(
			array_filter(
				self::getSupported(),
				static fn(PermissionSection $section): bool => $section->canEdit(),
			)
		);
	}

	/**
	 * Whether the rights screen has anything to show the current user.
	 *
	 * The screen is opened by the right of any of its sections, so the menu item leading to it asks
	 * the same question the screen itself answers - and asks it here, so that a section added or
	 * closed by a rollout switch never leaves a menu item pointing at a screen that refuses entry.
	 */
	public static function hasAvailableSection(): bool
	{
		return self::getAvailable() !== [];
	}

	/**
	 * Sections this portal has, whoever is asking.
	 *
	 * @return PermissionSection[] in the fixed order of the screen
	 */
	public static function getSupported(): array
	{
		$sections = [
			new MainRightsSection(),
			new DashboardGroupSection(),
		];

		// The section of the table axis exists on the screen only where the axis itself is applied,
		// and this single place decides it for the whole screen: the portal that has not got the
		// axis keeps the screen it had before it.
		//
		// The predicate is the conjunction and not the rollout half alone: the rollout half answers
		// "may the tariff move the axis at all" and reads true on a portal whose tariff carries no
		// rights screen of this kind, which would put the section in front of people the axis will
		// never restrict.
		if (Feature::isTablePermissionsEnabled())
		{
			$sections[] = new TableGroupSection();
		}

		return $sections;
	}
}
