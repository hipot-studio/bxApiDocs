<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Enum;

/**
 * What a value naming an object of Disk has been taken from.
 *
 * A number means one thing in a setting filled by hand - the identifier of an object - and another one in
 * the value of a file field, where the platform keeps the identifier of an attachment. Attachments and
 * objects are numbered in tables of their own and their identifiers overlap, so the shape of the value
 * tells the two apart no better than a coin: the source has to be named.
 */
enum ObjectValueSource: string
{
	case ObjectId = 'object';
	case FileField = 'file_field';
}
