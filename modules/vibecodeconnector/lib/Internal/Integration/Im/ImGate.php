<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Im;

use Bitrix\Im\Common;
use Bitrix\Im\User;
use Bitrix\Im\V2\Entity\Url\RichData;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Vibecodeconnector\Internal\Dto\Catalog\Preview\CatalogItemPreview;

class ImGate
{
	private const ICON_SIZE = 80;

	public function isAvailable(): bool
	{
		return Loader::includeModule('im');
	}

	public function getPublicDomain(): string
	{
		if (!$this->isAvailable())
		{
			return '';
		}

		return (string)Common::getPublicDomain();
	}

	public function getUserName(int $userId): string
	{
		$user = $this->findUser($userId);

		return $user === null ? '' : trim((string)$user->getFullName(false));
	}

	public function getUserAvatarPath(int $userId): string
	{
		$user = $this->findUser($userId);

		return $user === null ? '' : (string)$user->getAvatar();
	}

	public function getUserProfilePath(int $userId): string
	{
		$user = $this->findUser($userId);

		return $user === null ? '' : (string)$user->getProfile();
	}

	public function buildAttach(CatalogItemPreview $preview): \CIMMessageParamAttach|false
	{
		if (!$this->isAvailable())
		{
			return false;
		}

		$attach = new \CIMMessageParamAttach(null, $preview->color);
		$attach->SetDescription(\CIMMessageParamAttach::SKIP_MESSAGE);
		$attach->AddLink($this->buildLinkBlock($preview));

		if ($preview->authorName !== '' || (int)$preview->appChatId > 0)
		{
			$attach->AddDelimiter();
		}

		if ($preview->authorName !== '')
		{
			$attach->AddUser($this->buildAuthorBlock($preview));
		}

		if ((int)$preview->appChatId > 0)
		{
			$attach->AddChat([
				'NAME' => (string)Loc::getMessage('VIBECODECONNECTOR_IM_GATE_APP_CHAT'),
				'LINK' => '/online/?IM_DIALOG=chat' . $preview->appChatId,
			]);
		}

		return $attach;
	}

	public function buildRichData(CatalogItemPreview $preview): RichData|false
	{
		if (!$this->isAvailable())
		{
			return false;
		}

		return (new RichData())
			->setType(RichData::DYNAMIC_TYPE)
			->setName($preview->title)
			->setDescription($preview->description)
			->setPreviewUrl($preview->iconUrl)
			->setLink($preview->pageUrl)
		;
	}

	protected function findUser(int $userId): ?User
	{
		if ($userId <= 0 || !$this->isAvailable())
		{
			return null;
		}

		return User::getInstance($userId);
	}

	private function buildLinkBlock(CatalogItemPreview $preview): array
	{
		$link = [
			'NAME' => $preview->title,
			'LINK' => $preview->pageUrl,
			'DESC' => $preview->description,
		];

		if ($preview->iconUrl !== null)
		{
			$link['PREVIEW'] = $preview->iconUrl;
			$link['WIDTH'] = self::ICON_SIZE;
			$link['HEIGHT'] = self::ICON_SIZE;
		}

		return $link;
	}

	private function buildAuthorBlock(CatalogItemPreview $preview): array
	{
		$author = ['NAME' => $preview->authorName];

		if ($preview->authorProfileUrl !== null)
		{
			$author['LINK'] = $preview->authorProfileUrl;
		}

		if ($preview->authorAvatarUrl !== null)
		{
			$author['AVATAR'] = $preview->authorAvatarUrl;
		}

		return $author;
	}
}
