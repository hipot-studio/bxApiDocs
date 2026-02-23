<?php
declare(strict_types=1);

namespace Bitrix\Disk\Analytics\Enum;

use Bitrix\Disk\Document\Models\DocumentSession;

enum OpenTypeEnum: string
{
	case View = 'view';
	case Edit = 'edit';

	public static function getByDocumentSessionType(int $documentSessionType): ?OpenTypeEnum
	{
		return match ($documentSessionType)
		{
			DocumentSession::TYPE_VIEW => OpenTypeEnum::View,
			DocumentSession::TYPE_EDIT => OpenTypeEnum::Edit,
			default => null,
		};
	}
}
