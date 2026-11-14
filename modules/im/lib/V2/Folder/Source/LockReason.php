<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder\Source;

/**
 * Why the switch of a composition item is inactive - the reason published next to
 * {@see SourceItem::$togglable}, which keeps its own meaning of "the switch is active".
 *
 * A showcase source is locked for good: it is shown on the screen and never becomes a real
 * source of "All" ({@see \Bitrix\Im\V2\Folder\System\ShowcaseSourceEntry}). The source of a
 * section whose navigation folder the owner has hidden is locked only while it stays hidden
 * (AC-015), because a hidden section reaches its chats through "All" alone.
 *
 * A value the client does not know reads as a locked source with the text it already shows, so
 * a reason added later needs no release of the client.
 */
enum LockReason: string
{
	case Showcase = 'showcase';
	case FolderHidden = 'folder_hidden';
}
