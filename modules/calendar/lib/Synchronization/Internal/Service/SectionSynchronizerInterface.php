<?php

declare(strict_types=1);

namespace Bitrix\Calendar\Synchronization\Internal\Service;

use Bitrix\Calendar\Core\Section\Section;
use Bitrix\Calendar\Synchronization\Internal\Exception\SynchronizerException;
use Bitrix\Main\Repository\Exception\PersistenceException;

interface SectionSynchronizerInterface
{
	public function sendSection(Section $section): void;

	public function deleteSection(string $vendorSectionId, int $userId): void;

	/**
	 * @throws PersistenceException
	 * @throws SynchronizerException
	 */
	public function importSections(int $userId, ?string $token = null): array;
}
