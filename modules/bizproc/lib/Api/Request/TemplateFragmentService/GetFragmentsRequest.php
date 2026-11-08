<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Api\Request\TemplateFragmentService;

/**
 * The library is one per portal and is not narrowed by the document type: what a person sees in it depends
 * on their rights and on the visibility of every fragment, and on nothing they could pass here.
 */
final class GetFragmentsRequest
{
	public function __construct(
		public readonly int $userId,
		public readonly int $limit,
		public readonly int $offset = 0,
	)
	{}
}
