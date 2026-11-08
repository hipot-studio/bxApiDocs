<?php

namespace Bitrix\Crm\MessageSender\UI\Editor\Scene;

use Bitrix\Main\Localization\Loc;
use Bitrix\MessageService\Public\UI\MessageEditor\Context;
use Bitrix\MessageService\Public\UI\MessageEditor\ViewChannel;
use Bitrix\MessageService\Public\UI\SenderCode;

final class ItemDetails extends BaseScene
{
	public const ID = 'crm.item.details';

	public function getId(): string
	{
		return self::ID;
	}

	public function getName(): string
	{
		return (string)Loc::getMessage('CRM_MS_EDITOR_SCENE_ITEM_DETAILS_NAME');
	}

	public function shouldShowInTemplateBindingLabels(): bool
	{
		return false;
	}

	public function filterViewChannels(array $viewChannels, Context $context): array
	{
		$viewChannels = parent::filterViewChannels($viewChannels, $context);

		return array_filter(
			$viewChannels,
			static fn(ViewChannel $vc): bool => $vc->getBackend()->getSenderCode() === SenderCode::SMS_PROVIDER,
		);
	}
}
