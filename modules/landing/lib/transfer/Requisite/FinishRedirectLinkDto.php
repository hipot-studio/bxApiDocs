<?php
declare(strict_types=1);

namespace Bitrix\Landing\Transfer\Requisite;

/**
 * Final redirect link data for import finish button.
 * Scope hooks may return only part of fields (e.g. href, href + text).
 */
final class FinishRedirectLinkDto
{
	public function __construct(
		public ?string $href = null,
		public ?string $text = null,
		public ?string $className = null,
		public ?string $target = null,
		public ?string $dataIsSite = null,
		public ?int $dataSiteId = null,
		public ?string $dataIsLanding = null,
		public ?int $dataLandingId = null,
		public ?int $dataReplaceLid = null,
	)
	{
	}

	/**
	 * Apply non-null values from override DTO.
	 */
	public function applyOverride(self $override): void
	{
		$this->href = $override->href ?? $this->href;
		$this->text = $override->text ?? $this->text;
		$this->className = $override->className ?? $this->className;
		$this->target = $override->target ?? $this->target;
		$this->dataIsSite = $override->dataIsSite ?? $this->dataIsSite;
		$this->dataSiteId = $override->dataSiteId ?? $this->dataSiteId;
		$this->dataIsLanding = $override->dataIsLanding ?? $this->dataIsLanding;
		$this->dataLandingId = $override->dataLandingId ?? $this->dataLandingId;
		$this->dataReplaceLid = $override->dataReplaceLid ?? $this->dataReplaceLid;
	}
}

