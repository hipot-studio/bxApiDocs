<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Flipchart;

use Bitrix\Main\Error;

/**
 * The refusal of the write node itself: the board was in hand and only the writing of it did not go
 * through. Everything a save stops at before that node - a board the backend never prepared, a board
 * that was not handed over, a session whose file is gone - is a refusal of another kind and carries
 * no such type.
 *
 * The difference is worth a type of its own because a channel that repeats a save answers the two
 * apart: only a write the node itself refused can be stored by a later attempt at the very same
 * board, so only it is worth keeping an editing session open for. A refusal added to the save path
 * later is no refusal of the write node until it is built as one, and the channel above keeps its
 * former answer for it.
 */
final class WriteNodeRefusal extends Error
{
}
