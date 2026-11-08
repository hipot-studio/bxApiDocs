<?php

namespace Bitrix\Landing\Vibe;

use Bitrix\Main\Type\Contract\Arrayable;

final readonly class VibeRenderOptions implements Arrayable
{
	/**
	 * Configures the Vibe settings section rendering.
	 *
	 * @param bool $canShowPublishButton
	 * @param bool $canShowWithdrawButton
	 * @param bool $canRedirectToVibeAfterEdit
	 */
	public function __construct(
		public bool $canShowPublishButton = true,
		public bool $canShowWithdrawButton = true,
		public bool $canRedirectToVibeAfterEdit = true,
	)
	{
	}

	/**
	 * Returns options in the VibeSection format.
	 *
	 * @return array<string, bool>
	 */
	public function toArray(): array
	{
		return [
			'canShowPublishButton' => $this->canShowPublishButton,
			'canShowWithdrawButton' => $this->canShowWithdrawButton,
			'canRedirectToVibeAfterEdit' => $this->canRedirectToVibeAfterEdit,
		];
	}
}
