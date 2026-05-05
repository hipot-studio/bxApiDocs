<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\OnlyOffice\Promo;

use Bitrix\Disk\Document\DocumentEditorUser;

class PromoBlocker
{
	/**
	 * @return bool
	 */
	public static function shouldBlockPromoForUser(): bool
	{
		global $USER;

		return !$USER->isAuthorized() || DocumentEditorUser::isCurrentUserDocumentEditor();
	}
}
