<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\UnifiedLink\SupportPolicy;

use Bitrix\Disk\File;
use Bitrix\Disk\TypeFile;
use Closure;

/**
 * UnifiedLink support policy factory with caching by file types.
 * By default, returns BaseSupportPolicy for unknown types.
 */
final class SupportPolicyFactory
{
	/** @var array<int, Closure():SupportPolicy> */
	private readonly array $registry;

	/** @var array<int, SupportPolicy> */
	private array $cache = [];

	public function __construct()
	{
		$documentSupportPolicy = new DocumentSupportPolicy();
		$this->registry = [
			TypeFile::DOCUMENT => static fn() => $documentSupportPolicy,
			TypeFile::PDF => static fn() => $documentSupportPolicy,
		];
	}

	/**
	 * Returns (or caches) the policy for the given file.
	 * Unknown types receive BaseSupportPolicy.
	 * @param File $file
	 * @return SupportPolicy
	 */
	public function create(File $file): SupportPolicy
	{
		$fileType = (int)$file->getTypeFile();

		return $this->cache[$fileType] ??=
			($this->registry[$fileType] ?? static fn() => new BaseSupportPolicy())();
	}

}
