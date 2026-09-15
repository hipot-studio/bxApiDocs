<?php

declare(strict_types=1);

namespace Bitrix\Anonymizer\Public\Providers;

/**
 * Provider supplies data for anonymization. Each implementation must be restorable from stored data.
 */
interface ProviderInterface
{
	/**
	 * Create a provider instance from stored data (e.g. from QUEST.PROVIDER_DATA).
	 * Data format is implementation-specific (e.g. PlainText: ['text' => string]).
	 * Returns null if data does not allow creating a valid instance.
	 *
	 * @param array $data Decoded provider data (from getData() when saving)
	 * @return static|null
	 */
	public static function createFromData(array $data): ?static;

	/**
	 * Returns the data to store for later restoration via createFromData().
	 *
	 * @return array|null The runtime data for provider initialization.
	 */
	public function getData(): ?array;
}
