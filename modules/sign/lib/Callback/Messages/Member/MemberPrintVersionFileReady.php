<?php

namespace Bitrix\Sign\Callback\Messages\Member;

use Bitrix\Sign\Callback;

class MemberPrintVersionFileReady extends Callback\Message
{
	public const Type = 'memberPrintVersionFileReady';

	/**
	 * @var array{
	 *     documentUid: string,
	 *     memberUid: string,
	 *     filePath: string,
	 * }
	 */
	protected array $data = [
		'documentUid' => '',
		'memberUid' => '',
	];

	public function getDocumentUid(): string
	{
		return $this->data['documentUid'];
	}

	public function setDocumentUid(string $documentUid): self
	{
		$this->data['documentUid'] = $documentUid;

		return $this;
	}

	public function getMemberUid(): string
	{
		return $this->data['memberUid'];
	}

	public function setMemberUid(string $memberUid): self
	{
		$this->data['memberUid'] = $memberUid;

		return $this;
	}
}
