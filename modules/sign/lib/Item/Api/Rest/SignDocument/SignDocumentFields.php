<?php
declare(strict_types=1);

namespace Bitrix\Sign\Item\Api\Rest\SignDocument;

/**
 * Class SignDocumentRequest
 *
 * Represents the request to send a document for signing.
 */
class SignDocumentFields
{
	public ?CompanyRequestData $company = null;
	/** @var SignMemberRequestData[] */
	public array $signers = [];
	public array $members = [];
	/** @var SignMemberRequestData[] */
	public array $representatives = [];
	public ?SignMemberRequestData $responsible = null;
	public ?SignMemberRequestData $reviewer = null;
	public ?SignMemberRequestData $editor = null;
	public ?string $companyProviderUid = null;
	/** @var SignDocumentFile[] */
	public array $files = [];
	public ?string $regionDocumentType = null;
	public ?string $language = null;
	public ?ExternalSettings $externalSettings = null;

	/**
	 * Create instance from associative array.
	 *
	 * @param array $data
	 * @return self
	 */
	public static function fromArray(array $data): self
	{
		$self = new self();

		if (isset($data['company']) && is_array($data['company']))
		{
			$self->company = CompanyRequestData::fromArray($data['company']);
		}

		if (!empty($data['signers']) && is_array($data['signers']))
		{
			foreach ($data['signers'] as $item) {
				if (is_array($item)) {
					$self->signers[] = SignMemberRequestData::fromArray($item);
				}
			}
		}

		if (!empty($data['members']) && is_array($data['members']))
		{
			foreach ($data['members'] as $item) {
				if (is_array($item)) {
					$self->members[] = SignMemberRequestData::fromArray($item);
				}
			}
		}

		if (!empty($data['representatives']) && is_array($data['representatives']))
		{
			foreach ($data['representatives'] as $item) {
				if (is_array($item)) {
					$self->representatives[] = SignMemberRequestData::fromArray($item);
				}
			}
		}

		if (isset($data['responsible']) && is_array($data['responsible']))
		{
			$self->responsible = SignMemberRequestData::fromArray($data['responsible']);
		}

		if (isset($data['reviewer']) && is_array($data['reviewer']))
		{
			$self->reviewer = SignMemberRequestData::fromArray($data['reviewer']);
		}

		if (isset($data['editor']) && is_array($data['editor']))
		{
			$self->editor = SignMemberRequestData::fromArray($data['editor']);
		}

		if (isset($data['companyProviderUid']))
		{
			$self->companyProviderUid = is_scalar($data['companyProviderUid']) ? (string)$data['companyProviderUid'] : null;
		}

		if (!empty($data['files']) && is_array($data['files']))
		{
			foreach ($data['files'] as $item) {
				if (is_array($item)) {
					$self->files[] = SignDocumentFile::fromArray($item);
				}
			}
		}

		if (isset($data['regionDocumentType']))
		{
			$self->regionDocumentType = is_scalar($data['regionDocumentType']) ? (string)$data['regionDocumentType'] : null;
		}

		if (isset($data['language']))
		{
			$self->language = is_scalar($data['language']) ? (string)$data['language'] : null;
		}

		if (isset($data['externalSettings']) && is_array($data['externalSettings']))
		{
			$self->externalSettings = ExternalSettings::fromArray($data['externalSettings']);
		}

		return $self;
	}

	/**
	 * @return CompanyRequestData|null
	 */
	public function getCompany(): ?CompanyRequestData
	{
		return $this->company;
	}

	/**
	 * @return SignMemberRequestData[]
	 */
	public function getSigners(): array
	{
		return $this->signers;
	}

	/**
	 * @return SignMemberRequestData[]
	 */
	public function getMembers(): array
	{
		return $this->members;
	}

	/**
	 * @return SignMemberRequestData[]
	 */
	public function getRepresentatives(): array
	{
		return $this->representatives;
	}

	/**
	 * @return SignMemberRequestData|null
	 */
	public function getResponsible(): ?SignMemberRequestData
	{
		return $this->responsible;
	}

	/**
	 * @return SignMemberData|null
	 */
	public function getReviewer(): ?SignMemberRequestData
	{
		return $this->reviewer;
	}

	/**
	 * @return SignMemberRequestData|null
	 */
	public function getEditor(): ?SignMemberRequestData
	{
		return $this->editor;
	}

	public function getCompanyProviderUid(): ?string
	{
		return $this->companyProviderUid;
	}

	/**
	 * @return SignDocumentFile[]
	 */
	public function getFiles(): array
	{
		return $this->files;
	}

	/**
	 * @return string|null
	 */
	public function getRegionDocumentType(): ?string
	{
		return $this->regionDocumentType;
	}

	/**
	 * @return ExternalSettings|null
	 */
	public function getExternalSettings(): ?ExternalSettings
	{
		return $this->externalSettings;
	}

	public function getLanguage(): ?string
	{
		return $this->language;
	}
}