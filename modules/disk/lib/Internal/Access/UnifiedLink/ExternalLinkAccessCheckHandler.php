<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Access\UnifiedLink;

use Bitrix\Disk\ExternalLink;
use Bitrix\Disk\File;
use Bitrix\Disk\Internals\ExternalLinkTable;
use Bitrix\Disk\Public\Provider\ExternalLinkProvider;

class ExternalLinkAccessCheckHandler extends ChainableAccessCheckHandler
{
	/**
	 * @param ExternalLinkProvider $externalLinkProvider
	 * @param bool $shouldCheckPassword
	 */
	public function __construct(
		protected readonly ExternalLinkProvider $externalLinkProvider,
		protected readonly bool $shouldCheckPassword,
	)
	{
	}

	protected function doCheck(File $file): UnifiedLinkAccessLevel
	{
		$externalLink = $this->externalLinkProvider->getForUnifiedLinkAccessCheck($file->getId());

		if (
			!$externalLink instanceof ExternalLink
			|| (
				$this->shouldCheckPassword
				&& $externalLink->hasPassword()
				&& !$this->checkPassword($externalLink)
			)
		)
		{
			return UnifiedLinkAccessLevel::Denied;
		}

		return match ($externalLink->getAccessRight()) {
			ExternalLinkTable::ACCESS_RIGHT_VIEW => UnifiedLinkAccessLevel::Read,
			ExternalLinkTable::ACCESS_RIGHT_EDIT => UnifiedLinkAccessLevel::Edit,
			default => UnifiedLinkAccessLevel::Denied,
		};
	}

	/**
	 * @see \CDiskExternalLinkComponent::validatePassword
	 * @param ExternalLink $externalLink
	 * @return bool
	 */
	protected function checkPassword(ExternalLink $externalLink): bool
	{
		$password = $_SESSION['DISK_DATA']['EXT_LINK_PASSWORD'] ?? null;

		if (!$password)
		{
			return false;
		}

		return $externalLink->checkPassword($password);
	}
}
