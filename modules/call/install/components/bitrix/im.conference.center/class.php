<?php

use Bitrix\Main\Error;
use Bitrix\Main\ErrorCollection;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    exit;
}

Loc::loadMessages(__FILE__);

class ConferenceCenterComponent extends CBitrixComponent
{
    /** @var ErrorCollection */
    protected $errors;

    public function executeComponent()
    {
        $this->errors = new ErrorCollection();
        $this->initParams();
        if (!$this->checkRequiredParams()) {
            $this->printErrors();

            return;
        }

        if (!$this->prepareResult()) {
            $this->printErrors();

            return;
        }

        if ('' !== $this->arResult['COMPONENT_PAGE']) {
            $this->includeComponentTemplate($this->arResult['COMPONENT_PAGE']);
        } else {
            LocalRedirect('/conference/');
        }
    }

    protected function checkRequiredParams()
    {
        if (!Loader::includeModule('im')) {
            $this->errors->setError(new Error('Module `im` is not installed.'));

            return false;
        }

        return true;
    }

    protected function initParams()
    {
        $this->arParams['SEF_MODE'] = $this->arParams['SEF_MODE'] ?? 'Y';
        $this->arParams['SEF_FOLDER'] = $this->arParams['SEF_FOLDER'] ?? '';
        $this->arParams['ELEMENT_ID'] = $this->arParams['ELEMENT_ID'] ?? $this->request->get('id');

        $this->arParams['IFRAME'] = $this->arParams['IFRAME'] ?? true;
        $this->arResult['NAME_TEMPLATE'] = empty($this->arParams['NAME_TEMPLATE']) ? CSite::GetNameFormat(false) : str_replace(['#NOBR#', '#/NOBR#'], ['', ''], $this->arParams['NAME_TEMPLATE']);
        $this->arResult['PATH_TO_USER_PROFILE'] = $this->arParams['PATH_TO_USER_PROFILE'] ?? '/company/personal/user/#id#/';

        if (!isset($this->arParams['VARIABLE_ALIASES'])) {
            $this->arParams['VARIABLE_ALIASES'] = [
                'list' => [],
                'edit' => [],
                'add' => [],
            ];
        }

        $arDefaultUrlTemplates404 = [
            'add' => 'edit/0/',
            'edit' => 'edit/#id#/',
            'list' => 'list',
        ];

        $componentPage = 'list';
        if ('Y' === $this->arParams['SEF_MODE']) {
            $arDefaultVariableAliases404 = [];
            $arComponentVariables = ['id'];
            $arVariables = [];
            $arUrlTemplates = CComponentEngine::makeComponentUrlTemplates($arDefaultUrlTemplates404, $this->arParams['SEF_URL_TEMPLATES']);
            $arVariableAliases = CComponentEngine::makeComponentVariableAliases($arDefaultVariableAliases404, $this->arParams['VARIABLE_ALIASES']);
            $componentPage = CComponentEngine::parseComponentPath($this->arParams['SEF_FOLDER'], $arUrlTemplates, $arVariables);

            if (!(is_string($componentPage) && isset($componentPage[0], $arDefaultUrlTemplates404[$componentPage]))) {
                $componentPage = 'list';
            }

            CComponentEngine::initComponentVariables($componentPage, $arComponentVariables, $arVariableAliases, $arVariables);
            foreach ($arUrlTemplates as $url => $value) {
                $key = 'PATH_TO_'.mb_strtoupper($url);
                $this->arResult[$key] = isset($this->arParams[$key][0]) ? $this->arParams[$key] : $this->arParams['SEF_FOLDER'].$value;
            }
        } else {
            $arComponentVariables = [
                isset($this->arParams['VARIABLE_ALIASES']['id']) ? $this->arParams['VARIABLE_ALIASES']['id'] : 'id',
            ];

            $arDefaultVariableAliases = [
                'id' => 'id',
            ];
            $arVariables = [];
            $arVariableAliases = CComponentEngine::makeComponentVariableAliases($arDefaultVariableAliases, $this->arParams['VARIABLE_ALIASES']);
            CComponentEngine::initComponentVariables(false, $arComponentVariables, $arVariableAliases, $arVariables);

            if (isset($_REQUEST['edit'])) {
                $componentPage = 'edit';
            }

            // @var CMain $APPLICATION
            global $APPLICATION;
            foreach ($arDefaultUrlTemplates404 as $url => $value) {
                $key = 'PATH_TO_'.mb_strtoupper($url);
                $value = mb_substr($value, 0, -1);
                $value = str_replace('/', '&ID=', $value);
                $lang = isset($_REQUEST['lang']) ? $_REQUEST['lang'] : null;
                $this->arResult[$key] = $APPLICATION->GetCurPage()."?{$value}".($lang ? "&lang={$lang}" : '');
            }
        }

        $componentPage = 'add' === $componentPage ? 'edit' : $componentPage;

        if (!is_array($this->arResult)) {
            $this->arResult = [];
        }

        $this->arResult = array_merge(
            [
                'COMPONENT_PAGE' => $componentPage,
                'VARIABLES' => $arVariables,
                'ALIASES' => 'Y' === $this->arParams['SEF_MODE'] ? [] : $arVariableAliases,
                'ID' => isset($arVariables['id']) ? (int) ($arVariables['id']) : '',
                'PATH_TO_USER_PROFILE' => $this->arParams['PATH_TO_USER_PROFILE'],
            ],
            $this->arResult
        );
    }

    protected function prepareResult()
    {
        return true;
    }

    protected function printErrors()
    {
        foreach ($this->errors as $error) {
            ShowError($error);
        }
    }
}
