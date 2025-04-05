<?php

namespace Bitrix\Crm\Service\Timeline\Item\Activity\Booking;

use Bitrix\Booking\Internals\Integration\Crm\BookingActivity;
use Bitrix\Crm\Service\Timeline\Context;
use Bitrix\Crm\Service\Timeline\Item\Activity;
use Bitrix\Crm\Service\Timeline\Item\Model;
use Bitrix\Crm\Service\Timeline\Layout;
use Bitrix\Crm\Service\Timeline\Layout\Action;
use Bitrix\Crm\Service\Timeline\Layout\Action\Animation;
use Bitrix\Crm\Service\Timeline\Layout\Action\RunAjaxJsonAction;
use Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock;
use Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock\ContentBlockWithTitle;
use Bitrix\Crm\Service\Timeline\Layout\Common\Icon;
use Bitrix\Crm\Service\Timeline\Layout\Footer\Button;
use Bitrix\Crm\Service\Timeline\Layout\Menu\MenuItem;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type\DateTime;

class Booking extends Activity
{
	private bool $isBookingLoaded = false;

	public function __construct(Context $context, Model $model)
	{
		if (Loader::includeModule('booking'))
		{
			$this->isBookingLoaded = true;
		}

		parent::__construct($context, $model);
	}

	protected function getActivityTypeId(): string
	{
		return 'Booking';
	}

	public function getTitle(): ?string
	{
		return Loc::getMessage('CRM_TIMELINE_BOOKING_TITLE');
	}

	public function getIconCode(): ?string
	{
		return Icon::BOOKING;
	}

	public function getLogo(): ?Layout\Body\Logo
	{
		$booking = $this->getAssociatedEntityModelFields();
		$bookingDateStart = DateTime::createFromTimestamp($booking['datePeriod']['from']['timestamp']);

		$logo = new Layout\Body\CalendarLogo($bookingDateStart);
		$logo->setIconType(Layout\Body\Logo::ICON_TYPE_SUCCESS);

		return $logo;
	}

	public function getTags(): ?array
	{
		return [];
	}

	public function getContentBlocks(): ?array
	{
		$result = [];

		$bookingStartBlock = $this->buildBookingStartBlock();
		if ($bookingStartBlock)
		{
			$result['bookingStart'] = $bookingStartBlock;
		}

		$primaryResourceBlockWeb = $this->buildPrimaryResourceBlock(ContentBlock::SCOPE_WEB);
		$primaryResourceBlockMob = $this->buildPrimaryResourceBlock(ContentBlock::SCOPE_MOBILE);

		if ($primaryResourceBlockWeb)
		{
			$result['primaryResourceWeb'] = $primaryResourceBlockWeb;
		}

		if ($primaryResourceBlockMob)
		{
			$result['primaryResourceMob'] = $primaryResourceBlockMob;
		}

		$secondaryResourceBlockWeb = $this->buildSecondaryResourceBlock(ContentBlock::SCOPE_WEB);
		$secondaryResourceBlockMob = $this->buildSecondaryResourceBlock(ContentBlock::SCOPE_MOBILE);

		if ($secondaryResourceBlockWeb)
		{
			$result['secondaryResourceWeb'] = $secondaryResourceBlockWeb;
		}

		if ($secondaryResourceBlockMob)
		{
			$result['secondaryResourceMob'] = $secondaryResourceBlockMob;
		}

		return $result;
	}

	public function getButtons(): array
	{
		$result = [];

		$result['openButton'] = (new Button(
			Loc::getMessage('CRM_TIMELINE_BOOKING_BTN_OPEN'),
			Button::TYPE_PRIMARY)
		)
			->setScopeWeb()
			->setAction($this->getOpenBookingAction())
		;

		if (!$this->isBookingLoaded)
		{
			return $result;
		}

		if (!$this->isScheduled())
		{
			return $result;
		}

		$bookingId = $this->getBookingId();
		$messageMenuItems = $this->getMessageMenuItems(
			$bookingId,
			BookingActivity::getMessageMenuItems($bookingId)
		);

		if (!empty($messageMenuItems))
		{
			$result['messageButton'] = (new Button(
				Loc::getMessage('CRM_TIMELINE_BOOKING_BTN_MESSAGE') ?? '',
				Button::TYPE_SECONDARY
			))
				->setScopeWeb()
				->setMenuItems($messageMenuItems)
			;
		}

		$result['cancelButton'] = (new Button(
			Loc::getMessage('CRM_TIMELINE_BOOKING_BTN_CANCEL') ?? '',
			Button::TYPE_SECONDARY
		))
			->setScopeWeb()
			->setAction(
				(new Layout\Action\RunAjaxJsonAction(
					BookingActivity::getDeleteBookingEndpoint()
				))
					->addActionParamInt('id', $bookingId)
					->setAnimation(Layout\Action\Animation::showLoaderForBlock())
			);

		return $result;
	}

	private function getMessageMenuItems(int $bookingId, array $messageMenuItems): array
	{
		$result = [];

		foreach ($messageMenuItems as $messageMenuItem)
		{
			$action = (new RunAjaxJsonAction(BookingActivity::getSendBookingMessageEndpoint()))
				->addActionParamInt('bookingId', $bookingId)
				->setAnimation(Animation::showLoaderForBlock())
			;
			$params = (isset($messageMenuItem['params']) && is_array($messageMenuItem['params']))
				? $messageMenuItem['params']
				: []
			;
			foreach ($params as $paramName => $paramValue)
			{
				$action->addActionParamString($paramName, $paramValue);
			}

			$result[$messageMenuItem['code'] ?? ''] = (new MenuItem($messageMenuItem['name'] ?? ''))
				->setAction($action);
		}

		return $result;
	}

	public function needShowNotes(): bool
	{
		return false;
	}

	public function getMenuItems(): array
	{
		$items = parent::getMenuItems();

		unset($items['edit'], $items['view']);

		return $items;
	}

	private function buildBookingStartBlock(): ContentBlockWithTitle|null
	{
		$fields = $this->getAssociatedEntityModelFields();
		$dateStart = $fields['datePeriod']['from']['timestamp'] ?? null;

		if (!$dateStart)
		{
			return null;
		}

		$titleBlockObject = new ContentBlockWithTitle();
		$titleBlockObject
			->setInline()
			->setTitle(Loc::getMessage('CRM_TIMELINE_BOOKING_CONTENT_BLOCK_START_TIME_TITLE'))
			->setContentBlock(
				(new ContentBlock\EditableDate())
					->setStyle(ContentBlock\EditableDate::STYLE_PILL)
					->setDate(DateTime::createFromTimestamp($dateStart))
			);

		return $titleBlockObject;
	}

	private function buildPrimaryResourceBlock(string $scope): ContentBlockWithTitle|null
	{
		$fields = $this->getAssociatedEntityModelFields();
		$primaryResource = $fields['resources'][0] ?? null;

		if (!$primaryResource)
		{
			return null;
		}

		$resourceTypeName = $primaryResource['type']['name']
			?? Loc::getMessage('CRM_TIMELINE_BOOKING_CONTENT_BLOCK_PRIMARY_RESOURCE_TITLE');

		$titleBlockObject = new ContentBlockWithTitle();
		$titleBlockObject
			->setInline()
			->setTitle($resourceTypeName);

		switch ($scope)
		{
			case ContentBlock::SCOPE_WEB:
				$titleBlockObject
					->setScopeWeb()
					->setContentBlock(
						(new ContentBlock\Link())
							->setValue($primaryResource['name'])
							->setAction($this->getOpenBookingAction()),
					);
				break;
			case ContentBlock::SCOPE_MOBILE:
				$titleBlockObject
					->setScopeMobile()
					->setContentBlock(
						(new ContentBlock\Text())
							->setValue($primaryResource['name'])
					);
				break;
		}

		return $titleBlockObject;
	}

	private function buildSecondaryResourceBlock(string $scope): ContentBlockWithTitle|null
	{
		$fields = $this->getAssociatedEntityModelFields();
		$resources = $fields['resources'] ?? null;

		if (empty($resources) || count($resources) <= 1)
		{
			return null;
		}

		$secondaryResourceNames = [];

		foreach (array_slice($resources, 1) as $resource)
		{
			$secondaryResourceNames[]= $resource['name'];
		}

		$titleBlockObject = new ContentBlockWithTitle();
		$titleBlockObject
			->setInline()
			->setTitle(Loc::getMessage('CRM_TIMELINE_BOOKING_CONTENT_BLOCK_SECONDARY_RESOURCE_TITLE'));

		switch ($scope)
		{
			case ContentBlock::SCOPE_WEB:
				$titleBlockObject
					->setScopeWeb()
					->setContentBlock(
						(new ContentBlock\Link())
							->setValue(implode(', ', $secondaryResourceNames))
							->setAction($this->getOpenBookingAction()),
					);
				break;
			case ContentBlock::SCOPE_MOBILE:
				$titleBlockObject
					->setScopeMobile()
					->setContentBlock(
						(new ContentBlock\Text())
							->setValue(implode(', ', $secondaryResourceNames))
					);
				break;
		}

		return $titleBlockObject;
	}

	private function getOpenBookingAction(): Action\JsEvent
	{
		return (new Action\JsEvent($this->getType() . ':ShowBooking'))
			->addActionParamInt('id', $this->getBookingId());
	}

	private function getBookingId(): int
	{
		$associatedEntityId = $this->getAssociatedEntityModel()->get('ASSOCIATED_ENTITY_ID') ?? null;
		if (!$associatedEntityId)
		{
			return 0;
		}

		return (int)$associatedEntityId;
	}

	private function getAssociatedEntityModelFields(): array
	{
		$settings = $this->getAssociatedEntityModel()->get('SETTINGS');
		$settings = is_array($settings) ? $settings : [];

		return isset($settings['FIELDS']) && is_array($settings['FIELDS']) ? $settings['FIELDS'] : [];
	}
}
