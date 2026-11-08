<?php

declare(strict_types=1);

namespace Bitrix\Calendar\Internal\Service\PublicBooking;

/**
 * Narrows the domain form data down to what the anonymous booking field renders.
 *
 * The projection only filters: a key absent from the input never appears in the output. The
 * whitelists below mirror what the two field runtimes actually read
 * (install/js/calendar/resourcebooking/src/livefieldcontroller.js and legacy
 * install/js/calendar/userfield/resourcebooking-webform-live.js), so a key added to the domain has to
 * be checked against them before it is whitelisted here.
 */
final class ResponseProjection
{
	private const TOP_LEVEL_FIELDS = [
		'timezoneOffset',
		'timezoneOffsetLabel',
		'fieldSettings',
		'usersAccessibility',
		'resourcesAccessibility',
		'userIndex',
		'workTimeStart',
		'workTimeEnd',
	];

	private const FIELD_SETTINGS_FIELDS = [
		'USE_USERS',
		'USE_RESOURCES',
		'FULL_DAY',
		'ALLOW_OVERBOOKING',
		'USE_SERVICES',
		'TIMEZONE',
		'USE_USER_TIMEZONE',
		'SERVICE_LIST',
		'SELECTED_RESOURCES',
	];

	private const ACCESSIBILITY_MAP_FIELDS = [
		'usersAccessibility',
		'resourcesAccessibility',
	];

	private const ENTRY_FIELDS = [
		'dateFrom',
		'dateTo',
		'fullDay',
	];

	private const USER_FIELDS = [
		'id',
		'displayName',
	];

	private const RESOURCE_FIELDS = [
		'id',
		'title',
	];

	private const SERVICE_FIELDS = [
		'name',
		'duration',
	];

	public function project(array $formData, array $allowedResourceIds): array
	{
		$result = $this->filterEntry($formData, self::TOP_LEVEL_FIELDS);

		if (array_key_exists('fieldSettings', $result))
		{
			$fieldSettings = $this->projectFieldSettings($result['fieldSettings'], $allowedResourceIds);
			if ($fieldSettings === [])
			{
				unset($result['fieldSettings']);
			}
			else
			{
				$result['fieldSettings'] = $fieldSettings;
			}
		}

		foreach (self::ACCESSIBILITY_MAP_FIELDS as $field)
		{
			if (array_key_exists($field, $result))
			{
				$result[$field] = $this->projectAccessibility($result[$field]);
			}
		}

		if (array_key_exists('userIndex', $result))
		{
			$result['userIndex'] = $this->projectUserIndex($result['userIndex']);
		}

		return $result;
	}

	private function projectFieldSettings(mixed $fieldSettings, array $allowedResourceIds): array
	{
		$result = $this->filterEntry($fieldSettings, self::FIELD_SETTINGS_FIELDS);

		if (array_key_exists('SERVICE_LIST', $result))
		{
			$result['SERVICE_LIST'] = $this->projectList($result['SERVICE_LIST'], self::SERVICE_FIELDS);
		}

		if (array_key_exists('SELECTED_RESOURCES', $result))
		{
			// A non-list value stays falsy for the client: it shows the resource selector by truthiness.
			if (is_array($result['SELECTED_RESOURCES']))
			{
				$result['SELECTED_RESOURCES'] = $this->projectSelectedResources(
					$result['SELECTED_RESOURCES'],
					$allowedResourceIds,
				);
			}
			else
			{
				unset($result['SELECTED_RESOURCES']);
			}
		}

		return $result;
	}

	private function projectSelectedResources(array $resources, array $allowedResourceIds): array
	{
		$allowedIds = $this->makeAllowedResourceIdMap($allowedResourceIds);

		$result = [];
		foreach ($resources as $resource)
		{
			if (!is_array($resource) || !$this->isAllowedResource($resource['id'] ?? null, $allowedIds))
			{
				continue;
			}

			$result[] = $this->filterEntry($resource, self::RESOURCE_FIELDS);
		}

		return $result;
	}

	private function projectAccessibility(mixed $accessibility): array
	{
		if (!is_array($accessibility))
		{
			return [];
		}

		$result = [];
		foreach ($accessibility as $entityId => $entries)
		{
			$result[$entityId] = $this->projectAccessibilityEntries($entries);
		}

		return $result;
	}

	/**
	 * Unlike the other narrowed lists, an entry left without whitelisted fields describes no busy
	 * interval at all, so it is dropped instead of being emitted as an empty record.
	 */
	private function projectAccessibilityEntries(mixed $entries): array
	{
		if (!is_array($entries))
		{
			return [];
		}

		$result = [];
		foreach ($entries as $entry)
		{
			$projectedEntry = $this->filterEntry($entry, self::ENTRY_FIELDS);
			if ($projectedEntry !== [])
			{
				$result[] = $projectedEntry;
			}
		}

		return $result;
	}

	private function projectUserIndex(mixed $userIndex): array
	{
		if (!is_array($userIndex))
		{
			return [];
		}

		$result = [];
		foreach ($userIndex as $userId => $user)
		{
			$result[$userId] = $this->filterEntry($user, self::USER_FIELDS);
		}

		return $result;
	}

	private function projectList(mixed $items, array $fields): array
	{
		if (!is_array($items))
		{
			return [];
		}

		$result = [];
		foreach ($items as $item)
		{
			if (is_array($item))
			{
				$result[] = $this->filterEntry($item, $fields);
			}
		}

		return $result;
	}

	private function filterEntry(mixed $entry, array $fields): array
	{
		if (!is_array($entry))
		{
			return [];
		}

		$result = [];
		foreach ($fields as $field)
		{
			if (array_key_exists($field, $entry))
			{
				$result[$field] = $entry[$field];
			}
		}

		return $result;
	}

	private function makeAllowedResourceIdMap(array $allowedResourceIds): array
	{
		$result = [];
		foreach ($allowedResourceIds as $allowedResourceId)
		{
			$resourceId = $this->normalizeResourceId($allowedResourceId);
			if ($resourceId > 0)
			{
				$result[$resourceId] = true;
			}
		}

		return $result;
	}

	private function isAllowedResource(mixed $resourceId, array $allowedIds): bool
	{
		$normalizedId = $this->normalizeResourceId($resourceId);

		return $normalizedId > 0 && isset($allowedIds[$normalizedId]);
	}

	/**
	 * Same (int) rule as the trusted list in CrmWebFormBookingSource::normalizeIdList(), so both sides
	 * of the comparison read a non-canonical id alike. Non-scalars are rejected instead of being cast:
	 * (int) turns any array into 1 and would match the resource with that id.
	 */
	private function normalizeResourceId(mixed $id): int
	{
		return is_scalar($id) ? (int)$id : 0;
	}
}
