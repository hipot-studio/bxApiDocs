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
}
