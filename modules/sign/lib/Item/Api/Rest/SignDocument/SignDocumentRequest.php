<?php
declare(strict_types=1);

namespace Bitrix\Sign\Item\Api\Rest\SignDocument;

/**
 * Class SignDocumentRequest
 *
 * Represents the request to send a document for signing.
 */
class SignDocumentRequest
{
	public SignDocumentFields $fields;

	/**
	 * Create instance from associative array.
	 *
	 * @param array $data
	 * @return self
	 */
	public static function fromArray(array $data): self
	{
		$self = new self();

		if (isset($data['fields']) && is_array($data['fields']))
		{
			$self->fields = SignDocumentFields::fromArray($data['fields']);
		}

		return $self;
	}

	/**
	 * @return SignDocumentFields
	 */
	public function getFields(): SignDocumentFields
	{
		return $this->fields;
	}
}