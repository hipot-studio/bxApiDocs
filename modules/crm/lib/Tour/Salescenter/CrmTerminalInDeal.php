<?php

namespace Bitrix\Crm\Tour\Salescenter;

use Bitrix\Crm\Entity\EntityEditorConfig;
use Bitrix\Crm\Entity\EntityEditorConfigScope;
use Bitrix\Crm\Service\EditorAdapter;
use Bitrix\Crm\Terminal\AvailabilityManager;
use Bitrix\Crm\Tour\Base;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Localization\Loc;

class CrmTerminalInDeal extends Base
{
    protected const OPTION_NAME = 'crm-terminal-in-deal';

    protected int $categoryId = 0;
    protected ?string $target = null;

    public function setCategoryId(int $categoryId): self
    {
        $this->categoryId = $categoryId;

        return $this;
    }

    protected function canShow(): bool
    {
        if (
            $this->isUserSeenTour()
            || !$this->canShowCrmTerminalTour()
        ) {
            return false;
        }

        $this->initializeTarget();
        if (!$this->target) {
            return false;
        }

        return AvailabilityManager::getInstance()->isAvailable();
    }

    protected function getSteps(): array
    {
        return [
            [
                'id' => 'step-'.self::OPTION_NAME,
                'target' => $this->target,
                'title' => Loc::getMessage('CRM_TERMINAL_IN_DEAL_TOUR_TITLE'),
                'text' => Loc::getMessage('CRM_TERMINAL_IN_DEAL_TOUR_TEXT'),
            ],
        ];
    }

    protected function getOptions(): array
    {
        return [
            'steps' => [
                'popup' => [
                    'width' => 320,
                ],
            ],
            'showOverlayFromFirstStep' => false,
            'hideTourOnMissClick' => true,
        ];
    }

    private function canShowCrmTerminalTour(): bool
    {
        return 'Y' === Option::get('crm', 'can-show-crm-terminal-tour', 'N');
    }

    private function initializeTarget(): void
    {
        $config = new EntityEditorConfig(
            \CCrmOwnerType::Deal,
            (int) CurrentUser::get()->getId(),
            EntityEditorConfigScope::COMMON,
            [
                'CATEGORY_ID' => $this->categoryId,
            ]
        );

        $opportunityField = $config->getFormField(EditorAdapter::FIELD_OPPORTUNITY);
        if (!$opportunityField) {
            return;
        }

        $isReceivePaymentButtonHidden = (
            isset($opportunityField['options']['isPayButtonVisible'])
            && 'false' === $opportunityField['options']['isPayButtonVisible']
        );

        $this->target =
            $isReceivePaymentButtonHidden
                ? '.crm-entity-widget-payment-add'
                : '.crm-entity-widget-content-block-inner-pay-button';
    }
}
