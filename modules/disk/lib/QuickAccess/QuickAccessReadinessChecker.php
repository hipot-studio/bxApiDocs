<?php
declare(strict_types=1);

namespace Bitrix\Disk\QuickAccess;

use Bitrix\Main\Config\Option;

class QuickAccessReadinessChecker
{
	private bool $fastDownload;

	public function __construct(
		private readonly ?string $signerKey,
	)
	{
		$this->fastDownload = $this->isFastDownloadEnabled();
	}

	/**
	 * Check if the system is ready to provide token access
	 *
	 * @return bool True if the system is ready, false otherwise
	 */
	public function isReady(): bool
	{
		if (!$this->fastDownload)
		{
			return false;
		}
		if (empty($this->signerKey))
		{
			return false;
		}

		return true;
	}

	/**
	 * Check if Fast Download option is enabled
	 *
	 * @return bool True if fast download is enabled
	 */
	private function isFastDownloadEnabled(): bool
	{
		return Option::get('main', 'bx_fast_download', 'N') === 'Y';
	}
}
