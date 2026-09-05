<?php
declare(strict_types=1);

namespace Bitrix\Landing\Metrika;

/**
 * Kind of the block content changed by a single save.
 * One save may change several kinds at once - each of them makes its own event.
 */
enum BlockContentKinds: string
{
	case Text = 'text';
	case Image = 'image';
	case Form = 'form';
}
