<?php

namespace Bitrix\Main\UserField\File;

class UploaderContextGenerator
{
	public function __construct(
		private readonly array $userField
	)
	{
	}

	public function getContextInEditMode(UploadSession $session): array
	{
		$options = array_merge(
			$this->getBaseOptions(),
			[
				'sessionId' => $session->getSessionId(),
			]
		);

		return $this->signOptions($options);
	}

	public function getContextForFileInViewMode(int $fileId): array
	{
		$options = array_merge(
			$this->getBaseOptions(),
			[
				'fileId' => $fileId,
			]
		);

		return $this->signOptions($options);
	}

	public function getControlId(): string
	{
		return md5(serialize($this->userField));
	}

	private function getBaseOptions(): array
	{
		return [
			'id' => (int)($this->userField['ID'] ?? 0),
			'entityId' => (string)($this->userField['ENTITY_ID'] ?? ''),
			'entityValueId' => (string)($this->userField['ENTITY_VALUE_ID'] ?? ''),
			'fieldName' => (string)($this->userField['FIELD_NAME'] ?? ''),
			'multiple' => ($this->userField['MULTIPLE'] ?? '') === 'Y',
		];
	}

	private function signOptions(array $options): array
	{
		$signer = new UploaderFileSigner();
		$options['controllerOptions'] = [
			'signed' => $signer->sign($options),
		];

		return $options;
	}
}
