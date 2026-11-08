<?php

namespace Bitrix\StaffTrack\Internal\Model;

/**
 * A row of the office employee table. Not to be confused with
 * {@see \Bitrix\StaffTrack\Item\OfficeEmployee}, which is the shape an employee takes in an answer to a
 * client and carries a name, an avatar and a presence instead of an office and an author.
 */
class OfficeEmployee extends EO_OfficeEmployee
{
}
