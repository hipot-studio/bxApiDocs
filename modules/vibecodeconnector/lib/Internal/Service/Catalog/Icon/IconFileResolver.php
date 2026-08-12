<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Catalog\Icon;

use Bitrix\Main\ArgumentException;
use Bitrix\Vibecodeconnector\Internal\Integration\Main\IconStorageService;

/**
 * Single source of truth for turning a raw icon URL into a stored file id:
 * normalizes the value, enforces the origin policy and downloads the icon.
 */
final class IconFileResolver
{
	public function __construct(
		private readonly IconOriginPolicy $iconOriginPolicy = new IconOriginPolicy(),
		private readonly IconStorageService $iconStorage = new IconStorageService(),
	)
	{
	}

	public function resolve(?string $iconUrl, ?string $pairingIss): ?int
	{
		$iconUrl = $this->normalize($iconUrl);
		if ($iconUrl === null)
		{
			return null;
		}

		$this->iconOriginPolicy->assertAllowed($iconUrl, $pairingIss);

		$iconFileId = $this->iconStorage->downloadAndSave($iconUrl);
		if ($iconFileId === null)
		{
			throw new ArgumentException('Failed to download icon', 'iconUrl');
		}

		return $iconFileId;
	}

	private function normalize(?string $value): ?string
	{
		if ($value === null)
		{
			return null;
		}

		$value = trim($value);

		return $value === '' ? null : $value;
	}
}
