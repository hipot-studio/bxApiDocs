<?php

namespace Bitrix\Calendar\Core\Event;

interface LegacyLocationAvailabilityCheckerInterface
{
	public function isAvailable(string $location, array $fields): bool;
}
