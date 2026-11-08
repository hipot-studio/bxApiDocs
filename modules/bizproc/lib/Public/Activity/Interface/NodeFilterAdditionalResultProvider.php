<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Activity\Interface;

/**
 * A node whose filter publishes, besides the documents it finds, documents of another module related to
 * them - so an action of a neighbour node can take one as an ordinary document of its own type.
 */
interface NodeFilterAdditionalResultProvider
{
	/**
	 * Descriptors of those documents, keyed by the key the runtime resolver answers with under
	 * `additional`. The key is appended to the filter id to make the property id.
	 *
	 * @return array<string, array{Name: string, Default: array}>
	 */
	public static function getNodeFilterAdditionalResults(): array;
}
