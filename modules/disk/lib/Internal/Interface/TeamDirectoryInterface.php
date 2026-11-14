<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Interface;

/**
 * Answers about teams of the default company structure with scalars.
 */
interface TeamDirectoryInterface
{
	public function isModuleAvailable(): bool;

	public function hasTeams(): bool;

	public function isTeamOfDefaultStructure(int $nodeId): bool;

	/**
	 * @param int[] $ids
	 * @return array<int, string>
	 */
	public function findTeamNames(array $ids): array;

	/**
	 * Reads the id of a deleted team from the "node" parameter of the event about a deleted node.
	 */
	public function readDeletedTeamId(mixed $node): ?int;
}
