<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Exception\Application\AppInstallRequest;

class AppInstallRequestAlreadyExistsException extends AppInstallRequestException
{
	public const CODE = 'already_exists';

	public function __construct(private readonly ?int $existingRequestId = null)
	{
		parent::__construct();
	}

	public function getExistingRequestId(): ?int
	{
		return $this->existingRequestId;
	}

	protected function getMessagePhrase(): string
	{
		return 'REST_APP_INSTALL_REQUEST_EXCEPTION_ALREADY_EXISTS';
	}
}
