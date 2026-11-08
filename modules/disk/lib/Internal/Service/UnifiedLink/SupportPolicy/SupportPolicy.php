<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\UnifiedLink\SupportPolicy;

use Bitrix\Disk\BaseObject;

interface SupportPolicy
{
	/**
	 * Checks if the given object has unified link support.
	 *
	 * @param BaseObject $baseObject
	 * @return bool
	 */
	public function supports(BaseObject $baseObject): bool;

	/**
	 * The answer of the configuration alone: the unique code of an object that has none is not written and
	 * the stored file is not read. A listing of objects asks this one, because it must stay a pure read;
	 * the missing code is written later, when an item is actually opened.
	 *
	 * @param BaseObject $baseObject
	 * @return bool
	 */
	public function supportsByConfiguration(BaseObject $baseObject): bool;
}
