<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Integration\Bizproc;

/**
 * Which nodes the structure data node asks for, counted from the source node. The values are stored in
 * saved workflow templates, so they are the vocabulary of the node setting and not of the org structure.
 */
enum StructureSelectionMode: string
{
	case Element = 'element';
	case All = 'all';
	case Parent = 'parent';
	case Children = 'children';
	case Branch = 'branch';
}
