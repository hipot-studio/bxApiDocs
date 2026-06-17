<?php

namespace Bitrix\Crm\Service\Timeline\Item\LogMessage\AI;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Timeline\Layout\Action;
use Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock\LineOfTextBlocks;
use Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock\Link;
use Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock\Text;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Web\Uri;

Container::getInstance()->getLocalization()->loadMessages();

final class CallScoringEmptyResult extends Base
{
	public function getType(): string
	{
		return 'CallScoringEmptyResult';
	}

	public function getTitle(): ?string
	{
		return Loc::getMessage('CRM_TIMELINE_LOG_CALL_SCORING_EMPTY_RESULT_TITLE');
	}

	public function getContentBlocks(): ?array
	{
		$result = [
			'description' => (new Text())
				->setValue(Loc::getMessage('CRM_TIMELINE_LOG_CALL_SCORING_EMPTY_RESULT_DESCRIPTION'))
				->setFontSize(13)
				->setColor(Text::COLOR_BASE_70)
			,
		];

		$settings = $this->getModel()->getSettings();
		if (!empty($settings['RECOMMENDATIONS']))
		{
			$recommendations = (new Text())
				->setValue($settings['RECOMMENDATIONS'])
				->setFontSize(13)
				->setColor(Text::COLOR_BASE_70)
			;
			$detail = (new Link())
				->setValue(Loc::getMessage('CRM_COMMON_DETAIL'))
				->setFontSize(13)
				->setAction($this->getOpenAction())
			;
			$result['recommendations'] = (new LineOfTextBlocks())
				->addContentBlock('recommendations', $recommendations)
				->addContentBlock('detail', $detail)
			;
		}

		return $result;
	}

	private function getOpenAction(): ?Action
	{
		$communication = $this->getAssociatedEntityModel()?->get('COMMUNICATION') ?? [];
		$userData =  $this->getUserData($this->getAssociatedEntityModel()?->get('RESPONSIBLE_ID'));
		$createdTimestamp = (new DateTime($this->getAssociatedEntityModel()?->get('CREATED')))->getTimestamp();

		return (new Action\JsEvent('CallScoringResult:Open'))
			->addActionParamInt('activityId', $this->getModel()->getAssociatedEntityId())
			->addActionParamInt('ownerTypeId', $this->getContext()->getEntityTypeId())
			->addActionParamInt('ownerId', $this->getContext()->getEntityId())
			->addActionParamString('clientDetailUrl', isset($communication['SHOW_URL']) ? new Uri($communication['SHOW_URL']) : null)
			->addActionParamString('clientFullName', $communication['TITLE'] ?? '')
			->addActionParamInt('activityCreated', $createdTimestamp)
			->addActionParamString('userPhotoUrl', $userData['PHOTO_URL'] ?? '')
		;
	}
}
