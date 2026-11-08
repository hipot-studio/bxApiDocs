<?php

declare(strict_types=1);

namespace Bitrix\Calendar\Synchronization\Internal\Service;

use Bitrix\Calendar\Core\Section\Section;
use Bitrix\Calendar\Synchronization\Internal\Entity\SectionConnection;
use Bitrix\Calendar\Synchronization\Internal\Exception\SynchronizerException;
use Bitrix\Main\Repository\Exception\PersistenceException;

interface SectionSynchronizerInterface
{
	/**
	 * @return SectionConnection|null The actual section link with the vendor: created by this call or
	 *                               already existing. Null when there is no link at all (no user
	 *                               connection, no gateway).
	 */
	public function sendSection(Section $section): ?SectionConnection;

	public function deleteSection(string $vendorSectionId, int $userId): void;

	/**
	 * @throws PersistenceException
	 * @throws SynchronizerException
	 */
	public function importSections(int $userId, ?string $token = null): array;
}
