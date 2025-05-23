<?php

namespace Bitrix\Crm\Security\Role\Manage;

use Bitrix\Crm\Security\EntityPermission\DefaultPermission;
use Bitrix\Crm\Security\Role\Manage\DTO\EntityDTO;
use Bitrix\Crm\Security\Role\Manage\Entity\Button;
use Bitrix\Crm\Security\Role\Manage\Entity\Company;
use Bitrix\Crm\Security\Role\Manage\Entity\Contact;
use Bitrix\Crm\Security\Role\Manage\Entity\Deal;
use Bitrix\Crm\Security\Role\Manage\Entity\DynamicItem;
use Bitrix\Crm\Security\Role\Manage\Entity\Exclusion;
use Bitrix\Crm\Security\Role\Manage\Entity\Lead;
use Bitrix\Crm\Security\Role\Manage\Entity\OldInvoice;
use Bitrix\Crm\Security\Role\Manage\Entity\Order;
use Bitrix\Crm\Security\Role\Manage\Entity\PermissionEntity;
use Bitrix\Crm\Security\Role\Manage\Entity\Quote;
use Bitrix\Crm\Security\Role\Manage\Entity\SaleTarget;
use Bitrix\Crm\Security\Role\Manage\Entity\SmartInvoice;
use Bitrix\Crm\Security\Role\Manage\Entity\WebForm;
use Bitrix\Crm\Security\Role\Manage\Permissions\Add;
use Bitrix\Crm\Security\Role\Manage\Permissions\Automation;
use Bitrix\Crm\Security\Role\Manage\Permissions\Delete;
use Bitrix\Crm\Security\Role\Manage\Permissions\Export;
use Bitrix\Crm\Security\Role\Manage\Permissions\HideSum;
use Bitrix\Crm\Security\Role\Manage\Permissions\Import;
use Bitrix\Crm\Security\Role\Manage\Permissions\MyCardView;
use Bitrix\Crm\Security\Role\Manage\Permissions\Read;
use Bitrix\Crm\Security\Role\Manage\Permissions\Transition;
use Bitrix\Crm\Security\Role\Manage\Permissions\Write;
use Bitrix\Crm\Traits\Singleton;

class entitiesbuilder
{
    use Singleton;

    private ?array $entities = null;

    /**
     * @return PermissionEntity[]
     */
    public function entities(): array
    {
        if (null === $this->entities) {
            $this->entities = self::allEntities();
        }

        return $this->entities;
    }

    public function getEntityNamesWithPermissionClass(DefaultPermission $defaultPermission): array
    {
        $result = [];

        $entities = $this->create();
        foreach ($entities as $entity) {
            $permissions = $entity->permissions();
            foreach ($permissions as $permission) {
                if ($permission::class === $defaultPermission->getPermissionClass()) {
                    $result[] = $entity->code();

                    break;
                }
            }
        }

        return $result;
    }

    /**
     * @return EntityDTO[]
     */
    public function create(): array
    {
        $result = [];
        foreach ($this->entities() as $permEntity) {
            foreach ($permEntity->make() as $entity) {
                $result[] = $entity;
            }
        }

        return $result;
    }

    public static function allEntities(): array
    {
        return [
            new Contact(),
            new Company(),
            new Deal(),
            new Lead(),
            new Quote(),
            new OldInvoice(),
            new SmartInvoice(),
            new Order(),
            new WebForm(),
            new Button(),
            new SaleTarget(),
            new Exclusion(),
            new DynamicItem(),
        ];
    }

    public static function allPermissions(): array
    {
        return [
            new Read([]),
            new Add([]),
            new Write([]),
            new Delete([]),
            new Export([]),
            new Import([]),
            new Automation([]),
            new HideSum([]),
            new MyCardView([]),
            new Transition([]),
        ];
    }
}
