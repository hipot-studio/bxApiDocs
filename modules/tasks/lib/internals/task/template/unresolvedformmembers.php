<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Internals\Task\Template;

use Bitrix\Main\UserTable;

/**
 * The template form posts its member fields on every save, so the stored composition becomes whatever the
 * form managed to render. Members are rendered from a map of resolved user records, so a member missing
 * from that map is not rendered, and the next save would remove it unless the form posts it back. This
 * collects exactly those ids per member field, and decides whether posting them back is right for a field
 * that came back empty.
 */
final class UnresolvedFormMembers
{
	/**
	 * Same as collect(), narrowed to the members whose user record still exists. A member left over from a
	 * deleted account is not worth posting back: no chip is rendered for it, so nobody could ever remove it
	 * through the form, while a save that drops it clears the dangling reference for good.
	 *
	 * @param array<string, iterable> $memberIdsByField member ids of the template, per form field
	 * @param int[] $resolvedUserIds ids of the user records the form managed to load
	 *
	 * @return array<string, int[]>
	 */
	public static function collectExisting(array $memberIdsByField, array $resolvedUserIds): array
	{
		$unresolved = self::collect($memberIdsByField, $resolvedUserIds);

		$existingUserIds = self::findExistingUserIds(array_merge(...array_values($unresolved)));

		foreach ($unresolved as $field => $memberIds)
		{
			$unresolved[$field] = array_values(array_intersect($memberIds, $existingUserIds));
		}

		return $unresolved;
	}

	/**
	 * @param array<string, iterable> $memberIdsByField member ids of the template, per form field
	 * @param int[] $resolvedUserIds ids of the user records the form managed to load
	 *
	 * @return array<string, int[]>
	 */
	public static function collect(array $memberIdsByField, array $resolvedUserIds): array
	{
		$resolved = self::indexUserIds($resolvedUserIds);

		$unresolved = [];

		foreach ($memberIdsByField as $field => $memberIds)
		{
			$fieldUnresolved = [];

			foreach (is_iterable($memberIds) ? $memberIds : [] as $memberId)
			{
				$memberId = (int)$memberId;

				if (
					$memberId > 0
					&& !isset($resolved[$memberId])
					&& !isset($fieldUnresolved[$memberId])
				)
				{
					$fieldUnresolved[$memberId] = $memberId;
				}
			}

			$unresolved[$field] = array_values($fieldUnresolved);
		}

		return $unresolved;
	}

	/**
	 * Consumes what the form posted about members it could not render: the ids themselves and a flag per
	 * member field telling whether the field had rendered members at all. Both keys are form-local and are
	 * removed here, so the public action the form calls never sees them.
	 *
	 * @param array $formData posted data of the template action
	 * @param string[] $protectedFields member fields the form protects from losing unrendered members
	 *
	 * @return array form data with unrendered members restored where that is right
	 */
	public static function applyToFormData(array $formData, array $protectedFields): array
	{
		if (
			!array_key_exists('UNRESOLVED_MEMBERS', $formData)
			&& !array_key_exists('RENDERED_MEMBERS', $formData)
		)
		{
			return $formData;
		}

		$unresolvedMembers = is_array($formData['UNRESOLVED_MEMBERS'] ?? null) ? $formData['UNRESOLVED_MEMBERS'] : [];
		$renderedMembers = is_array($formData['RENDERED_MEMBERS'] ?? null) ? $formData['RENDERED_MEMBERS'] : [];

		unset($formData['UNRESOLVED_MEMBERS'], $formData['RENDERED_MEMBERS']);

		foreach ($protectedFields as $field)
		{
			if (!is_array($formData[$field] ?? null) || !is_array($unresolvedMembers[$field] ?? null))
			{
				continue;
			}

			$formData[$field] = self::mergeSubmitted(
				$formData[$field],
				$unresolvedMembers[$field],
				($renderedMembers[$field] ?? '') === 'Y'
			);
		}

		return $formData;
	}

	/**
	 * The form posts every member field on each save, so a field that comes back empty means one of two
	 * things: the person cleared the role, or the role only had members the form could not render. Posting
	 * unresolved members back is right in the second case and wrong in the first, where it would restore a
	 * member the person had just removed. The rendered flag tells the cases apart.
	 *
	 * @param array $submittedField posted value of the member field
	 * @param int[] $unresolvedIds ids the form could not render, posted back by the form
	 * @param bool $hadRenderedMembers whether the field had at least one member rendered as a chip
	 *
	 * @return array field value in the posted shape
	 */
	private static function mergeSubmitted(array $submittedField, array $unresolvedIds, bool $hadRenderedMembers): array
	{
		$submittedIds = self::extractSubmittedIds($submittedField);

		if (empty($submittedIds) && $hadRenderedMembers)
		{
			return $submittedField;
		}

		foreach ($unresolvedIds as $memberId)
		{
			$memberId = (int)$memberId;

			if ($memberId > 0 && !isset($submittedIds[$memberId]))
			{
				$submittedField[$memberId] = ['ID' => $memberId];
				$submittedIds[$memberId] = true;
			}
		}

		return $submittedField;
	}

	/**
	 * @param array<string, iterable> $memberIdsByField member ids of the template, per form field
	 * @param int[] $resolvedUserIds ids of the user records the form managed to load
	 *
	 * @return array<string, bool> whether the field has at least one member the form renders as a chip
	 */
	public static function collectRenderedFlags(array $memberIdsByField, array $resolvedUserIds): array
	{
		$resolved = self::indexUserIds($resolvedUserIds);

		$flags = [];

		foreach ($memberIdsByField as $field => $memberIds)
		{
			$flags[$field] = false;

			foreach (is_iterable($memberIds) ? $memberIds : [] as $memberId)
			{
				if (isset($resolved[(int)$memberId]))
				{
					$flags[$field] = true;

					break;
				}
			}
		}

		return $flags;
	}

	/**
	 * @return array<int, bool> posted member ids, as a lookup
	 */
	private static function extractSubmittedIds(array $submittedField): array
	{
		$ids = [];

		foreach ($submittedField as $member)
		{
			$memberId = is_array($member) ? (int)($member['ID'] ?? 0) : 0;

			if ($memberId > 0)
			{
				$ids[$memberId] = true;
			}
		}

		return $ids;
	}

	/**
	 * @param int[] $userIds
	 *
	 * @return array<int, bool>
	 */
	private static function indexUserIds(array $userIds): array
	{
		$indexed = [];

		foreach ($userIds as $userId)
		{
			$indexed[(int)$userId] = true;
		}

		return $indexed;
	}

	/**
	 * @param int[] $userIds
	 *
	 * @return int[]
	 */
	private static function findExistingUserIds(array $userIds): array
	{
		if (empty($userIds))
		{
			return [];
		}

		$rows = UserTable::getList([
			'select' => ['ID'],
			'filter' => ['@ID' => $userIds],
		])->fetchAll();

		return array_map(static fn (array $row): int => (int)$row['ID'], $rows);
	}
}
