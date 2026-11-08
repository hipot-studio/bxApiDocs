<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\ExternalLink;

use Bitrix\Disk\Configuration;
use Bitrix\Disk\Document\Flipchart\Configuration as FlipchartConfiguration;
use Bitrix\Disk\ExternalLink;
use Bitrix\Disk\TypeFile;

/**
 * The single decision point of the portal policy for opening an external link (ALG-02).
 *
 * It answers only whether the portal policy allows opening the link. Activity, lifetime, revocation,
 * existence of the object, password and the one-time download token stay in the opening points.
 */
final class ExternalLinkOpenPolicy
{
	public function __construct(
		private readonly bool $manualLinksEnabled,
		private readonly bool $autoLinksEnabled,
		private readonly bool $boardLinksEnabled,
		private readonly bool $boardsEnabled,
	)
	{
	}

	/**
	 * The answers of the portal for the whole hit; the portal builds the decision this way
	 * (`disk/.settings.php`). {@see Configuration} keeps its answers in static variables and the flag of
	 * boards is an option of the already loaded module set, so reading all four here costs no query.
	 */
	public static function fromPortalConfiguration(): self
	{
		return new self(
			manualLinksEnabled: (bool)Configuration::isEnabledManualExternalLink(),
			autoLinksEnabled: (bool)Configuration::isEnabledAutoExternalLink(),
			boardLinksEnabled: Configuration::isEnabledBoardExternalLink(),
			boardsEnabled: FlipchartConfiguration::isBoardsEnabled(),
		);
	}

	/**
	 * The order of the branches is normative: the scenario goes first, otherwise a service link to a
	 * board would fall into the tariff branch of boards. The board branch belongs to the board handler,
	 * registered only while the portal keeps boards on, so with boards off a board file is decided by
	 * the automatic or the manual branch, as before.
	 *
	 * @see \Bitrix\Disk\Document\DocumentHandlersManager::buildDocumentHandlerList()
	 */
	public function canOpenByPortalPolicy(ExternalLink $link): bool
	{
		if ($link->isMailAttachmentLink())
		{
			return true;
		}

		if ($this->isBoard($link) && $this->boardsEnabled)
		{
			return $this->boardLinksEnabled;
		}

		if ($link->isAutomatic())
		{
			return $this->autoLinksEnabled;
		}

		return $this->manualLinksEnabled;
	}

	private function isBoard(ExternalLink $link): bool
	{
		$file = $link->getFile();

		return $file !== null && (int)$file->getTypeFile() === TypeFile::BOARD;
	}
}
