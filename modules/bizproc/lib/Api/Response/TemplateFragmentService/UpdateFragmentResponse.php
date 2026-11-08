<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Api\Response\TemplateFragmentService;

use Bitrix\Bizproc\Api\Data\TemplateFragmentService\Fragment;
use Bitrix\Bizproc\Result;

/**
 * The fragment as it is after the change, with the verdicts recounted: a fragment made personal is still
 * managed by its author, and the list of the library is not read again to learn that.
 */
final class UpdateFragmentResponse extends Result
{
	public function getFragment(): ?Fragment
	{
		return $this->data['fragment'] ?? null;
	}

	public function setFragment(Fragment $fragment): self
	{
		$this->data['fragment'] = $fragment;

		return $this;
	}
}
