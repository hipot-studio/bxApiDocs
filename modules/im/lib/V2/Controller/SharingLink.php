<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Controller;

class SharingLink extends BaseController
{
	/**
	 * @restMethod im.v2.SharingLink.get
	 */
	public function getAction(\Bitrix\Im\V2\SharingLink\SharingLink $sharingLink): ?array
	{
		return $this->toRestFormat($sharingLink);
	}
}
