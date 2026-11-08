<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\UnifiedLink\SupportPolicy;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\File;
use Bitrix\Disk\Internal\Service\UnifiedLink\Configuration;

class FileSupportPolicy extends BaseSupportPolicy
{
	/** @var array<int, bool> Cache of supported file types. */
	private array $supportedFileTypes = [];

	/**
	 * {@inheritDoc}
	 * @param File $baseObject
	 */
	final public function supports(BaseObject $baseObject): bool
	{
		return parent::supports($baseObject) && $this->passesSpecializedChecks($baseObject);
	}

	/**
	 * {@inheritDoc}
	 * The specialized checks stay out: they are judged by the stored file, which a listing must not read.
	 * @param File $baseObject
	 */
	final public function supportsByConfiguration(BaseObject $baseObject): bool
	{
		return $this->passesConfigurationChecks($baseObject) && parent::supportsByConfiguration($baseObject);
	}

	/**
	 * Checks against configuration settings.
	 * @param File $file
	 * @return bool
	 */
	final protected function passesConfigurationChecks(File $file): bool
	{
		$fileType = (int)$file->getTypeFile();
		$this->supportedFileTypes[$fileType] ??= Configuration::isFileTypeAllowed($fileType);

		return $this->supportedFileTypes[$fileType];
	}

	/**
	 * Additional (specialized) checks.
	 * Can be extended by descendants if necessary.
	 * @param File $file
	 * @return bool
	 */
	protected function passesSpecializedChecks(File $file): bool
	{
		return true;
	}
}
