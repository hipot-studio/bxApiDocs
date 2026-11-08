<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Sync\Card;

use Bitrix\Disk\Infrastructure\Rest\V3\Dto\Sync\PathNodeDto;

/**
 * The one place a path becomes publishable, and the one place the boundary of access is enforced on
 * it.
 *
 * The legacy resolver of the desktop client closes into refusal here: an ancestor the user may not
 * read turns the whole object into an empty stub, and the comment in that code names the reason -
 * the name of a foreign folder must not leak. The contour needs the object itself, so the path is
 * cut instead of the object being dropped; what must not change is that a folder above the boundary
 * contributes nothing - not a name, not a node, not a hint of one.
 *
 * A folder whose name was withheld therefore does not shorten the path by one: it and everything
 * above it are dropped together, because a path with a hole in it would let the client infer the
 * depth of what it may not see. The cut always leaves a suffix - the deepest folders, the ones
 * nearest the object.
 *
 * Whether the path is whole is decided here as well, and by the same fact: a path is whole exactly
 * when nothing had to be cut out of it. A boundary met anywhere on the way - in the chain itself or
 * in the chain of a link the route passes through - reaches this method as a folder without a name
 * and makes the answer say so.
 */
final class PathTrimmer
{
	/**
	 * @param array<int, array{id: int, name: string|null}> $chain the folders of the place from the
	 *        top of the storage down to the parent; a folder the user may not read carries a null
	 *        name
	 * @return array{path: PathNodeDto[], complete: bool}
	 */
	public function trim(array $chain): array
	{
		$cutAt = -1;
		foreach ($chain as $index => $node)
		{
			if ($node['name'] === null)
			{
				$cutAt = $index;
			}
		}

		$path = [];
		foreach (array_slice($chain, $cutAt + 1) as $node)
		{
			$pathNode = new PathNodeDto();
			$pathNode->id = $node['id'];
			$pathNode->name = (string)$node['name'];
			$path[] = $pathNode;
		}

		return [
			'path' => $path,
			'complete' => $cutAt < 0,
		];
	}
}
