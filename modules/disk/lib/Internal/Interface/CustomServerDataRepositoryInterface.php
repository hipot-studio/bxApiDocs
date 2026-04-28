<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Interface;

use Bitrix\Disk\Internal\Enum\CustomServerTypes;

/**
 * Interface for repository for obtaining dynamic data for the custom server.
 */
interface CustomServerDataRepositoryInterface
{
	/**
	 * @param CustomServerTypes $type
	 * @param array $data
	 * @return array data from storage
	 */
	public function create(CustomServerTypes $type, array $data): array;

	/**
	 * @param mixed $id
	 * @return array|null
	 */
	public function find(mixed $id): ?array;

	/**
	 * @param array<int, CustomServerTypes> $types
	 * @return array {"onlyOffice": [{"param0": "value0", ...}, ...], ...}
	 */
	public function getForTypes(array $types): array;

	/**
	 * @param CustomServerTypes $type
	 * @return array [{"param0": "value0", ...}, ...]
	 */
	public function getForType(CustomServerTypes $type): array;

	/**
	 * @param mixed $id
	 * @param array $data
	 * @return array|null
	 */
	public function update(mixed $id, array $data): ?array;

	/**
	 * @param mixed $id
	 * @return bool
	 */
	public function delete(mixed $id): bool;
}
