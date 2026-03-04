<?php

declare(strict_types=1);

namespace Bitrix\Main\Validation\Rule;

/**
 * Interface for validation attributes that explicitly declare validation groups.
 *
 * Implement this interface on attribute classes to make them group-aware in the
 * validation service. Returning an empty array means the attribute applies to all groups.
 */
interface ValidateByGroupInterface
{
	/**
	 * Return the list of groups this attribute belongs to.
	 *
	 * @return string[] list of group names
	 */
	public function getGroups(): array;
}
