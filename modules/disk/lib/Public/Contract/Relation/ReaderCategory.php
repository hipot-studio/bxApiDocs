<?php
declare(strict_types=1);

namespace Bitrix\Disk\Public\Contract\Relation;

/**
 * Category of the reader by the rules of Disk; the value is chosen by Disk, never by the provider.
 *
 * @see RelationProviderInterface::confirmsReaderCategory()
 */
enum ReaderCategory: string
{
	case Employee = 'employee';
	case Extranet = 'extranet';
	case Collaber = 'collaber';
}
