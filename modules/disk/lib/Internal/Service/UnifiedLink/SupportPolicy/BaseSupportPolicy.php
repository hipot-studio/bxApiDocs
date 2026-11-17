<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\UnifiedLink\SupportPolicy;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\File;
use Bitrix\Disk\Folder;
use Bitrix\Disk\Internal\Service\UnifiedLink\Configuration;
use Bitrix\Disk\Internal\Service\UnifiedLink\UniqueCodeBackfiller;

class BaseSupportPolicy implements SupportPolicy
{
	/**
	 * @param UniqueCodeBackfiller $uniqueCodeBackfiller
	 */
	public function __construct(
		protected readonly UniqueCodeBackfiller $uniqueCodeBackfiller,
	)
	{
	}

	/**
	 * {@inheritDoc}
	 */
	public function supports(BaseObject $baseObject): bool
	{
		return $this->supportsByConfiguration($baseObject) && $this->hasUniqueCode($baseObject);
	}

	/**
	 * {@inheritDoc}
	 */
	public function supportsByConfiguration(BaseObject $baseObject): bool
	{
		return Configuration::isEnabled()
			&& ($baseObject instanceof File || $baseObject instanceof Folder)
		;
	}

	private function hasUniqueCode(BaseObject $baseObject): bool
	{
		if ((string)$baseObject->getUniqueCode() !== '')
		{
			return true;
		}

		// Read-only: an object without a code does not support the unified link until a stepper assigns it one.
		return (string)$this->uniqueCodeBackfiller->getBackfilledUniqueCode((int)$baseObject->getId()) !== '';
	}
}
