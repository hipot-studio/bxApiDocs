<?php

namespace Bitrix\Disk\Internal\Service\Access;

use Bitrix\Disk\BaseObject;

interface AccessContactCandidateProviderInterface
{
	/** @return int[] */
	public function getFastCandidateIds(BaseObject $object): array;

	/** @return int[] */
	public function getExtendedCandidateIds(BaseObject $object): array;
}
