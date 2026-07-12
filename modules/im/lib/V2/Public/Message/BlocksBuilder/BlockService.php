<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Public\Message\BlocksBuilder;

use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Message\BlocksBuilder\BuilderResult;

class BlockService
{
	public function appendBlock(Message $message, BlockItem $blockItem): BuilderResult
	{
		return (new Message\BlocksBuilder\BuilderUpdater())->appendBlock($message, $blockItem->blockData);
	}

	public function updateBlock(Message $message, string $blockId, BlockItem $blockItem): BuilderResult
	{
		return (new Message\BlocksBuilder\BuilderUpdater())->updateBlock($message, $blockId, $blockItem->blockData);
	}

	public function deleteBlock(Message $message, string $blockId): BuilderResult
	{
		return (new Message\BlocksBuilder\BuilderUpdater())->deleteBlock($message, $blockId);
	}
}
