<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\UnifiedLink\SupportPolicy;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\File;
use Bitrix\Disk\TypeFile;

/**
 * UnifiedLink support policy factory by file types.
 * By default, returns BaseSupportPolicy for unknown types.
 */
final readonly class SupportPolicyFactory
{
	/** @var array<int, SupportPolicy> */
	private array $registry;

	/**
	 * @param BaseSupportPolicy $baseSupportPolicy
	 * @param FileSupportPolicy $fileSupportPolicy
	 * @param DocumentSupportPolicy $documentSupportPolicy
	 */
	public function __construct(
		private BaseSupportPolicy $baseSupportPolicy,
		private FileSupportPolicy $fileSupportPolicy,
		private DocumentSupportPolicy $documentSupportPolicy,
	)
	{
		$this->registry = [
			TypeFile::DOCUMENT => $this->documentSupportPolicy,
			TypeFile::PDF => $this->documentSupportPolicy,
		];
	}

	/**
	 * Returns the policy for the given object.
	 * Unknown types receive BaseSupportPolicy.
	 *
	 * @param BaseObject $baseObject
	 * @return SupportPolicy
	 */
	public function create(BaseObject $baseObject): SupportPolicy
	{
		if ($baseObject instanceof File)
		{
			$fileType = (int)$baseObject->getTypeFile();

			return $this->registry[$fileType] ?? $this->fileSupportPolicy;
		}

		return $this->baseSupportPolicy;
	}
}
