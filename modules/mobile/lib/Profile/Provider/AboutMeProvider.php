<?php

namespace Bitrix\Mobile\Profile\Provider;

use Bitrix\Intranet\Public\Provider\User\UserProfilePostProvider;
use Bitrix\Intranet\User\Command\UpdateProfilePostCommand;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Mobile\Provider\DiskFileProvider;

class AboutMeProvider
{
	private int $userId;

	public function __construct(private ?int $ownerId = null)
	{
		$this->userId = CurrentUser::get()->getId();
		$this->ownerId = ($ownerId ?? $this->userId);
	}

	public function getData(): array
	{
		$aboutMePost = UserProfilePostProvider::createByDefault()->getByUserId($this->ownerId);

		return [
			'text' => $aboutMePost?->text ?? '',
			'files' => (new DiskFileProvider())->getDiskFileAttachmentsWithDto($aboutMePost?->fileIds ?? []),
		];
	}
}
