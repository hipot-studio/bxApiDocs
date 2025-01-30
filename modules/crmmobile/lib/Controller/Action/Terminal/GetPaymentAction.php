<?php

namespace Bitrix\CrmMobile\Controller\Action\Terminal;

use Bitrix\Crm\Order\Permissions;
use Bitrix\CrmMobile\Controller\Action;
use Bitrix\CrmMobile\Terminal\GetPaymentQuery;
use Bitrix\Main\Engine\CurrentUser;

class GetPaymentAction extends Action
{
    public function run(int $id, CurrentUser $currentUser)
    {
        if (!Permissions\Payment::checkReadPermission($id)) {
            return null;
        }

        return (new GetPaymentQuery($id))->execute();
    }
}
