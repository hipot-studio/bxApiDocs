<?
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

\Bitrix\Main\Loader::includeModule('mail');

class CMailContactAvatarComponent extends \CBitrixComponent
{

	public function executeComponent()
	{
		$page = 'avatar_default';
		$imageUrl = trim((string)($this->arParams['imageUrl'] ?? ''));
		$this->arResult['testId'] = isset($this->arParams['testId'])
			&& is_string($this->arParams['testId'])
			&& preg_match('/^[a-z0-9-]+$/D', $this->arParams['testId']) === 1
				? $this->arParams['testId']
				: ''
		;
		$mailContact = $this->arParams['mailContact'] ?? null;
		$fileId = 0;
		$color = '';
		$initials = '';
		$avatarSize = $this->arParams['avatarSize'] ?? null;
		$this->arResult['avatarSize'] = $avatarSize && is_numeric($avatarSize) ? (int)$avatarSize : 22;
		if (!empty($mailContact) && is_array($mailContact))
		{
			$fileId = !empty($mailContact['FILE_ID']) ? $mailContact['FILE_ID'] : 0;
			if (!empty($mailContact['ICON']) && is_array($mailContact['ICON'])
				&& !empty($mailContact['ICON']['COLOR']) && !empty($mailContact['ICON']['INITIALS']))
			{
				$color = $mailContact['ICON']['COLOR'];
				$initials = $mailContact['ICON']['INITIALS'];
			}
		}
		$email = $this->arParams['email'] ?? null;
		$name = $this->arParams['name'] ?? null;

		$s = $this->arResult['avatarSize'];
		$this->arResult['initialsFontSize'] = floor($s / 2) - abs($s % 2 - floor($s / 2) % 2);

		if ($fileId && is_numeric($fileId))
		{
			$image = \CFile::resizeImageGet(
				$fileId, ['width' => $this->arResult['avatarSize'], 'height' => $this->arResult['avatarSize']],
				BX_RESIZE_IMAGE_EXACT, false
			);
			if ($image['src'])
			{
				$this->arResult['image'] = $image;
				$page = 'avatar';
			}
		}
		else
		{
			if ($email && $name && $color)
			{
				$page = 'icon';
				$this->arResult['COLOR'] = $color;
				$this->arResult['INITIALS'] = \Bitrix\Mail\Helper\MailContact::getInitials($email, $name);
			}
			elseif ($color && $email)
			{
				$page = 'icon';
				$this->arResult['COLOR'] = $color;
				$this->arResult['INITIALS'] = \Bitrix\Mail\Helper\MailContact::getInitials($email);
			}
			elseif ($color && $initials)
			{
				$page = 'icon';
				$this->arResult['COLOR'] = $color;
				$this->arResult['INITIALS'] = $initials;
			}
		}

		// A protected url is served by an access controller, so the contact image or the initials stay
		// underneath it and become visible when the request is refused.
		if ($imageUrl !== '')
		{
			$this->arResult['imageUrl'] = $imageUrl;
			$page = 'avatar_url';
		}

		$this->includeComponentTemplate($page);
	}
}
