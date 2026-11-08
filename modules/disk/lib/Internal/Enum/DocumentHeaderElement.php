<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Enum;

/**
 * Elements the document/board header may consist of.
 *
 * The declaration order is the left-to-right render order: the composition service keeps it
 * when it builds the `elements` list. The enum itself carries no display conditions - they
 * live in {@see \Bitrix\Disk\Internal\Service\DocumentHeader\HeaderCompositionService}.
 */
enum DocumentHeaderElement: string
{
	case Logo = 'logo';
	case Name = 'name';
	case ContentWarning = 'contentWarning';
	case Rename = 'rename';
	case SaveStatus = 'saveStatus';
	case Boost = 'boost';
	case Edit = 'edit';
	case Presence = 'presence';
	case Access = 'access';
	case CopyLink = 'copyLink';
	case Download = 'download';
	case Presentation = 'presentation';

	/**
	 * @return string[] element values in render order
	 */
	public static function forExtension(): array
	{
		return array_column(self::cases(), 'value');
	}
}
