<?php

declare(strict_types=1);

namespace Bitrix\Landing\Security;

/**
 * Result of RepoNormalizer: the fields of a b_landing_repo row and the verdict on the content.
 *
 * The verdict is reported, not acted upon - the REST entry answers with an error, the import
 * has its own reaction, and the normalizer knows about neither.
 */
final readonly class NormalizedRepoBlock
{
	public function __construct(
		public array $fields,
		public bool $contentRejected,
	)
	{
	}
}
