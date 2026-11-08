<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Repository\Interface;

use Bitrix\Disk\ExternalLink;
use Bitrix\Main\Repository\RepositoryInterface;

interface ExternalLinkRepositoryInterface extends RepositoryInterface
{
	/**
	 * @param mixed $objectId
	 * @return ExternalLink|null
	 */
	public function getForUnifiedLinkAccessCheck(mixed $objectId): ?ExternalLink;

	/**
	 * @param array<int, int> $objectIds
	 * @return array<int, ExternalLink>
	 */
	public function getForUnifiedLinkAccessCheckCollection(array $objectIds): array;

	/**
	 * @param mixed $objectId
	 * @return ExternalLink|null
	 */
	public function getForComponent(mixed $objectId): ?ExternalLink;

	/**
	 * @param string $hash
	 * @return ExternalLink|null
	 */
	public function getForComponentByHash(string $hash): ?ExternalLink;

	/**
	 * @param mixed $objectId
	 * @return ExternalLink|null
	 */
	public function getForUse(mixed $objectId): ?ExternalLink;

	/**
	 * The schema holds no unique key over the working manual link of an object, so a caller which
	 * has to see every row asks for the whole list, newest first.
	 *
	 * @param mixed $objectId
	 * @return ExternalLink[]
	 */
	public function getAllForUse(mixed $objectId): array;
}
