<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Api\Response\TemplateFragmentService;

use Bitrix\Bizproc\Api\Data\TemplateFragmentService\Fragment;
use Bitrix\Bizproc\Result;

final class CreateFragmentResponse extends Result
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
