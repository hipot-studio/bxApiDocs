<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Api\Response\TemplateFragmentService;

use Bitrix\Bizproc\Api\Data\TemplateFragmentService\Fragment;
use Bitrix\Bizproc\Result;

/**
 * One page of the library. Whether there is more is answered by asking for one fragment more than the page
 * holds, so the answer costs no second count of the rows.
 */
final class GetFragmentsResponse extends Result
{
	/**
	 * @return Fragment[]
	 */
	public function getFragments(): array
	{
		return $this->data['fragments'] ?? [];
	}

	/**
	 * @param Fragment[] $fragments
	 */
	public function setFragments(array $fragments): self
	{
		$this->data['fragments'] = $fragments;

		return $this;
	}

	public function hasMore(): bool
	{
		return $this->data['hasMore'] ?? false;
	}

	public function setHasMore(bool $hasMore): self
	{
		$this->data['hasMore'] = $hasMore;

		return $this;
	}
}
