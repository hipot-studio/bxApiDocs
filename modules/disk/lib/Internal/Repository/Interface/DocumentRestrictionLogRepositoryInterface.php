<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Repository\Interface;

use Bitrix\Disk\Internal\Entity\DocumentRestrictionLog;
use Bitrix\Main\Repository\RepositoryInterface;

interface DocumentRestrictionLogRepositoryInterface extends RepositoryInterface
{
	/**
	 * @param string $hash
	 * @return DocumentRestrictionLog|null
	 */
	public function getByHash(string $hash): ?DocumentRestrictionLog;
}