<?php
declare(strict_types=1);

namespace Bitrix\Sign\Item\Api\Rest\SignDocument;

/**
 * Class SignDocumentResponse
 *
 * Represents a signer/member in request payloads.
 */
class SignDocumentResponse
{
	public ?string $uid = null;
	public ?SignDocumentState $state = null;
	/** @var SignDocumentMember[] $members  */
	public array $members = [];

	public function __construct(?string $uid = null, ?SignDocumentState $state = null, array $members = [])
	{
		$this->uid = $uid;
		$this->state = $state;
		$this->members = $members;
	}

	/**
	 * Convert object to array
	 *
	 * @return array
	 */
	public function toArray(): array
	{
		return [
			'uid' => $this->uid,
			'state' => $this->state?->toArray(),
			'members' => array_map(function($item){
				return $item->toArray();
			}, $this->members),
		];
	}
}
