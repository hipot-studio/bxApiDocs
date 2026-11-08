<?php

namespace Bitrix\Crm\Service\UserPermissions;

/**
 * The server surface asking {@see Configuration} for a configuration capability.
 *
 * The decision is made by {@see Configuration::can()} inside the contour of the scope, and the case
 * does not enter it. The case stays for two reasons: it is the inventory of the surfaces the model
 * covers, and it is the key of the former check, which two paths still take - a contour excluded from
 * the model ({@see ConfigurationScope::isExcluded()}) and a rollback of the registration, which hands
 * every surface back exactly what it answered before. The former check cannot be derived from the
 * capability, because the surfaces disagree: managing stages was the global CRM administrator check
 * in `CCrmStatus` and the contextual `isAdminForEntity()` one in the category domain and in the
 * mobile controllers - so the surface has to name itself. A case is one surface, or one action of it
 * where the actions of a surface used to check different things.
 *
 * Three cases cover a surface whose actions did check different things and are told apart by the kind of
 * {@see ConfigurationScope} the caller passes rather than by a case of their own, so the kind is part of
 * their contract:
 *
 * - `TypeControllerUpdate` - `ofDynamicTypeTarget()` for a type being created
 *   (`crm/lib/controller/type.php:335-337`), `ofEntityType()` for an existing one (`:342-344`), and
 *   `ofDynamicTypeTarget()` again for the contour an existing type is moved to (`:398-399`), asked of
 *   CRM and of the workplace alike. A move asks for the right of both ends of it, in either direction:
 *   the source contour is the one `ofEntityType()` has already answered for, and the target contour is
 *   that third call. A request naming no target contour at all moves nothing and never makes it;
 * - `DynamicTypePermissions` - `ofDynamicTypeTarget()` for `DynamicType::canAdd()` (`:33-36`),
 *   `ofEntityType()` for `DynamicType::canUpdate()` (`:81-84`). The gate of the type list section
 *   (`crm.type.list/class.php::checkPermissions()`) shares the case rather than keeping one of its own:
 *   its former check was the same contour check of the same kind of scope - `isCrmAdmin()` filtered to
 *   CRM, `isAutomatedSolutionAdmin()` filtered to one workplace. The right to manage the list of
 *   workplaces that gate also asks for belongs to no case: it is not a configuration operation;
 * - `MobileCategoryController` - the case keeps the contextual check with the category, so a path that
 *   used to ask about the entity type alone has to pass a scope carrying no category. With the category:
 *   `crmmobile/lib/Controller/Category.php:246` `canDelete()`, `:316` `canUpdate()` and the tunnel scheme
 *   behind `:635`. Without it: `:219` `canAdd()` of a funnel that has no id yet, `:322` for an entity
 *   type whose funnels are off, `:517`/`:589` `canUserEditCategory()`, `:678` `editable` and `:202`.
 *
 * A scope of the other kind hands the caller the former check of the other action, and the two calls of
 * `TypeControllerUpdate` stand in one method, so getting them mixed up costs nothing.
 *
 * `TypeControllerAdd` answers its former check for `ofDynamicTypeTarget()` alone: the add path names the
 * target contour and has no entity to resolve one from (`crm/lib/controller/type.php:256-258`).
 *
 * `TypeControllerDelete` is shared by the delete entry of the type list grid row
 * (`crm.type.list/class.php::prepareGrid()`): the entry triggers exactly that action of the controller,
 * so it must not offer what the action refuses. The edit entry of the same row opens the type card and
 * asks what the card asks, {@see DynamicType::canUpdate()}, so it has no case of its own either.
 *
 * `TypeControllerRead` is the two read actions of the type controller that were closed to everybody but
 * the CRM administrator: `fieldsAction()` (`crm/lib/controller/type.php:55`, the `crm.type.fields`
 * action) and `listAction()` (`:123`, `crm.type.list`). The former check of both was the global
 * `isCrmAdmin()`, so the case is theirs alone rather than shared with `TypeControllerUpdate`. The
 * contour is the one the request names: a list filtered down to one workplace asks for the contour of
 * that workplace, and every other list asks for CRM - unfiltered, filtered to several workplaces, or
 * filtered by a key carrying an operator prefix, none of which narrows the answer to one contour. The
 * remaining two reads have no case of their own: `getAction()` (`:71`) and
 * `getByEntityTypeIdAction()` (`:89`) keep their former check, which falls back to `canReadItems()` and
 * is milder than the right.
 *
 * `FieldsSettingsMenu` is the field settings entry that opens the user field screen of an entity type,
 * offered by the settings menu of the item list
 * ({@see \Bitrix\Crm\Component\ItemList::getToolbarSettingsItems()}), the quote menu and the row menu
 * of the type list grid. The item list asked the contextual check, the quote menu asked for the CRM
 * administrator, and the row of the grid asked nothing at all and takes the contextual check. The
 * screen behind the entry is a surface of its own and keeps the global check of `FieldsListComponent`.
 *
 * `MobileStageController` and `SalesTunnelsComponent` ignore the category of the scope, and a caller
 * asking for `ManageTunnels` still has to pass the source funnel it resolved on the server: once the
 * operations are registered that capability ({@see Configuration::can()}) reads the robots right of the
 * funnel of the scope, and without a category that is the right of funnel `0`
 * ({@see Automation::canEdit()}). The funnel screen asks for it once per funnel it shows; the rest of
 * what it asks is of the entity type as a whole.
 *
 * `DealCategoryListAjax` is the funnel screen of Deal and the mass deletion behind it: the gate of
 * `crm.deal_category.list/class.php:36` `executeComponent()`, and `:325` `checkCurrentAccess()` and
 * `:421` `canDeleteCategory()` of
 * {@see \Bitrix\Crm\V2\Internal\Service\Category\Deal\BatchDeleteService}. The name is older than the
 * surface: the `ajax.php` and the `component.php` it was named after were deleted when the screen
 * became a grid, and nothing asked for the case at all until these three points were wired onto it.
 * Only `canDeleteCategory()` names a category, and it shares the case rather than keeping one of its
 * own - the two forms of the former check disagree only for the contractor category, which Deal has
 * none of.
 *
 * `McpStageService` is the stage tools the AI assistant is given
 * ({@see \Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Service\StageService}), four points of one
 * service: `:146` `renameStage()`, `:190` `deleteStage()`, `:232` `setStageList()` and `:482`
 * `prepare()`, the shared entry of `createStage()`, `listStages()` and `setStageList()` - so the one
 * tool that has a point of its own is asked twice, as it was before. All four asked
 * `isAdminForEntity($entityTypeId, $categoryId)` with the category the point had resolved: the
 * normalised category of the request under `prepare()`, the category of the stage itself where a stage
 * is named. The funnel tools of the same server keep no case of their own - they reach the funnel CRUD
 * through {@see \Bitrix\Crm\Service\UserPermissions\EntityPermissions\Category} and are answered by
 * `CategoryPermissions` - and that disagreement between the two halves of one surface is what this case
 * ends.
 *
 * `ItemDetailsFieldCreation` and `FactoryBasedFieldCreation` are the create-a-field control of the
 * item card. One flag carries both the control and the create signature the server issues next to it
 * (`ENABLE_USER_FIELD_CREATION` and `USER_FIELD_CREATE_SIGNATURE`), so the button and the write it
 * leads to cannot disagree. Two cases rather than one because the cards asked different former
 * checks: the cards of lead, deal, contact and company go through
 * {@see \Bitrix\Crm\Component\EntityDetails\Traits\InitializeUFConfig} and asked about the entity type
 * alone, while every factory-based card - smart process, quote, smart invoice, sign document -
 * ({@see \Bitrix\Crm\Component\EntityDetails\FactoryBased::getEditorConfig()}) passed the category of
 * the card on. The field screen the control links to is a surface of its own and keeps its own case,
 * `FieldsEditComponent`.
 *
 * `RequirementProvider` reads the requirement settings of a field in the resolved entity and category,
 * while `RequirementCommand` changes them. Their former check was contextual and passed the category on;
 * the registered model answers both through {@see Configuration::canManageFields()}.
 *
 * The common views and the common card layout are four surfaces of one screen, told apart by what
 * names the card: `EntityEditorPermissions` is
 * {@see \Bitrix\Crm\Service\UserPermissions\EntityEditor::canEditCommonView()} and every consumer of
 * it, where the card is whatever the consumer knows and a consumer that knows none is answered for CRM
 * as a whole; `EntityEditorConfig` is the write path of {@see \Bitrix\Crm\Entity\EntityEditorConfig};
 * `EntityEditorComponent` is `crm.entity.editor` itself; `ScopeAccess` is the scopes of the card
 * ({@see \Bitrix\Crm\EntityForm\ScopeAccess}), whose card comes out of the option name the scope was
 * saved under. Of the four only `EntityEditorComponent` and `ScopeAccess` used the contextual check,
 * and both of them passed the category on.
 *
 * The AI surfaces of the same screen are two more: `McpPageLayout` is the page layout tools of the
 * assistant ({@see \Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Service\PageLayoutService}) and
 * `AiCardViewCreate` is the create function of the card view. Both asked the contextual check with the
 * category. Assigning a layout to other users is deliberately not theirs and stays on the former
 * administrative check - it writes the options of those users rather than the layout of the card.
 *
 * The former check of every case lives in one place,
 * {@see Configuration::legacyDecision()}.
 */
enum ConfigurationCallSite: string
{
	// funnels, stages, funnel access and tunnels
	case CategoryDomainService = 'categoryDomainService';
	case CategoryPermissions = 'categoryPermissions';
	case SalesTunnelsComponent = 'salesTunnelsComponent';
	case KanbanComponent = 'kanbanComponent';
	case TunnelManager = 'tunnelManager';
	case DealCategoryListAjax = 'dealCategoryListAjax';
	case McpStageService = 'mcpStageService';
	case LegacyRestDealCategory = 'legacyRestDealCategory';
	case StatusDictionary = 'statusDictionary';

	// user fields
	case UserFieldAccess = 'userFieldAccess';
	case FieldsListComponent = 'fieldsListComponent';
	case FieldsEditComponent = 'fieldsEditComponent';
	case FieldsSettingsMenu = 'fieldsSettingsMenu';
	case ItemDetailsFieldCreation = 'itemDetailsFieldCreation';
	case FactoryBasedFieldCreation = 'factoryBasedFieldCreation';
	case RequirementProvider = 'requirementProvider';
	case RequirementCommand = 'requirementCommand';

	// common views and the common card layout
	case EntityEditorPermissions = 'entityEditorPermissions';
	case EntityEditorConfig = 'entityEditorConfig';
	case EntityEditorComponent = 'entityEditorComponent';
	case ScopeAccess = 'scopeAccess';
	case McpPageLayout = 'mcpPageLayout';
	case AiCardViewCreate = 'aiCardViewCreate';

	// business process templates
	case BizprocDocument = 'bizprocDocument';
	case BizprocTemplateComponent = 'bizprocTemplateComponent';

	// smart process types
	case DynamicTypePermissions = 'dynamicTypePermissions';
	case TypeControllerAdd = 'typeControllerAdd';
	case TypeControllerUpdate = 'typeControllerUpdate';
	case TypeControllerDelete = 'typeControllerDelete';
	case TypeControllerRead = 'typeControllerRead';

	// mobile CRM
	case MobileCategoryController = 'mobileCategoryController';
	case MobileStageController = 'mobileStageController';

	// the settings section of CRM, one surface asking for several capabilities at once
	case CrmConfigsComponent = 'crmConfigsComponent';
}
