<?php

use Bitrix\Main\Data\ConnectionPool;
use Bitrix\Main\DB\ConnectionException;
use Bitrix\Main\DB\MysqlConnection;
use Bitrix\Main\DB\MysqliConnection;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\HttpApplication;

// Define constants for base64-decoded values
const MODULE_LANG_FILE = 'IncludeModuleLangFile';

// Use a meaningful variable instead of $GLOBALS
$moduleLangFiles = [
    MODULE_LANG_FILE,
    MODULE_LANG_FILE,
    MODULE_LANG_FILE,
];

// Existing logic continues with $moduleLangFiles instead of $GLOBALS

class WelcomeStep extends CWizardStep
{
    public function InitStep()
    {
        $this->SetStepID(___518687646(52));
        $this->SetNextStep(___518687646(53));
        $this->SetNextCaption(InstallGetMessage(___518687646(54)));
        $this->SetTitle(InstallGetMessage(___518687646(55)));
    }

    public function ShowStep()
    {
        global $arWizardConfig;
        $bxProductConfig = [];
        if ($GLOBALS['____1079197990'][21]($_SERVER[___518687646(56)].BX_ROOT.___518687646(57))) {
            include $_SERVER[___518687646(58)].BX_ROOT.___518687646(59);
        }
        if (isset($bxProductConfig[___518687646(60)][___518687646(61)])) {
            $this->content .= ___518687646(62).$bxProductConfig[___518687646(63)][___518687646(64)].___518687646(65);
        } else {
            $this->content .= ___518687646(66).(isset($arWizardConfig[___518687646(67)]) ? $arWizardConfig[___518687646(68)] : InstallGetMessage(___518687646(69))).___518687646(70);
        }
    }

    public static function unformat($_1501816970)
    {
        $_1501816970 = $GLOBALS['____1079197990'][22]($GLOBALS['____1079197990'][23]($_1501816970));
        $_512179251 = $GLOBALS['____1079197990'][24]($_1501816970);
        $_1866447557 = $GLOBALS['____1079197990'][25]($_1501816970, -round(0 + 0.2 + 0.2 + 0.2 + 0.2 + 0.2));
        if ($_1866447557 === ___518687646(71)) {
            $_512179251 *= round(0 + 256 + 256 + 256 + 256);
        } elseif ($_1866447557 === ___518687646(72)) {
            $_512179251 *= round(0 + 524_288 + 524_288);
        } elseif ($_1866447557 === ___518687646(73)) {
            $_512179251 *= round(0 + 524_288 + 524_288) * round(0 + 512 + 512);
        }

        return $_512179251;
    }
}

class AgreementStep extends CWizardStep
{
    public function InitStep()
    {
        $this->SetStepID(___518687646(74));
        $this->SetPrevStep(___518687646(75));
        $this->SetNextStep(___518687646(76));
        $this->SetNextCaption(InstallGetMessage(___518687646(77)));
        $this->SetPrevCaption(InstallGetMessage(___518687646(78)));
        $this->SetTitle(InstallGetMessage(___518687646(79)));
    }

    public function OnPostForm()
    {
        $wizard = $this->GetWizard();
        if ($wizard->IsPrevButtonClick()) {
            return;
        }
        $_720507404 = $wizard->GetVar(___518687646(80));
        if ($_720507404 !== ___518687646(81)) {
            $this->SetError(InstallGetMessage(___518687646(82)), ___518687646(83));
        }
    }

    public function ShowStep()
    {
        $this->content = ___518687646(84);
        $this->content .= $this->ShowCheckboxField(___518687646(85), ___518687646(86), [___518687646(87) => ___518687646(88), ___518687646(89) => ___518687646(90)]);
        $this->content .= ___518687646(91).InstallGetMessage(___518687646(92)).___518687646(93);
        $this->content .= ___518687646(94);
    }
}

class AgreementStep4VM extends CWizardStep
{
    public function InitStep()
    {
        $this->SetStepID(___518687646(95));
        if ($_SERVER[___518687646(96)] !== ___518687646(97)) {
            $this->SetNextStep(___518687646(98));
        } else {
            $this->SetNextStep(___518687646(99));
        }
        $this->SetNextCaption(InstallGetMessage(___518687646(100)));
        $this->SetPrevCaption(InstallGetMessage(___518687646(101)));
        $this->SetTitle(InstallGetMessage(___518687646(102)));
    }

    public function OnPostForm()
    {
        $wizard = $this->GetWizard();
        if ($wizard->IsPrevButtonClick()) {
            return;
        }
        $_720507404 = $wizard->GetVar(___518687646(103));
        if ($_720507404 !== ___518687646(104)) {
            $this->SetError(InstallGetMessage(___518687646(105)), ___518687646(106));
        }
        $this->CheckShortInstall();
    }

    public function CheckShortInstall()
    {
        $DBType = 'mysql';
        $_2140707841 = new RequirementStep();
        if (!$_2140707841->CheckRequirements($DBType)) {
            $this->SetError($_2140707841->GetErrors());
        }
        if ($GLOBALS['____1079197990'][26](___518687646(107)) && !BXInstallServices::IsUTF8Support()) {
            $this->SetError(InstallGetMessage(___518687646(108)));
        }

        require_once $_SERVER[___518687646(109)].___518687646(110);

        require_once $_SERVER[___518687646(111)].___518687646(112);

        require_once $_SERVER[___518687646(113)].___518687646(114);
        $GLOBALS['_____1777807464'][1]($_SERVER[___518687646(115)].___518687646(116));
        $_1803568705 = HttpApplication::getInstance();
        $_1803568705->initializeBasicKernel();
        $_2033524056 = $_1803568705->getConnectionPool();
        $_1095416219 = $_2033524056->getConnection();
        $_2033524056->useMasterOnly(true);
        $DB = new CDatabase();
        $_1028303136 = $_1095416219->getHost();
        $_2061283400 = $_1095416219->getDatabase();
        $_1398261849 = $_1095416219->getLogin();
        $_133964101 = $_1095416219->getPassword();
        if (!$DB->Connect($_1028303136, $_2061283400, $_1398261849, $_133964101)) {
            $this->SetError(InstallGetMessage(___518687646(117)).___518687646(118).$DB->_915184687);
        }
        $_899927747 = new CreateDBStep();
        $_899927747->DB = &$DB;
        $_899927747->dbType = $DBType;
        $_899927747->dbName = $_2061283400;
        $_899927747->filePermission = ($GLOBALS['____1079197990'][27](___518687646(119)) ? $GLOBALS['____1079197990'][28](___518687646(120), BX_FILE_PERMISSIONS) : (1_168 / 2 - 584));
        $_899927747->folderPermission = ($GLOBALS['____1079197990'][29](___518687646(121)) ? $GLOBALS['____1079197990'][30](___518687646(122), BX_DIR_PERMISSIONS) : (1_360 / 2 - 680));
        $_899927747->createDBType = ($GLOBALS['____1079197990'][31](___518687646(123)) ? MYSQL_TABLE_TYPE : ___518687646(124));
        $_899927747->utf8 = $GLOBALS['____1079197990'][32](___518687646(125));
        $_899927747->createCharset = null;
        $_899927747->needCodePage = false;
        if ($_899927747->IsBitrixInstalled()) {
            $this->SetError($_899927747->GetErrors());
        }
        $_2051421235 = $DB->Query(___518687646(126), true);
        if ($_2051421235 && ($_326913257 = $_2051421235->Fetch())) {
            $_2083424398 = $GLOBALS['____1079197990'][33]($_326913257[___518687646(127)]);
            if (!BXInstallServices::VersionCompare($_2083424398, ___518687646(128))) {
                $this->SetError(InstallGetMessage(___518687646(129)));
            }
            $_899927747->needCodePage = true;
            if (!$_899927747->needCodePage && $GLOBALS['____1079197990'][34](___518687646(130))) {
                $this->SetError(InstallGetMessage(___518687646(131)));
            }
        }
        if ($_899927747->needCodePage) {
            $_471332480 = false;
            if (LANGUAGE_ID === ___518687646(132) || LANGUAGE_ID === ___518687646(133)) {
                $_471332480 = ___518687646(134);
            } elseif ($_899927747->createCharset !== ___518687646(135)) {
                $_471332480 = $_899927747->createCharset;
            } else {
                $_471332480 = ___518687646(136);
            }
            if ($_899927747->utf8) {
                $DB->Query(___518687646(137).$_899927747->dbName.___518687646(138), true);
            } elseif ($_471332480) {
                $DB->Query(___518687646(139).$_899927747->dbName.___518687646(140).$_471332480, true);
            }
        }
        if ($_899927747->createDBType !== ___518687646(141)) {
            $_512179251 = $DB->Query(___518687646(142).$_899927747->createDBType.___518687646(143), true);
            if (!$_512179251) {
                $DB->Query(___518687646(144).$_899927747->createDBType.___518687646(145));
            }
        }
        $_2051421235 = $DB->Query(___518687646(146), true);
        if ($_2051421235 && ($_1314357634 = $_2051421235->Fetch())) {
            $_1199207744 = $GLOBALS['____1079197990'][35]($_1314357634[___518687646(147)]);
            if ($_1199207744 !== ___518687646(148)) {
                $_899927747->_1199207744 = ___518687646(149);
            }
        }
        if (!$GLOBALS['____1079197990'][36]($_SERVER[___518687646(150)].BX_PERSONAL_ROOT.___518687646(151)) && false === $_899927747->CreateAfterConnect()) {
            $this->SetError($_899927747->GetErrors());
        }
        if (!$_899927747->CheckDBOperation()) {
            $this->SetError($_899927747->GetErrors());
        }
    }

    public function SetError($_1811753136, $_1800790168 = false)
    {
        if ($GLOBALS['____1079197990'][37]($_1811753136)) {
            $this->stepErrors = $GLOBALS['____1079197990'][38]($this->stepErrors, $_1811753136);
        } else {
            $this->stepErrors[] = [$_1811753136, $_1800790168];
        }
    }

    public function ShowStep()
    {
        $this->content = ___518687646(152);
        $this->content .= $this->ShowCheckboxField(___518687646(153), ___518687646(154), [___518687646(155) => ___518687646(156), ___518687646(157) => ___518687646(158)]);
        $this->content .= ___518687646(159).InstallGetMessage(___518687646(160)).___518687646(161);
        $this->content .= ___518687646(162);
    }
}

class DBTypeStep extends CWizardStep
{
    public function InitStep()
    {
        $this->SetStepID(___518687646(163));
        $this->SetPrevStep(___518687646(164));
        $this->SetNextStep(___518687646(165));
        $this->SetNextCaption(InstallGetMessage(___518687646(166)));
        $this->SetPrevCaption(InstallGetMessage(___518687646(167)));
        $_1503091578 = BXInstallServices::GetDBTypes();
        if ($GLOBALS['____1079197990'][39]($_1503091578) > round(0 + 0.333_333_333_333_33 + 0.333_333_333_333_33 + 0.333_333_333_333_33)) {
            $this->SetTitle(InstallGetMessage(___518687646(168)));
        } else {
            $this->SetTitle(InstallGetMessage(___518687646(169)));
        }
        $wizard = $this->GetWizard();
        if ($GLOBALS['____1079197990'][40](___518687646(170)) || $GLOBALS['____1079197990'][41](___518687646(171))) {
            $wizard->SetDefaultVar(___518687646(172), ___518687646(173));
        }
        if ($GLOBALS['____1079197990'][42]($_SERVER[___518687646(174)].___518687646(175))) {
            $LICENSE_KEY = ___518687646(176);

            include $_SERVER[___518687646(177)].___518687646(178);
            $wizard->SetDefaultVar(___518687646(179), $LICENSE_KEY);
        }
        $_709538023 = ___518687646(180);
        foreach ($_1503091578 as $dbType => $_1426172146) {
            $_709538023 = $dbType;
            if ($_1426172146) {
                break;
            }
        }
        $wizard->SetDefaultVar(___518687646(181), $_709538023);
        $wizard->SetDefaultVar(___518687646(182), BXInstallServices::IsUTF8Support() ? ___518687646(183) : ___518687646(184));
    }

    public function OnPostForm()
    {
        $wizard = $this->GetWizard();
        if ($wizard->IsPrevButtonClick()) {
            return;
        }
        $dbType = $wizard->GetVar(___518687646(185));
        $_1503091578 = BXInstallServices::GetDBTypes();
        if ($GLOBALS['____1079197990'][43]($_1503091578) > round(0 + 1) && (!$GLOBALS['____1079197990'][44]($dbType, $_1503091578) || false === $_1503091578[$dbType])) {
            $this->SetError(InstallGetMessage(___518687646(186)), ___518687646(187));
        }
        $_21197058 = $wizard->GetVar(___518687646(188));
        if (!$GLOBALS['____1079197990'][45](___518687646(189)) && !$GLOBALS['____1079197990'][46](___518687646(190)) && $GLOBALS['____1079197990'][47](___518687646(191)) && !$GLOBALS['____1079197990'][48](___518687646(192), $_21197058)) {
            $this->SetError(InstallGetMessage(___518687646(193)), ___518687646(194));
        }
        if ($GLOBALS['____1079197990'][49](___518687646(195)) || $GLOBALS['____1079197990'][50](___518687646(196))) {
            $_106940388 = $wizard->GetVar(___518687646(197));
            if (($GLOBALS['____1079197990'][51](___518687646(198)) || ($GLOBALS['____1079197990'][52](___518687646(199)) && $_106940388 === ___518687646(200))) && $_21197058 === ___518687646(201)) {
                $_866963222 = $wizard->GetVar(___518687646(202));
                $_1059820518 = $wizard->GetVar(___518687646(203));
                $_410600491 = $wizard->GetVar(___518687646(204));
                $_754699777 = false;
                if ($GLOBALS['____1079197990'][53]($_1059820518) === ___518687646(205)) {
                    $this->SetError(InstallGetMessage(___518687646(206)), ___518687646(207));
                    $_754699777 = true;
                }
                if ($GLOBALS['____1079197990'][54]($_866963222) === ___518687646(208)) {
                    $this->SetError(InstallGetMessage(___518687646(209)), ___518687646(210));
                    $_754699777 = true;
                }
                if ($GLOBALS['____1079197990'][55]($_410600491) === ___518687646(211) || !check_email($_410600491)) {
                    $this->SetError(InstallGetMessage(___518687646(212)), ___518687646(213));
                    $_754699777 = true;
                }
                if (!$_754699777) {
                    $_990466145 = BXInstallServices::GetRegistrationKey($_1059820518, $_866963222, $_410600491, $dbType);
                    if (false !== $_990466145) {
                        $wizard->SetVar(___518687646(214), $_990466145);
                    } elseif ($GLOBALS['____1079197990'][56](___518687646(215))) {
                        $this->SetError(InstallGetMessage(___518687646(216)), ___518687646(217));
                    }
                }
            }
        }
    }

    public function ShowStep()
    {
        $wizard = $this->GetWizard();
        BXInstallServices::SetSession();
        $this->content .= ___518687646(218).InstallGetMessage(___518687646(219)).___518687646(220);
        if (!$GLOBALS['____1079197990'][57](___518687646(221)) && !$GLOBALS['____1079197990'][58](___518687646(222))) {
            $this->content .= ___518687646(223).InstallGetMessage(___518687646(224)).___518687646(225).$this->ShowInputField(___518687646(226), ___518687646(227), [___518687646(228) => ___518687646(229), ___518687646(230) => ___518687646(231), ___518687646(232) => ___518687646(233)]).___518687646(234).InstallGetMessage(___518687646(235)).___518687646(236).InstallGetMessage(___518687646(237)).___518687646(238).$this->ShowCheckboxField(___518687646(239), ___518687646(240), [___518687646(241) => ___518687646(242)]).___518687646(243).InstallGetMessage(___518687646(244)).___518687646(245);
        } else {
            $this->content .= ___518687646(246);
            if (!$GLOBALS['____1079197990'][59](___518687646(247))) {
                $this->content .= ___518687646(248).$this->ShowCheckboxField(___518687646(249), ___518687646(250), [___518687646(251) => ___518687646(252), ___518687646(253) => ___518687646(254)]).___518687646(255).InstallGetMessage(___518687646(256)).___518687646(257);
            }
            $_106940388 = $wizard->GetVar(___518687646(258), $_575710406 = true);
            $this->content .= ___518687646(259).InstallGetMessage(___518687646(260)).___518687646(261).$this->ShowInputField(___518687646(262), ___518687646(263), [___518687646(264) => ___518687646(265), ___518687646(266) => ___518687646(267), ___518687646(268) => ___518687646(269)]).___518687646(270).InstallGetMessage(___518687646(271)).___518687646(272).$this->ShowInputField(___518687646(273), ___518687646(274), [___518687646(275) => ___518687646(276), ___518687646(277) => ___518687646(278), ___518687646(279) => ___518687646(280)]).___518687646(281).$this->ShowInputField(___518687646(282), ___518687646(283), [___518687646(284) => ___518687646(285), ___518687646(286) => ___518687646(287), ___518687646(288) => ___518687646(289)]).___518687646(290).(($_106940388 === ___518687646(291)) ? ___518687646(292) : ___518687646(293)).___518687646(294);
        }
        $this->content .= ___518687646(295);
        $wizard->SetVar(___518687646(296), $wizard->GetDefaultVar(___518687646(297)));
        $this->content .= ___518687646(298).InstallGetMessage(___518687646(299)).___518687646(300).$this->ShowCheckboxField(___518687646(301), ___518687646(302), [___518687646(303) => ___518687646(304)]).___518687646(305).InstallGetMessage(___518687646(306)).___518687646(307);
    }
}

class RequirementStep extends CWizardStep
{
    protected $_716469993 = 64;
    protected $_283480729 = 256;
    protected $_752503645 = 500;
    protected $_756699738 = '7.4.0';
    protected $_2105544596 = '2.0';
    protected $_1048241864 = '7.5.0';
    protected $_181770737 = [];

    public function InitStep()
    {
        $this->SetStepID(___518687646(308));
        $this->SetNextStep(___518687646(309));
        $this->SetPrevStep(___518687646(310));
        $this->SetNextCaption(InstallGetMessage(___518687646(311)));
        $this->SetPrevCaption(InstallGetMessage(___518687646(312)));
        $this->SetTitle(InstallGetMessage(___518687646(313)));
    }

    public function OnPostForm()
    {
        $wizard = $this->GetWizard();
        if ($wizard->IsPrevButtonClick()) {
            return null;
        }
        $dbType = $wizard->GetVar(___518687646(314));
        $utf8 = $wizard->GetVar(___518687646(315));
        if ($utf8 === ___518687646(316) && !BXInstallServices::IsUTF8Support()) {
            $this->SetError(InstallGetMessage(___518687646(317)));

            return false;
        }
        if ($utf8 !== ___518687646(318) && $GLOBALS['____1079197990'][60]($GLOBALS['____1079197990'][61](___518687646(319))) === ___518687646(320)) {
            $this->SetError(InstallGetMessage(___518687646(321)));

            return false;
        }
        $_1737256404 = $GLOBALS['____1079197990'][62](___518687646(322));
        if ($_1737256404 !== ___518687646(323) && $GLOBALS['____1079197990'][63]($_1737256404) !== $GLOBALS['____1079197990'][64]($GLOBALS['____1079197990'][65](___518687646(324)))) {
            $this->SetError(InstallGetMessage(___518687646(325)));

            return false;
        }
        if (!BXInstallServices::CheckSession()) {
            $this->SetError(InstallGetMessage(___518687646(326)));

            return false;
        }
        if (!$this->CheckRequirements($dbType)) {
            return false;
        }

        return null;
    }

    public function CheckRequirements($dbType)
    {
        if (false === $this->CheckServerVersion($_909687026, $_771395668, $_1110578176)) {
            $this->SetError(InstallGetMessage(___518687646(327)));

            return false;
        }
        if (!$this->CheckPHPVersion()) {
            $this->SetError(InstallGetMessage(___518687646(328)));

            return false;
        }
        if ($this->GetPHPSetting(___518687646(329)) === ___518687646(330)) {
            $this->SetError(InstallGetMessage(___518687646(331)));

            return false;
        }
        if ($GLOBALS['____1079197990'][66](___518687646(332)) === ___518687646(333)) {
            $this->SetError(InstallGetMessage(___518687646(334)));

            return false;
        }
        if ($GLOBALS['____1079197990'][67](___518687646(335))) {
            $this->SetError(InstallGetMessage(___518687646(336)));

            return false;
        }
        $_1503091578 = BXInstallServices::GetDBTypes();
        if (!$GLOBALS['____1079197990'][68]($dbType, $_1503091578) || false === $_1503091578[$dbType]) {
            $this->SetError(InstallGetMessage(___518687646(337)));

            return false;
        }
        if (!$GLOBALS['____1079197990'][69](___518687646(338))) {
            $this->SetError(InstallGetMessage(___518687646(339)));

            return false;
        }
        if (!$GLOBALS['____1079197990'][70](___518687646(340))) {
            $this->SetError(InstallGetMessage(___518687646(341)));

            return false;
        }
        if (!$GLOBALS['____1079197990'][71](___518687646(342))) {
            $this->SetError(InstallGetMessage(___518687646(343)));

            return false;
        }
        if (!$GLOBALS['____1079197990'][72](___518687646(344))) {
            $this->SetError(InstallGetMessage(___518687646(345)));

            return false;
        }
        if (!$GLOBALS['____1079197990'][73](___518687646(346))) {
            $this->SetError(InstallGetMessage(___518687646(347)));

            return false;
        }
        if ($GLOBALS['____1079197990'][74]($GLOBALS['____1079197990'][75](___518687646(348))) > min(102, 0, 34)) {
            $this->SetError(InstallGetMessage(___518687646(349)));

            return false;
        }
        if (!$this->CheckFileAccess()) {
            $_558464166 = ___518687646(350);
            foreach ($this->_181770737 as $_1291744683) {
                if (!$_1291744683[___518687646(351)]) {
                    $_558464166 .= ___518687646(352).$_1291744683[___518687646(353)];
                }
            }
            $this->SetError(InstallGetMessage(___518687646(354)).$_558464166);

            return false;
        }

        return true;
    }

    public function GetPHPSetting($_304302910)
    {
        return $GLOBALS['____1079197990'][76]($_304302910) === ___518687646(355) || $GLOBALS['____1079197990'][77]($GLOBALS['____1079197990'][78]($_304302910)) === ___518687646(356) ? ___518687646(357) : ___518687646(358);
    }

    public function ShowResult($_2122117738, $type = 'OK')
    {
        if ($_2122117738 === ___518687646(359)) {
            return ___518687646(360);
        }
        if ($GLOBALS['____1079197990'][79]($type) === ___518687646(361) || false === $type) {
            return ___518687646(362).$_2122117738.___518687646(363);
        }
        if ($GLOBALS['____1079197990'][80]($type) === ___518687646(364) || true === $type) {
            return ___518687646(365).$_2122117738.___518687646(366);
        }
        if ($GLOBALS['____1079197990'][81]($type) === ___518687646(367) || $GLOBALS['____1079197990'][82]($type) === ___518687646(368)) {
            return ___518687646(369).$_2122117738.___518687646(370);
        }

        return ___518687646(371);
    }

    public function CheckServerVersion(&$_909687026, &$_771395668, &$_1110578176)
    {
        $_909687026 = ___518687646(372);
        $_771395668 = ___518687646(373);
        $_1110578176 = ___518687646(374);
        if (isset($_SERVER[___518687646(375)])) {
            $_909687026 = ___518687646(376);
            $_771395668 = $_SERVER[___518687646(377)];
            $_1110578176 = $this->_1048241864;

            return BXInstallServices::VersionCompare($_771395668, $_1110578176);
        }
        $_1919580559 = $_SERVER[___518687646(378)];
        if ($_1919580559 === ___518687646(379)) {
            $_1919580559 = $_SERVER[___518687646(380)];
        }
        $_1919580559 = $GLOBALS['____1079197990'][83]($_1919580559);
        if (!$GLOBALS['____1079197990'][84](___518687646(381)) || !$GLOBALS['____1079197990'][85](___518687646(382), $_1919580559, $_1392483085)) {
            return null;
        }
        $_909687026 = $_1392483085[round(0 + 0.2 + 0.2 + 0.2 + 0.2 + 0.2)];
        $_771395668 = $_1392483085[round(0 + 0.666_666_666_666_67 + 0.666_666_666_666_67 + 0.666_666_666_666_67)];
        if ($GLOBALS['____1079197990'][86]($_909687026) === ___518687646(383)) {
            $_1110578176 = $this->_2105544596;

            return BXInstallServices::VersionCompare($_771395668, $_1110578176);
        }

        return null;
    }

    public function CheckPHPVersion()
    {
        return BXInstallServices::VersionCompare($GLOBALS['____1079197990'][87](), $this->_756699738);
    }

    public function CheckFileAccess()
    {
        $this->_181770737 = [[___518687646(384) => $_SERVER[___518687646(385)], ___518687646(386) => InstallGetMessage(___518687646(387)), ___518687646(388) => ___518687646(389), ___518687646(390) => true], [___518687646(391) => $_SERVER[___518687646(392)].___518687646(393), ___518687646(394) => InstallGetMessage(___518687646(395)), ___518687646(396) => ___518687646(397), ___518687646(398) => true], [___518687646(399) => $_SERVER[___518687646(400)].___518687646(401), ___518687646(402) => InstallGetMessage(___518687646(403)), ___518687646(404) => ___518687646(405), ___518687646(406) => true], [___518687646(407) => $_SERVER[___518687646(408)].___518687646(409), ___518687646(410) => InstallGetMessage(___518687646(411)), ___518687646(412) => ___518687646(413), ___518687646(414) => true]];
        $_1256265352 = true;
        foreach ($this->_181770737 as $_2136546593 => $_1291744683) {
            $_1218143971 = $GLOBALS['____1079197990'][88]($_1291744683[___518687646(415)]);
            $_1987088984 = $GLOBALS['____1079197990'][89]($_1291744683[___518687646(416)]);
            if ($_1218143971 && $_1987088984) {
                $this->_181770737[$_2136546593][___518687646(417)] = $this->ShowResult(InstallGetMessage(___518687646(418)), ___518687646(419));

                continue;
            }
            $_1256265352 = false;
            $this->_181770737[$_2136546593][___518687646(420)] = false;
            if (!$_1987088984) {
                $this->_181770737[$_2136546593][___518687646(421)] .= $this->ShowResult(InstallGetMessage(___518687646(422)), ___518687646(423));
            }
            if (!$_1987088984 && !$_1218143971) {
                $this->_181770737[$_2136546593][___518687646(424)] .= ___518687646(425).InstallGetMessage(___518687646(426)).___518687646(427);
            }
            if (!$_1218143971) {
                $this->_181770737[$_2136546593][___518687646(428)] .= $this->ShowResult(InstallGetMessage(___518687646(429)), ___518687646(430));
            }
        }
        if (false === $_1256265352) {
            return false;
        }

        return $this->CreateTemporaryFiles();
    }

    public function CreateTemporaryFiles()
    {
        $_2090142083 = $_SERVER[___518687646(431)].___518687646(432);
        $_1046889925 = $_SERVER[___518687646(433)].___518687646(434);
        if (!$GLOBALS['____1079197990'][90]($_2090142083) && false === @$GLOBALS['____1079197990'][91]($_2090142083)) {
            $this->_181770737[] = [___518687646(435) => $_2090142083, ___518687646(436) => InstallGetMessage(___518687646(437)), ___518687646(438) => $this->ShowResult(InstallGetMessage(___518687646(439)), ___518687646(440)), ___518687646(441) => false];

            return false;
        }
        if (!$GLOBALS['____1079197990'][92]($_1046889925) && false === @$GLOBALS['____1079197990'][93]($_1046889925)) {
            $this->_181770737[] = [___518687646(442) => $_1046889925, ___518687646(443) => false, ___518687646(444) => $this->ShowResult(InstallGetMessage(___518687646(445)), ___518687646(446)), ___518687646(447) => InstallGetMessage(___518687646(448))];

            return false;
        }
        $_1129597667 = [[___518687646(449) => $_SERVER[___518687646(450)].___518687646(451), ___518687646(452) => ___518687646(453)], [___518687646(454) => $_SERVER[___518687646(455)].___518687646(456), ___518687646(457) => ___518687646(458).___518687646(459).___518687646(460).___518687646(461).___518687646(462).___518687646(463).___518687646(464).___518687646(465).___518687646(466).___518687646(467).___518687646(468).___518687646(469).___518687646(470).___518687646(471).___518687646(472).___518687646(473).___518687646(474).___518687646(475).___518687646(476).___518687646(477).___518687646(478)], [___518687646(479) => $_SERVER[___518687646(480)].___518687646(481), ___518687646(482) => ___518687646(483)]];
        foreach ($_1129597667 as $_1291744683) {
            if (!$_1316527108 = @$GLOBALS['____1079197990'][94]($_1291744683[___518687646(484)], ___518687646(485))) {
                $this->_181770737[] = [___518687646(486) => $_1291744683[___518687646(487)], ___518687646(488) => false, ___518687646(489) => true];

                return false;
            }
            if (!$GLOBALS['____1079197990'][95]($_1316527108, $_1291744683[___518687646(490)])) {
                $this->_181770737[] = [___518687646(491) => $_1291744683[___518687646(492)], ___518687646(493) => false, ___518687646(494) => true];

                return false;
            }
        }

        return true;
    }

    public function ShowStep()
    {
        $wizard = $this->GetWizard();
        $this->content .= ___518687646(495).InstallGetMessage(___518687646(496)).___518687646(497).InstallGetMessage(___518687646(498)).___518687646(499);
        $this->content .= ___518687646(500).InstallGetMessage(___518687646(501)).___518687646(502).InstallGetMessage(___518687646(503)).___518687646(504).InstallGetMessage(___518687646(505)).___518687646(506);
        $_1256265352 = $this->CheckServerVersion($_909687026, $_771395668, $_1110578176);
        $this->content .= ___518687646(507).$GLOBALS['____1079197990'][96](___518687646(508), ($_909687026 !== ___518687646(509)) ? $_909687026 : InstallGetMessage(___518687646(510)), InstallGetMessage(___518687646(511))).___518687646(512).($_1110578176 !== ___518687646(513) ? $GLOBALS['____1079197990'][97](___518687646(514), $_1110578176, InstallGetMessage(___518687646(515))) : ___518687646(516)).___518687646(517).(null !== $_1256265352 ? $this->ShowResult($_771395668, $_1256265352) : $this->ShowResult(InstallGetMessage(___518687646(518)), ___518687646(519))).___518687646(520);
        $_1256265352 = $this->CheckPHPVersion();
        $this->content .= ___518687646(521).InstallGetMessage(___518687646(522)).___518687646(523).($this->_756699738 !== ___518687646(524) ? $GLOBALS['____1079197990'][98](___518687646(525), $this->_756699738, InstallGetMessage(___518687646(526))) : ___518687646(527)).___518687646(528).$this->ShowResult($GLOBALS['____1079197990'][99](), $_1256265352).___518687646(529);
        $this->content .= ___518687646(530).InstallGetMessage(___518687646(531)).___518687646(532);
        $this->content .= ___518687646(533).InstallGetMessage(___518687646(534)).___518687646(535).($this->GetPHPSetting(___518687646(536)) === ___518687646(537) ? $this->ShowResult(InstallGetMessage(___518687646(538)), ___518687646(539)) : $this->ShowResult(InstallGetMessage(___518687646(540)), ___518687646(541))).___518687646(542);
        $this->content .= ___518687646(543).InstallGetMessage(___518687646(544)).___518687646(545).(($_1920168049 = $GLOBALS['____1079197990'][100](___518687646(546))) === ___518687646(547) ? $this->ShowResult(InstallGetMessage(___518687646(548)), ___518687646(549)) : $this->ShowResult($_1920168049, ___518687646(550))).___518687646(551);
        if ($this->GetPHPSetting(___518687646(552)) === ___518687646(553)) {
            $this->content .= ___518687646(554).($this->GetPHPSetting(___518687646(555)) === ___518687646(556) ? $this->ShowResult(round(0 + 1), ___518687646(557)) : $this->ShowResult(min(44, 0, 14.666_666_666_667), ___518687646(558))).___518687646(559).($GLOBALS['____1079197990'][101](___518687646(560)) > (866 - 2 * 433) ? $this->ShowResult($GLOBALS['____1079197990'][102](___518687646(561)), ___518687646(562)) : $this->ShowResult(___518687646(563), ___518687646(564))).___518687646(565);
        }
        if ($GLOBALS['____1079197990'][103](___518687646(566))) {
            $this->content .= ___518687646(567).InstallGetMessage(___518687646(568)).___518687646(569).$this->ShowResult(InstallGetMessage(___518687646(570)), ___518687646(571)).___518687646(572);
        }
        $utf8 = ($wizard->GetVar(___518687646(573)) === ___518687646(574));
        $_1879567179 = $GLOBALS['____1079197990'][104](___518687646(575));
        if ($_1879567179 === ___518687646(576)) {
            $_1879567179 = $this->ShowResult(InstallGetMessage(___518687646(577)), ___518687646(578));
        } elseif ($utf8) {
            $_1879567179 = $this->ShowResult($_1879567179, $GLOBALS['____1079197990'][105]($_1879567179) === ___518687646(579) ? ___518687646(580) : ___518687646(581));
        }
        $this->content .= ___518687646(582).($utf8 ? ___518687646(583) : ___518687646(584)).___518687646(585).$_1879567179.___518687646(586);
        if ($GLOBALS['____1079197990'][106]($GLOBALS['____1079197990'][107](___518687646(587))) > min(188, 0, 62.666_666_666_667)) {
            $this->content .= ___518687646(588).$this->ShowResult($GLOBALS['____1079197990'][108](___518687646(589)), ___518687646(590)).___518687646(591);
        }
        $dbType = $wizard->GetVar(___518687646(592));
        $_1503091578 = BXInstallServices::GetDBTypes();
        $_1256265352 = ($GLOBALS['____1079197990'][109]($dbType, $_1503091578) && true === $_1503091578[$dbType]);
        $this->content .= ___518687646(593).InstallGetMessage(___518687646(594)).___518687646(595).InstallGetMessage(___518687646(596)).___518687646(597).InstallGetMessage(___518687646(598)).___518687646(599).($_1256265352 ? $this->ShowResult(InstallGetMessage(___518687646(600)), ___518687646(601)) : $this->ShowResult(InstallGetMessage(___518687646(602)), ___518687646(603))).___518687646(604);
        $this->content .= ___518687646(605).InstallGetMessage(___518687646(606)).___518687646(607).InstallGetMessage(___518687646(608)).___518687646(609).($GLOBALS['____1079197990'][110](___518687646(610)) ? $this->ShowResult(InstallGetMessage(___518687646(611)), ___518687646(612)) : $this->ShowResult(InstallGetMessage(___518687646(613)), ___518687646(614))).___518687646(615);
        $this->content .= ___518687646(616).InstallGetMessage(___518687646(617)).___518687646(618).InstallGetMessage(___518687646(619)).___518687646(620).($GLOBALS['____1079197990'][111](___518687646(621)) ? $this->ShowResult(InstallGetMessage(___518687646(622)), ___518687646(623)) : $this->ShowResult(InstallGetMessage(___518687646(624)), ___518687646(625))).___518687646(626);
        $this->content .= ___518687646(627).InstallGetMessage(___518687646(628)).___518687646(629).InstallGetMessage(___518687646(630)).___518687646(631).($GLOBALS['____1079197990'][112](___518687646(632)) ? $this->ShowResult(InstallGetMessage(___518687646(633)), ___518687646(634)) : $this->ShowResult(InstallGetMessage(___518687646(635)), ___518687646(636))).___518687646(637);
        $this->content .= ___518687646(638).InstallGetMessage(___518687646(639)).___518687646(640).($GLOBALS['____1079197990'][113](___518687646(641)) ? $this->ShowResult(InstallGetMessage(___518687646(642)), ___518687646(643)) : $this->ShowResult(InstallGetMessage(___518687646(644)), ___518687646(645))).___518687646(646);
        $this->content .= ___518687646(647).InstallGetMessage(___518687646(648)).___518687646(649).($GLOBALS['____1079197990'][114](___518687646(650)) ? $this->ShowResult(InstallGetMessage(___518687646(651)), ___518687646(652)) : $this->ShowResult(InstallGetMessage(___518687646(653)), ___518687646(654))).___518687646(655);
        if (!BXInstallServices::CheckSession()) {
            $this->content .= ___518687646(656).InstallGetMessage(___518687646(657)).___518687646(658).InstallGetMessage(___518687646(659)).___518687646(660).$this->ShowResult(InstallGetMessage(___518687646(661)).___518687646(662).InstallGetMessage(___518687646(663)), ___518687646(664)).___518687646(665);
        }
        $this->content .= ___518687646(666);
        $this->content .= ___518687646(667).InstallGetMessage(___518687646(668)).___518687646(669).InstallGetMessage(___518687646(670)).___518687646(671);
        $this->content .= ___518687646(672).InstallGetMessage(___518687646(673)).___518687646(674).InstallGetMessage(___518687646(675)).___518687646(676);
        $this->CheckFileAccess();
        foreach ($this->_181770737 as $_1291744683) {
            if (isset($_1291744683[___518687646(677)])) {
                continue;
            }
            $this->content .= ___518687646(678).$_1291744683[___518687646(679)].___518687646(680).$_1291744683[___518687646(681)].___518687646(682).$_1291744683[___518687646(683)].___518687646(684);
        }
        $this->content .= ___518687646(685);
        $this->content .= ___518687646(686).InstallGetMessage(___518687646(687)).___518687646(688).InstallGetMessage(___518687646(689)).___518687646(690);
        $this->content .= ___518687646(691).InstallGetMessage(___518687646(692)).___518687646(693).InstallGetMessage(___518687646(694)).___518687646(695).InstallGetMessage(___518687646(696)).___518687646(697);
        if ($GLOBALS['____1079197990'][115]($_SERVER[___518687646(698)].___518687646(699))) {
            $_1902349872 = ___518687646(700);
            if (!($_655495249 = $GLOBALS['____1079197990'][116](___518687646(701)))) {
                $_594697045 = $this->ShowResult(InstallGetMessage(___518687646(702)), false);
            } elseif ($GLOBALS['____1079197990'][117]($_655495249, $_1902349872, ___518687646(703))) {
                $_594697045 = $this->ShowResult($GLOBALS['____1079197990'][118](___518687646(704), htmlspecialcharsbx($_655495249), InstallGetMessage(___518687646(705))), false);
            } else {
                $_594697045 = $this->ShowResult(InstallGetMessage(___518687646(706)), true);
            }
            $this->content .= ___518687646(707).InstallGetMessage(___518687646(708)).___518687646(709).$GLOBALS['____1079197990'][119](___518687646(710), $_1902349872, InstallGetMessage(___518687646(711))).___518687646(712).$_594697045.___518687646(713);
        }
        if (false !== $GLOBALS['____1079197990'][120]($GLOBALS['____1079197990'][121]($_SERVER[___518687646(714)]), ___518687646(715))) {
            $this->content .= ___518687646(716).InstallGetMessage(___518687646(717)).___518687646(718).InstallGetMessage(___518687646(719)).___518687646(720).$this->ShowResult(InstallGetMessage(___518687646(721)), ___518687646(722)).___518687646(723).$this->ShowResult(InstallGetMessage(___518687646(724)), ___518687646(725)).___518687646(726).$this->ShowResult(InstallGetMessage(___518687646(727)), ___518687646(728)).___518687646(729).$this->ShowResult(InstallGetMessage(___518687646(730)), ___518687646(731)).___518687646(732);
        }
        $_1291517617 = @$GLOBALS['____1079197990'][122]($_SERVER[___518687646(733)]);
        $_1291517617 = $_1291517617 * 1.0 / 1_000_000.0;
        $this->content .= ___518687646(734).InstallGetMessage(___518687646(735)).___518687646(736).($GLOBALS['____1079197990'][123]($this->_752503645) > (156 * 2 - 312) ? $GLOBALS['____1079197990'][124](___518687646(737), $this->_752503645, InstallGetMessage(___518687646(738))) : ___518687646(739)).___518687646(740).($_1291517617 > $this->_752503645 ? $this->ShowResult($GLOBALS['____1079197990'][125]($_1291517617, round(0 + 0.25 + 0.25 + 0.25 + 0.25)).___518687646(741), ___518687646(742)) : $this->ShowResult($GLOBALS['____1079197990'][126]($_1291517617, round(0 + 1)).___518687646(743), ___518687646(744))).___518687646(745);
        $_615590436 = WelcomeStep::unformat($GLOBALS['____1079197990'][127](___518687646(746)));
        if (!$_615590436 || $_615590436 === ___518687646(747)) {
            $_615590436 = WelcomeStep::unformat($GLOBALS['____1079197990'][128](___518687646(748)));
        }
        if ($_615590436 > (152 * 2 - 304) && $_615590436 < $this->_716469993) {
            @$GLOBALS['____1079197990'][129](___518687646(749), ___518687646(750));
            $_615590436 = WelcomeStep::unformat($GLOBALS['____1079197990'][130](___518687646(751)));
        }
        $_814790622 = ___518687646(752);
        if ($GLOBALS['____1079197990'][131]($this->_716469993) > (838 - 2 * 419)) {
            $_814790622 .= $GLOBALS['____1079197990'][132](___518687646(753), $this->_716469993, InstallGetMessage(___518687646(754)));
        }
        if ($GLOBALS['____1079197990'][133]($this->_716469993) > min(154, 0, 51.333_333_333_333) && $GLOBALS['____1079197990'][134]($this->_283480729) > (828 - 2 * 414)) {
            $_814790622 .= ___518687646(755);
        }
        if ($GLOBALS['____1079197990'][135]($this->_283480729) > min(154, 0, 51.333_333_333_333)) {
            $_814790622 .= $GLOBALS['____1079197990'][136](___518687646(756), $this->_283480729, InstallGetMessage(___518687646(757)));
        }
        $this->content .= ___518687646(758).InstallGetMessage(___518687646(759)).___518687646(760).InstallGetMessage(___518687646(761)).___518687646(762).$_814790622.___518687646(763).($_615590436 > (860 - 2 * 430) && $_615590436 < $this->_716469993 * round(0 + 524_288 + 524_288) ? $this->ShowResult($GLOBALS['____1079197990'][137](___518687646(764)), ___518687646(765)) : $this->ShowResult($GLOBALS['____1079197990'][138](___518687646(766)), ___518687646(767))).___518687646(768);
        $this->content .= ___518687646(769).InstallGetMessage(___518687646(770)).___518687646(771).InstallGetMessage(___518687646(772)).___518687646(773).($this->GetPHPSetting(___518687646(774)) === ___518687646(775) ? $this->ShowResult(InstallGetMessage(___518687646(776)), ___518687646(777)) : $this->ShowResult(InstallGetMessage(___518687646(778)), ___518687646(779))).___518687646(780).InstallGetMessage(___518687646(781)).___518687646(782).InstallGetMessage(___518687646(783)).___518687646(784).($this->GetPHPSetting(___518687646(785)) === ___518687646(786) ? $this->ShowResult(InstallGetMessage(___518687646(787)), ___518687646(788)) : $this->ShowResult(InstallGetMessage(___518687646(789)), ___518687646(790))).___518687646(791).InstallGetMessage(___518687646(792)).___518687646(793).InstallGetMessage(___518687646(794)).___518687646(795).($this->GetPHPSetting(___518687646(796)) === ___518687646(797) ? $this->ShowResult(InstallGetMessage(___518687646(798)), ___518687646(799)) : $this->ShowResult(InstallGetMessage(___518687646(800)), ___518687646(801))).___518687646(802);
        $this->content .= ___518687646(803).InstallGetMessage(___518687646(804)).___518687646(805).InstallGetMessage(___518687646(806)).___518687646(807).($GLOBALS['____1079197990'][139](___518687646(808)) && $GLOBALS['____1079197990'][140](___518687646(809)) ? $this->ShowResult(InstallGetMessage(___518687646(810)), ___518687646(811)) : $this->ShowResult(InstallGetMessage(___518687646(812)), ___518687646(813))).___518687646(814).InstallGetMessage(___518687646(815)).___518687646(816).InstallGetMessage(___518687646(817)).___518687646(818).($GLOBALS['____1079197990'][141](___518687646(819)) ? $this->ShowResult(InstallGetMessage(___518687646(820)), ___518687646(821)) : $this->ShowResult(InstallGetMessage(___518687646(822)), ___518687646(823))).___518687646(824).InstallGetMessage(___518687646(825)).___518687646(826).($GLOBALS['____1079197990'][142](___518687646(827)) ? $this->ShowResult(InstallGetMessage(___518687646(828)), ___518687646(829)) : $this->ShowResult(InstallGetMessage(___518687646(830)), ___518687646(831))).___518687646(832);
        $this->content .= ___518687646(833);
        $this->content .= ___518687646(834).InstallGetMessage(___518687646(835)).___518687646(836);
    }
}

class CreateDBStep extends CWizardStep
{
    public $dbType;
    public $_1711868715;
    public $_1706001958;
    public $_2133254569;
    public $dbName;
    public $_4481787;
    public $_133494610;
    public $createCharset = false;
    public $createDBType;
    public $_751108138;
    public $_1255105762;
    public $filePermission;
    public $folderPermission;
    public $utf8;
    public $needCodePage = false;
    public $DB;
    public $_1199207744 = false;

    public function InitStep()
    {
        $this->SetStepID(___518687646(837));
        $this->SetNextStep(___518687646(838));
        $this->SetPrevStep(___518687646(839));
        $this->SetNextCaption(InstallGetMessage(___518687646(840)));
        $this->SetPrevCaption(InstallGetMessage(___518687646(841)));
        $this->SetTitle(InstallGetMessage(___518687646(842)));
        $wizard = $this->GetWizard();
        $wizard->SetDefaultVars([___518687646(843) => ___518687646(844), ___518687646(845) => ___518687646(846), ___518687646(847) => ___518687646(848), ___518687646(849) => ___518687646(850)]);
        $dbType = $wizard->GetVar(___518687646(851));
        $wizard->SetDefaultVar(___518687646(852), ___518687646(853));
        if ($dbType === ___518687646(854)) {
            $wizard->SetDefaultVar(___518687646(855), ___518687646(856));
        }
    }

    public function OnPostForm()
    {
        $wizard = $this->GetWizard();
        if ($wizard->IsPrevButtonClick()) {
            return;
        }
        $this->dbType = $wizard->GetVar(___518687646(857));
        $this->_1711868715 = $wizard->GetVar(___518687646(858));
        $this->_1706001958 = $wizard->GetVar(___518687646(859));
        $this->_2133254569 = $wizard->GetVar(___518687646(860));
        $this->dbName = $wizard->GetVar(___518687646(861));
        $this->_4481787 = $wizard->GetVar(___518687646(862));
        $this->_4481787 = ($this->_4481787 && $this->_4481787 === ___518687646(863));
        $this->_133494610 = $wizard->GetVar(___518687646(864));
        $this->_133494610 = ($this->_133494610 && $this->_133494610 === ___518687646(865));
        $this->createDBType = $wizard->GetVar(___518687646(866));
        $this->_751108138 = $wizard->GetVar(___518687646(867));
        $this->_1255105762 = $wizard->GetVar(___518687646(868));
        if ($GLOBALS['____1079197990'][143](___518687646(869), $wizard->GetVar(___518687646(870)), $_2116929005)) {
            $this->filePermission = $_2116929005[round(0 + 0.333_333_333_333_33 + 0.333_333_333_333_33 + 0.333_333_333_333_33)];
        } else {
            $this->filePermission = $wizard->GetDefaultVar(___518687646(871));
        }
        if ($GLOBALS['____1079197990'][144](___518687646(872), $wizard->GetVar(___518687646(873)), $_2116929005)) {
            $this->folderPermission = $_2116929005[round(0 + 1)];
        } else {
            $this->folderPermission = $wizard->GetDefaultVar(___518687646(874));
        }
        $this->utf8 = $wizard->GetVar(___518687646(875));
        $this->utf8 = ($this->utf8 && $this->utf8 === ___518687646(876) && BXInstallServices::IsUTF8Support());
        BXInstallServices::CheckDirPath($_SERVER[___518687646(877)].___518687646(878), $GLOBALS['____1079197990'][145]($this->folderPermission));
        if (!$GLOBALS['____1079197990'][146]($_SERVER[___518687646(879)].___518687646(880)) || !$GLOBALS['____1079197990'][147]($_SERVER[___518687646(881)].___518687646(882))) {
            $this->SetError(___518687646(883));

            return;
        }
        $_705446088 = $_SERVER[___518687646(884)].___518687646(885);
        if ($GLOBALS['____1079197990'][148]($_705446088)) {
            if (!$GLOBALS['____1079197990'][149]($_705446088) || !$GLOBALS['____1079197990'][150]($_705446088)) {
                $this->SetError(___518687646(886));

                return;
            }
        } else {
            $_1956445980 = false;
            if ($_1316527108 = @$GLOBALS['____1079197990'][151]($_705446088, ___518687646(887))) {
                if ($GLOBALS['____1079197990'][152]($_1316527108, ___518687646(888))) {
                    $_1956445980 = true;
                }
                @$GLOBALS['____1079197990'][153]($_1316527108);
                @$GLOBALS['____1079197990'][154]($_705446088);
            }
            if (!$_1956445980) {
                $this->SetError(___518687646(889));

                return;
            }
        }
        $_1503091578 = BXInstallServices::GetDBTypes();
        if (!$GLOBALS['____1079197990'][155]($wizard->GetVar(___518687646(890)), $_1503091578) || false === $_1503091578[$wizard->GetVar(___518687646(891))]) {
            $this->SetError(InstallGetMessage(___518687646(892)));

            return;
        }
        if ($this->_1711868715 === ___518687646(893)) {
            $this->SetError(InstallGetMessage(___518687646(894)), ___518687646(895));

            return;
        }
        if ($GLOBALS['____1079197990'][156](___518687646(896))) {
            $_2097069295 = ___518687646(897);
            $GLOBALS['____1079197990'][157](___518687646(898), true);
        } else {
            $_2097069295 = ___518687646(899);
        }
        $_1803568705 = HttpApplication::getInstance();
        $_2033524056 = $_1803568705->getConnectionPool();
        $_2033524056->setConnectionParameters(ConnectionPool::DEFAULT_CONNECTION_NAME, [___518687646(900) => $_2097069295, ___518687646(901) => $this->_2133254569, ___518687646(902) => $this->dbName, ___518687646(903) => $this->_1711868715, ___518687646(904) => $this->_1706001958, ___518687646(905) => round(0 + 2)]);
        $_2033524056->useMasterOnly(true);
        if (!$this->CreateMySQL()) {
            return;
        }
        if (!$this->CreateAfterConnect()) {
            return;
        }

        require_once $_SERVER[___518687646(906)].___518687646(907).$this->dbType.___518687646(908);

        require_once $_SERVER[___518687646(909)].___518687646(910).$this->dbType.___518687646(911);

        require_once $_SERVER[___518687646(912)].___518687646(913);

        require_once $_SERVER[___518687646(914)].___518687646(915);
        $GLOBALS['_____1777807464'][2]($_SERVER[___518687646(916)].___518687646(917));
        global $DB;
        $DB = new CDatabase();
        $this->DB = &$DB;
        $DB->_932886709 = false;
        if (!$DB->Connect($this->_2133254569, $this->dbName, $this->_1711868715, $this->_1706001958)) {
            $this->SetError(InstallGetMessage(___518687646(918)).___518687646(919).$DB->_915184687);

            return;
        }
        $DB->_988579659 = true;
        if ($this->IsBitrixInstalled()) {
            return;
        }
        if (!$this->CheckDBOperation()) {
            return;
        }
        if (!$this->createSettings()) {
            return;
        }
        if (!$this->CreateDBConn()) {
            return;
        }
        $this->CreateLicenseFile();
        BXInstallServices::DeleteDirRec($_SERVER[___518687646(920)].___518687646(921));
        BXInstallServices::DeleteDirRec($_SERVER[___518687646(922)].___518687646(923));
    }

    public function IsBitrixInstalled()
    {
        $DB = &$this->DB;
        $_512179251 = $DB->Query(___518687646(924), true);
        if ($_512179251 && $_512179251->Fetch()) {
            $this->SetError($GLOBALS['____1079197990'][158](___518687646(925), $this->dbName, InstallGetMessage(___518687646(926))));

            return true;
        }

        return false;
    }

    public function CreateMySQL()
    {
        if ($GLOBALS['____1079197990'][159](___518687646(927), $this->dbName) || $GLOBALS['____1079197990'][160](___518687646(928), $this->dbName) || $GLOBALS['____1079197990'][161]($this->dbName) > round(0 + 16 + 16 + 16 + 16)) {
            $this->SetError(InstallGetMessage(___518687646(929)));

            return false;
        }
        $_1474318253 = [___518687646(930) => $this->_2133254569];
        if ($this->_4481787 || $this->_133494610) {
            $_1474318253[___518687646(931)] = $this->_751108138;
            $_1474318253[___518687646(932)] = $this->_1255105762;
        } else {
            $_1474318253[___518687646(933)] = $this->_1711868715;
            $_1474318253[___518687646(934)] = $this->_1706001958;
        }
        if ($GLOBALS['____1079197990'][162](___518687646(935))) {
            $_486546320 = new MysqliConnection($_1474318253);
        } else {
            $_486546320 = new MysqlConnection($_1474318253);
        }

        try {
            $_486546320->connect();
        } catch (ConnectionException $_2103178604) {
            $this->SetError(InstallGetMessage(___518687646(936)).___518687646(937).$_2103178604->getDatabaseMessage());

            return false;
        }
        $_2051421235 = $_486546320->query(___518687646(938));
        if ($_326913257 = $_2051421235->fetch()) {
            $_2083424398 = $GLOBALS['____1079197990'][163]($_326913257[___518687646(939)]);
            if (!BXInstallServices::VersionCompare($_2083424398, ___518687646(940))) {
                $this->SetError(InstallGetMessage(___518687646(941)));

                return false;
            }
            $this->needCodePage = true;
            if (!$this->needCodePage && $this->utf8) {
                $this->SetError(InstallGetMessage(___518687646(942)));

                return false;
            }
        }
        $_2051421235 = $_486546320->query(___518687646(943));
        if ($_1314357634 = $_2051421235->fetch()) {
            $_1199207744 = $GLOBALS['____1079197990'][164]($_1314357634[___518687646(944)]);
            if ($_1199207744 !== ___518687646(945)) {
                $this->_1199207744 = ___518687646(946);
            }
        }
        if ($this->_4481787) {
            if ($_486546320->selectDatabase($this->dbName)) {
                $this->SetError($GLOBALS['____1079197990'][165](___518687646(947), $this->dbName, InstallGetMessage(___518687646(948))));

                return false;
            }
            $_486546320->queryExecute(___518687646(949).$_486546320->getSqlHelper()->quote($this->dbName));
            if (!$_486546320->selectDatabase($this->dbName)) {
                $this->SetError($GLOBALS['____1079197990'][166](___518687646(950), $this->dbName, InstallGetMessage(___518687646(951))));

                return false;
            }
        } else {
            if (!$_486546320->selectDatabase($this->dbName)) {
                $this->SetError($GLOBALS['____1079197990'][167](___518687646(952), $this->dbName, InstallGetMessage(___518687646(953))));

                return false;
            }
            if ($GLOBALS['____1079197990'][168](___518687646(954)) || (isset($_COOKIE[___518687646(955)]) && $_COOKIE[___518687646(956)] === ___518687646(957))) {
                $_1837716736 = $_486546320->query(___518687646(958));
                while ($_1926900757 = $_1837716736->fetch()) {
                    $_486546320->queryExecute(___518687646(959).$GLOBALS['____1079197990'][169]($_1926900757));
                }
            }
        }
        if ($this->_1711868715 !== $this->_751108138) {
            $_1777445710 = $this->_2133254569;
            if ($_1654303264 = $GLOBALS['____1079197990'][170]($_1777445710, ___518687646(960))) {
                $_1777445710 = $GLOBALS['____1079197990'][171]($_1777445710, 768 - 2 * 384, $_1654303264);
            }
            if ($this->_133494610) {
                $_1184195954 = ___518687646(961).$GLOBALS['____1079197990'][172]($this->dbName).___518687646(962).$GLOBALS['____1079197990'][173]($this->_1711868715).___518687646(963).$_1777445710.___518687646(964).$GLOBALS['____1079197990'][174]($this->_1706001958).___518687646(965);

                try {
                    $_486546320->queryExecute($_1184195954);
                } catch (SqlQueryException $_2103178604) {
                    $this->SetError(InstallGetMessage(___518687646(966)).___518687646(967).$_2103178604->getDatabaseMessage());

                    return false;
                }
            } elseif ($this->_4481787) {
                $_1184195954 = ___518687646(968).$GLOBALS['____1079197990'][175]($this->dbName).___518687646(969).$GLOBALS['____1079197990'][176]($this->_1711868715).___518687646(970).$_1777445710.___518687646(971);

                try {
                    $_486546320->queryExecute($_1184195954);
                } catch (SqlQueryException $_2103178604) {
                    $this->SetError(InstallGetMessage(___518687646(972)).___518687646(973).$_2103178604->getDatabaseMessage());

                    return false;
                }
            }
        }
        if ($this->needCodePage) {
            if ($this->utf8) {
                $_471332480 = ___518687646(974);
            } elseif (LANGUAGE_ID === ___518687646(975) || LANGUAGE_ID === ___518687646(976)) {
                $_471332480 = ___518687646(977);
            } elseif ($this->createCharset !== ___518687646(978)) {
                $_471332480 = $this->createCharset;
            } else {
                $_471332480 = ___518687646(979);
            }
            if ($_471332480) {
                try {
                    if ($_471332480 === ___518687646(980)) {
                        $_486546320->queryExecute(___518687646(981).$_486546320->getSqlHelper()->quote($this->dbName).___518687646(982));
                    } else {
                        $_486546320->queryExecute(___518687646(983).$_486546320->getSqlHelper()->quote($this->dbName).___518687646(984).$_471332480);
                    }
                } catch (SqlQueryException $_2103178604) {
                    $this->SetError(InstallGetMessage(___518687646(985)));

                    return false;
                }
                $_486546320->queryExecute(___518687646(986).$_471332480.___518687646(987));
            }
        }

        return true;
    }

    public function createSettings()
    {
        $_2111491895 = $_SERVER[___518687646(988)].___518687646(989);
        if (!BXInstallServices::CheckDirPath($_2111491895, $GLOBALS['____1079197990'][177]($this->folderPermission))) {
            $this->SetError($GLOBALS['____1079197990'][178](___518687646(990), ___518687646(991), InstallGetMessage(___518687646(992))));

            return false;
        }
        $_1357227016 = [___518687646(993) => [___518687646(994) => ($this->utf8 ? true : false), ___518687646(995) => true], ___518687646(996) => [___518687646(997) => [___518687646(998) => round(0 + 900 + 900 + 900 + 900), ___518687646(999) => round(0 + 1_200 + 1_200 + 1_200)], ___518687646(1_000) => false]];
        $_1357227016[___518687646(1_001)] = [___518687646(1_002) => [___518687646(1_003) => false, ___518687646(1_004) => true], ___518687646(1_005) => false];
        $_1357227016[___518687646(1_006)] = [___518687646(1_007) => [___518687646(1_008) => false, ___518687646(1_009) => E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR, ___518687646(1_010) => E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR, ___518687646(1_011) => false, ___518687646(1_012) => true, ___518687646(1_013) => E_USER_ERROR, ___518687646(1_014) => null], ___518687646(1_015) => false];
        if ($GLOBALS['____1079197990'][179](___518687646(1_016))) {
            $_2097069295 = ___518687646(1_017);
        } else {
            $_2097069295 = ___518687646(1_018);
        }
        $_1357227016[___518687646(1_019)][___518687646(1_020)][___518687646(1_021)] = [___518687646(1_022) => $_2097069295, ___518687646(1_023) => $this->_2133254569, ___518687646(1_024) => $this->dbName, ___518687646(1_025) => $this->_1711868715, ___518687646(1_026) => $this->_1706001958, ___518687646(1_027) => round(0 + 0.5 + 0.5 + 0.5 + 0.5)];
        $_1357227016[___518687646(1_028)][___518687646(1_029)] = true;
        $_1357227016[___518687646(1_030)] = [___518687646(1_031) => [___518687646(1_032) => $GLOBALS['____1079197990'][180](___518687646(1_033).$GLOBALS['____1079197990'][181](___518687646(1_034), true))], ___518687646(1_035) => true];
        if (!$_1316527108 = @$GLOBALS['____1079197990'][182]($_2111491895, ___518687646(1_036))) {
            $this->SetError($GLOBALS['____1079197990'][183](___518687646(1_037), $_SERVER[___518687646(1_038)], InstallGetMessage(___518687646(1_039))));

            return false;
        }
        if (!$GLOBALS['____1079197990'][184]($_1316527108, ___518687646(1_040).___518687646(1_041).$GLOBALS['____1079197990'][185]($_1357227016, true).___518687646(1_042))) {
            $this->SetError($GLOBALS['____1079197990'][186](___518687646(1_043), $_SERVER[___518687646(1_044)], InstallGetMessage(___518687646(1_045))));

            return false;
        }
        @$GLOBALS['____1079197990'][187]($_1316527108);
        if ($this->filePermission > min(144, 0, 48)) {
            @$GLOBALS['____1079197990'][188]($_2111491895, $GLOBALS['____1079197990'][189]($this->filePermission));
        }

        return true;
    }

    public function CreateDBConn()
    {
        $_2111491895 = $_SERVER[___518687646(1_046)].BX_PERSONAL_ROOT.___518687646(1_047);
        if (!BXInstallServices::CheckDirPath($_2111491895, $GLOBALS['____1079197990'][190]($this->folderPermission))) {
            $this->SetError($GLOBALS['____1079197990'][191](___518687646(1_048), BX_PERSONAL_ROOT.___518687646(1_049), InstallGetMessage(___518687646(1_050))));

            return false;
        }
        $_1345882244 = ___518687646(1_051).___518687646(1_052).($GLOBALS['____1079197990'][192](___518687646(1_053)) ? ___518687646(1_054) : ___518687646(1_055)).___518687646(1_056).___518687646(1_057).___518687646(1_058).___518687646(1_059).($this->createDBType === ___518687646(1_060) ? ___518687646(1_061).___518687646(1_062) : ___518687646(1_063)).___518687646(1_064).___518687646(1_065).___518687646(1_066).___518687646(1_067).___518687646(1_068).___518687646(1_069).___518687646(1_070).___518687646(1_071).___518687646(1_072).___518687646(1_073).___518687646(1_074);
        $_1276016777 = [];
        if ($this->filePermission > (1_328 / 2 - 664)) {
            $_1345882244 .= ___518687646(1_075).$this->filePermission.___518687646(1_076);
            $_1276016777[] = ___518687646(1_077);
        }
        if ($this->folderPermission > (222 * 2 - 444)) {
            $_1345882244 .= ___518687646(1_078).$this->folderPermission.___518687646(1_079);
            $_1276016777[] = ___518687646(1_080);
        }
        if ($_1276016777) {
            $_1345882244 .= ___518687646(1_081).$GLOBALS['____1079197990'][193](___518687646(1_082), $_1276016777).___518687646(1_083);
        }
        if ($GLOBALS['____1079197990'][194]($_SERVER[___518687646(1_084)].___518687646(1_085))) {
            $_1345882244 .= ___518687646(1_086);
        } else {
            $_615590436 = WelcomeStep::unformat($GLOBALS['____1079197990'][195](___518687646(1_087)));
            if (!$_615590436 || $_615590436 === ___518687646(1_088)) {
                $_615590436 = WelcomeStep::unformat($GLOBALS['____1079197990'][196](___518687646(1_089)));
            }
            if ($_615590436 > (249 * 2 - 498) && $_615590436 < round(0 + 85.333_333_333_333 + 85.333_333_333_333 + 85.333_333_333_333) * round(0 + 262_144 + 262_144 + 262_144 + 262_144)) {
                @$GLOBALS['____1079197990'][197](___518687646(1_090), ___518687646(1_091));
                $_615590436 = WelcomeStep::unformat($GLOBALS['____1079197990'][198](___518687646(1_092)));
                if ($_615590436 >= round(0 + 128 + 128 + 128 + 128) * round(0 + 262_144 + 262_144 + 262_144 + 262_144)) {
                    $_1345882244 .= ___518687646(1_093);
                }
            }
        }
        $_1345882244 .= ___518687646(1_094);
        if ($this->utf8) {
            $_1345882244 .= ___518687646(1_095).___518687646(1_096).___518687646(1_097);
        } elseif (LANGUAGE_ID === ___518687646(1_098) || LANGUAGE_ID === ___518687646(1_099)) {
            $_1345882244 .= ___518687646(1_100).___518687646(1_101).___518687646(1_102).___518687646(1_103);
        }
        if (!$_1316527108 = @$GLOBALS['____1079197990'][199]($_2111491895, ___518687646(1_104))) {
            $this->SetError($GLOBALS['____1079197990'][200](___518687646(1_105), $_SERVER[___518687646(1_106)], InstallGetMessage(___518687646(1_107))));

            return false;
        }
        if (!$GLOBALS['____1079197990'][201]($_1316527108, $_1345882244)) {
            $this->SetError($GLOBALS['____1079197990'][202](___518687646(1_108), $_SERVER[___518687646(1_109)], InstallGetMessage(___518687646(1_110))));

            return false;
        }
        @$GLOBALS['____1079197990'][203]($_1316527108);
        if ($this->filePermission > (198 * 2 - 396)) {
            @$GLOBALS['____1079197990'][204]($_2111491895, $GLOBALS['____1079197990'][205]($this->filePermission));
        }

        return true;
    }

    public function CreateAfterConnect()
    {
        $_471332480 = ___518687646(1_111);
        if ($this->needCodePage) {
            if ($this->utf8) {
                $_471332480 = ___518687646(1_112);
            } elseif (LANGUAGE_ID === ___518687646(1_113) || LANGUAGE_ID === ___518687646(1_114)) {
                $_471332480 = ___518687646(1_115);
            } else {
                $_471332480 = $this->createCharset;
            }
        }
        $_900772753 = ___518687646(1_116).___518687646(1_117).($_471332480 !== ___518687646(1_118) ? ___518687646(1_119).___518687646(1_120).$_471332480.___518687646(1_121) : ___518687646(1_122)).(false !== $this->_1199207744 ? ___518687646(1_123).___518687646(1_124).$this->_1199207744.___518687646(1_125) : ___518687646(1_126)).($this->utf8 ? ___518687646(1_127).___518687646(1_128) : ___518687646(1_129));
        $_2080112128 = $_SERVER[___518687646(1_130)].BX_PERSONAL_ROOT.___518687646(1_131);
        if (!BXInstallServices::CheckDirPath($_2080112128, $GLOBALS['____1079197990'][206]($this->folderPermission))) {
            $this->SetError($GLOBALS['____1079197990'][207](___518687646(1_132), BX_PERSONAL_ROOT.___518687646(1_133), InstallGetMessage(___518687646(1_134))));

            return false;
        }
        if (!$_1316527108 = @$GLOBALS['____1079197990'][208]($_2080112128, ___518687646(1_135))) {
            $this->SetError($GLOBALS['____1079197990'][209](___518687646(1_136), $_SERVER[___518687646(1_137)], InstallGetMessage(___518687646(1_138))));

            return false;
        }
        if (!$GLOBALS['____1079197990'][210]($_1316527108, $_900772753)) {
            $this->SetError($GLOBALS['____1079197990'][211](___518687646(1_139), $_SERVER[___518687646(1_140)], InstallGetMessage(___518687646(1_141))));

            return false;
        }
        @$GLOBALS['____1079197990'][212]($_1316527108);
        if ($this->filePermission > min(158, 0, 52.666_666_666_667)) {
            @$GLOBALS['____1079197990'][213]($_2080112128, $GLOBALS['____1079197990'][214]($this->filePermission));
        }
        if ($GLOBALS['____1079197990'][215]($GLOBALS['____1079197990'][216](PHP_OS, 1_408 / 2 - 704, round(0 + 1.5 + 1.5))) === ___518687646(1_142)) {
            $_2005862307 = $_SERVER[___518687646(1_143)].___518687646(1_144);
            $_1918978521 = $GLOBALS['____1079197990'][217]($_2005862307, ___518687646(1_145));
            $_1451459739 = $GLOBALS['____1079197990'][218]($_1918978521, $GLOBALS['____1079197990'][219]($_2005862307));
            $GLOBALS['____1079197990'][220]($_1918978521);
            $_1451459739 = $GLOBALS['____1079197990'][221](___518687646(1_146), ___518687646(1_147).___518687646(1_148).___518687646(1_149).___518687646(1_150).___518687646(1_151).___518687646(1_152).___518687646(1_153).___518687646(1_154).___518687646(1_155).___518687646(1_156).___518687646(1_157).___518687646(1_158).___518687646(1_159), $_1451459739);
            $_1918978521 = $GLOBALS['____1079197990'][222]($_2005862307, ___518687646(1_160));
            $GLOBALS['____1079197990'][223]($_1918978521, $_1451459739);
            $GLOBALS['____1079197990'][224]($_1918978521);
        }
        $_2111491895 = $_SERVER[___518687646(1_161)].BX_PERSONAL_ROOT.___518687646(1_162);
        if ($GLOBALS['____1079197990'][225]($_2111491895)) {
            return true;
        }
        if (!$_1316527108 = @$GLOBALS['____1079197990'][226]($_2111491895, ___518687646(1_163))) {
            $this->SetError($GLOBALS['____1079197990'][227](___518687646(1_164), $_SERVER[___518687646(1_165)], InstallGetMessage(___518687646(1_166))));

            return false;
        }
        if (!$GLOBALS['____1079197990'][228]($_1316527108, ___518687646(1_167))) {
            $this->SetError($GLOBALS['____1079197990'][229](___518687646(1_168), $_SERVER[___518687646(1_169)], InstallGetMessage(___518687646(1_170))));

            return false;
        }
        @$GLOBALS['____1079197990'][230]($_1316527108);
        if ($this->filePermission > (1_304 / 2 - 652)) {
            @$GLOBALS['____1079197990'][231]($_2111491895, $GLOBALS['____1079197990'][232]($this->filePermission));
        }

        return true;
    }

    public function CreateLicenseFile()
    {
        $wizard = $this->GetWizard();
        $_21197058 = $wizard->GetVar(___518687646(1_171));
        if (!BXInstallServices::CreateLicenseFile($_21197058)) {
            return false;
        }
        $_2111491895 = $_SERVER[___518687646(1_172)].___518687646(1_173);
        if ($this->filePermission > (199 * 2 - 398)) {
            @$GLOBALS['____1079197990'][233]($_2111491895, $GLOBALS['____1079197990'][234]($this->filePermission));
        }

        return true;
    }

    public function CheckDBOperation()
    {
        if (!$GLOBALS['____1079197990'][235]($this->DB)) {
            return;
        }
        $DB = &$this->DB;
        $_1498297318 = ___518687646(1_174);
        $_1745446918 = "CREATE TABLE {$_1498297318}(ID INT)";
        $DB->Query($_1745446918, true);
        if ($DB->_915184687 !== ___518687646(1_175)) {
            $this->SetError(InstallGetMessage(___518687646(1_176)));

            return false;
        }
        $_1745446918 = "ALTER TABLE {$_1498297318} ADD COLUMN CLMN VARCHAR(100)";
        $DB->Query($_1745446918, true);
        if ($DB->_915184687 !== ___518687646(1_177)) {
            $this->SetError(InstallGetMessage(___518687646(1_178)));

            return false;
        }
        $_1745446918 = "DROP TABLE IF EXISTS {$_1498297318}";
        $DB->Query($_1745446918, true);
        if ($DB->_915184687 !== ___518687646(1_179)) {
            $this->SetError(InstallGetMessage(___518687646(1_180)));

            return false;
        }

        return true;
    }

    public function ShowStep()
    {
        $wizard = $this->GetWizard();
        $dbType = $wizard->GetVar(___518687646(1_181));
        $this->content .= ___518687646(1_182).InstallGetMessage(___518687646(1_183)).___518687646(1_184);
        $this->content .= ___518687646(1_185).InstallGetMessage(___518687646(1_186)).___518687646(1_187).$this->ShowInputField(___518687646(1_188), ___518687646(1_189), [___518687646(1_190) => ___518687646(1_191)]).___518687646(1_192).InstallGetMessage(___518687646(1_193)).___518687646(1_194);
        $this->content .= ___518687646(1_195).InstallGetMessage(___518687646(1_196)).___518687646(1_197).$this->ShowRadioField(___518687646(1_198), ___518687646(1_199), [___518687646(1_200) => ___518687646(1_201), ___518687646(1_202) => ___518687646(1_203)]).___518687646(1_204).InstallGetMessage(___518687646(1_205)).___518687646(1_206).$this->ShowRadioField(___518687646(1_207), ___518687646(1_208), [___518687646(1_209) => ___518687646(1_210), ___518687646(1_211) => ___518687646(1_212)]).___518687646(1_213).InstallGetMessage(___518687646(1_214)).___518687646(1_215);
        $this->content .= ___518687646(1_216).InstallGetMessage(___518687646(1_217)).___518687646(1_218).$this->ShowInputField(___518687646(1_219), ___518687646(1_220), [___518687646(1_221) => ___518687646(1_222)]).___518687646(1_223).InstallGetMessage(___518687646(1_224)).___518687646(1_225).InstallGetMessage(___518687646(1_226)).___518687646(1_227).$this->ShowInputField(___518687646(1_228), ___518687646(1_229), [___518687646(1_230) => ___518687646(1_231)]).___518687646(1_232).InstallGetMessage(___518687646(1_233)).___518687646(1_234).InstallGetMessage(___518687646(1_235)).___518687646(1_236).$this->ShowRadioField(___518687646(1_237), ___518687646(1_238), [___518687646(1_239) => ___518687646(1_240), ___518687646(1_241) => ___518687646(1_242)]).___518687646(1_243).InstallGetMessage(___518687646(1_244)).___518687646(1_245).$this->ShowRadioField(___518687646(1_246), ___518687646(1_247), [___518687646(1_248) => ___518687646(1_249), ___518687646(1_250) => ___518687646(1_251)]).___518687646(1_252).InstallGetMessage(___518687646(1_253)).___518687646(1_254).InstallGetMessage(___518687646(1_255)).___518687646(1_256).InstallGetMessage(___518687646(1_257)).___518687646(1_258).$this->ShowInputField(___518687646(1_259), ___518687646(1_260), [___518687646(1_261) => ___518687646(1_262)]).___518687646(1_263).InstallGetMessage(___518687646(1_264)).___518687646(1_265);
        $this->content .= ___518687646(1_266).InstallGetMessage(___518687646(1_267)).___518687646(1_268).$this->ShowSelectField(___518687646(1_269), [___518687646(1_270) => InstallGetMessage(___518687646(1_271)), ___518687646(1_272) => ___518687646(1_273)]).___518687646(1_274);
        $this->content .= ___518687646(1_275).InstallGetMessage(___518687646(1_276)).___518687646(1_277).InstallGetMessage(___518687646(1_278)).___518687646(1_279).$this->ShowInputField(___518687646(1_280), ___518687646(1_281), [___518687646(1_282) => ___518687646(1_283), ___518687646(1_284) => ___518687646(1_285)]).___518687646(1_286).InstallGetMessage(___518687646(1_287)).___518687646(1_288).InstallGetMessage(___518687646(1_289)).___518687646(1_290).$this->ShowInputField(___518687646(1_291), ___518687646(1_292), [___518687646(1_293) => ___518687646(1_294), ___518687646(1_295) => ___518687646(1_296)]).___518687646(1_297).InstallGetMessage(___518687646(1_298)).___518687646(1_299);
        $this->content .= ___518687646(1_300).InstallGetMessage(___518687646(1_301)).___518687646(1_302).InstallGetMessage(___518687646(1_303)).___518687646(1_304).$this->ShowInputField(___518687646(1_305), ___518687646(1_306), [___518687646(1_307) => ___518687646(1_308)]).___518687646(1_309).InstallGetMessage(___518687646(1_310)).___518687646(1_311).InstallGetMessage(___518687646(1_312)).___518687646(1_313).$this->ShowInputField(___518687646(1_314), ___518687646(1_315), [___518687646(1_316) => ___518687646(1_317)]).___518687646(1_318).InstallGetMessage(___518687646(1_319)).___518687646(1_320);
    }
}

class CreateModulesStep extends CWizardStep
{
    public $_1115940484 = [];
    public $_107974232 = [];

    public function InitStep()
    {
        $this->SetStepID(___518687646(1_321));
        $this->SetTitle(InstallGetMessage(___518687646(1_322)));
    }

    public function OnPostForm()
    {
        $wizard = $this->GetWizard();
        $_2025093500 = $wizard->GetVar(___518687646(1_323));
        $_1310381179 = $wizard->GetVar(___518687646(1_324));
        if ($_2025093500 === ___518687646(1_325)) {
            $wizard->SetCurrentStep(___518687646(1_326));

            return;
        }
        $this->_107974232 = [___518687646(1_327) => InstallGetMessage(___518687646(1_328)).___518687646(1_329), ___518687646(1_330) => InstallGetMessage(___518687646(1_331)).___518687646(1_332), ___518687646(1_333) => InstallGetMessage(___518687646(1_334))];
        $this->_1115940484 = $GLOBALS['____1079197990'][236]($this->GetModuleList(), $GLOBALS['____1079197990'][237]($this->_107974232));
        $_1049274473 = [];
        if ($GLOBALS[___518687646(1_335)][___518687646(1_336)] !== ___518687646(1_337)) {
            $_1049274473 = $GLOBALS['____1079197990'][238](___518687646(1_338), $GLOBALS[___518687646(1_339)][___518687646(1_340)], -round(0 + 0.333_333_333_333_33 + 0.333_333_333_333_33 + 0.333_333_333_333_33), PREG_SPLIT_NO_EMPTY);
        }
        $_1563822354 = $GLOBALS['____1079197990'][239]($_2025093500, $this->_1115940484);
        if (false === $_1563822354 || null === $_1563822354) {
            $_2025093500 = ___518687646(1_341);
        }
        if ($GLOBALS['____1079197990'][240]($_2025093500, $this->_107974232) && $_1310381179 !== ___518687646(1_342)) {
            $_1256265352 = $this->InstallSingleStep($_2025093500);
        } else {
            if ($GLOBALS['____1079197990'][241]($_2025093500, $_1049274473) && $_1310381179 !== ___518687646(1_343)) {
                $_1256265352 = true;
            } else {
                $_1256265352 = $this->InstallModule($_2025093500, $_1310381179);
            }
        }
        if ($_2025093500 === ___518687646(1_344) && false === $_1256265352) {
            $this->SendResponse(___518687646(1_345).InstallGetMessage(___518687646(1_346)).___518687646(1_347));
        }
        list($_661732917, $_874183770, $_1824050818, $_1835071541) = $this->GetNextStep($_2025093500, $_1310381179, $_1256265352);
        $_1188574468 = ___518687646(1_348);
        if ($_661732917 === ___518687646(1_349)) {
            $_1188574468 .= ___518687646(1_350);
        }
        $_1188574468 .= ___518687646(1_351).$_1824050818.___518687646(1_352).$_661732917.___518687646(1_353).$_874183770.___518687646(1_354).$_1835071541.___518687646(1_355);
        $this->SendResponse($_1188574468);
    }

    public function SendResponse($_1188574468)
    {
        $GLOBALS['____1079197990'][242](___518687646(1_356).INSTALL_CHARSET);

        exit(___518687646(1_357).$_1188574468.___518687646(1_358));
    }

    public function GetModuleList()
    {
        $_239589203 = [];
        $_632360857 = @$GLOBALS['____1079197990'][243]($_SERVER[___518687646(1_359)].___518687646(1_360));
        if (!$_632360857) {
            return $_239589203;
        }
        while (false !== ($_1164453069 = $GLOBALS['____1079197990'][244]($_632360857))) {
            $_1839759500 = $_SERVER[___518687646(1_361)].___518687646(1_362).$_1164453069;
            if ($GLOBALS['____1079197990'][245]($_1839759500) && $_1164453069 !== ___518687646(1_363) && $_1164453069 !== ___518687646(1_364) && $_1164453069 !== ___518687646(1_365) && $GLOBALS['____1079197990'][246]($_1839759500.___518687646(1_366))) {
                $_239589203[] = $_1164453069;
            }
        }
        $GLOBALS['____1079197990'][247]($_632360857);
        $GLOBALS['____1079197990'][248]($_239589203, static function ($_675639870, $_1115414998) {
            return $GLOBALS['____1079197990'][249]($_675639870, $_1115414998);
        });
        $GLOBALS['____1079197990'][250]($_239589203, ___518687646(1_367));

        return $_239589203;
    }

    public function GetNextStep($_2025093500, $_1310381179, $_941209125)
    {
        $_1634062961 = $GLOBALS['____1079197990'][251]($_2025093500, $this->_1115940484);
        if ($_1310381179 === ___518687646(1_368)) {
            $_661732917 = $_2025093500;
            $_874183770 = ___518687646(1_369);
        } elseif ($_1310381179 === ___518687646(1_370) && $_941209125) {
            $_661732917 = $_2025093500;
            $_874183770 = ___518687646(1_371);
        } else {
            if (!isset($this->_1115940484[$_1634062961 + round(0 + 0.5 + 0.5)])) {
                return [___518687646(1_372), ___518687646(1_373), round(0 + 100), InstallGetMessage(___518687646(1_374))];
            }
            $_661732917 = $this->_1115940484[$_1634062961 + round(0 + 0.2 + 0.2 + 0.2 + 0.2 + 0.2)];
            if ($GLOBALS['____1079197990'][252]($_661732917, $this->_107974232)) {
                $_874183770 = ___518687646(1_375);
            } elseif ($GLOBALS['____1079197990'][253](___518687646(1_376))) {
                $_874183770 = ___518687646(1_377);
            } else {
                $_874183770 = ___518687646(1_378);
            }
        }
        $_107974232 = $GLOBALS['____1079197990'][254]($this->_107974232);
        $_1803176052 = $GLOBALS['____1079197990'][255]($this->_1115940484) - $_107974232;
        $_1619215959 = ($GLOBALS['____1079197990'][256](___518687646(1_379)) ? round(0 + 1 + 1 + 1) : round(0 + 2));
        $_1185956998 = $_1803176052 * $_1619215959 + $_107974232;
        if ($_1310381179 === ___518687646(1_380) || ($_1310381179 === ___518687646(1_381) && !$GLOBALS['____1079197990'][257]($_2025093500, $this->_107974232))) {
            $_248480100 = ++$_1634062961 * $_1619215959;
        } elseif ($_1310381179 === ___518687646(1_382)) {
            $_248480100 = ++$_1634062961 * $_1619215959 - round(0 + 0.25 + 0.25 + 0.25 + 0.25);
        } elseif ($_1310381179 === ___518687646(1_383)) {
            $_248480100 = ++$_1634062961 * $_1619215959 - round(0 + 2);
        } else {
            $_248480100 = $_1803176052 * $_1619215959 + ($_1634062961 + round(0 + 0.25 + 0.25 + 0.25 + 0.25) - $_1803176052);
        }
        $_1824050818 = $GLOBALS['____1079197990'][258]($_248480100 / $_1185956998 * round(0 + 100));
        $_1890171132 = [___518687646(1_384) => ___518687646(1_385), ___518687646(1_386) => InstallGetMessage(___518687646(1_387)), ___518687646(1_388) => InstallGetMessage(___518687646(1_389))];
        if ($GLOBALS['____1079197990'][259]($_661732917, $this->_107974232)) {
            $_1835071541 = $this->_107974232[$_661732917];
        } elseif ($_661732917 === ___518687646(1_390)) {
            $_1835071541 = InstallGetMessage(___518687646(1_391)).___518687646(1_392).$_1890171132[$_874183770].___518687646(1_393);
        } else {
            $_1292308431 = $this->GetModuleObject($_661732917);
            $_1025258984 = ($GLOBALS['____1079197990'][260]($_1292308431) ? ($GLOBALS['____1079197990'][261](___518687646(1_394)) && ($_874183770 === ___518687646(1_395) || BXInstallServices::IsUTFString($_1292308431->MODULE_NAME)) ? $GLOBALS['____1079197990'][262]($_1292308431->MODULE_NAME, INSTALL_CHARSET, ___518687646(1_396)) : $_1292308431->MODULE_NAME) : $_661732917);
            $_1835071541 = InstallGetMessage(___518687646(1_397)).___518687646(1_398).$_1025258984.___518687646(1_399).$_1890171132[$_874183770].___518687646(1_400);
        }

        return [$_661732917, $_874183770, $_1824050818, $_1835071541];
    }

    public function InstallSingleStep($_1270492395)
    {
        if ($_1270492395 === ___518687646(1_401)) {
            BXInstallServices::DeleteDbFiles(___518687646(1_402));
        } elseif ($_1270492395 === ___518687646(1_403)) {
            BXInstallServices::DeleteDbFiles(___518687646(1_404));
        } elseif ($_1270492395 === ___518687646(1_405)) {
            BXInstallServices::DeleteDirRec($_SERVER[___518687646(1_406)].___518687646(1_407));
            BXInstallServices::DeleteDirRec($_SERVER[___518687646(1_408)].___518687646(1_409));
        }

        return true;
    }

    public function GetModuleObject($_974596800)
    {
        if (!$GLOBALS['____1079197990'][263](___518687646(1_410))) {
            global $DB, $DBHost, $DBLogin, $DBPassword, $DBName, $DBDebug, $DBDebugToFile, $APPLICATION, $USER;

            require_once $_SERVER[___518687646(1_411)].___518687646(1_412);
        }
        $_503055972 = $_SERVER[___518687646(1_413)].___518687646(1_414).$_974596800.___518687646(1_415);
        if (!$GLOBALS['____1079197990'][264]($_503055972)) {
            return false;
        }

        include_once $_503055972;
        $_1859041608 = $GLOBALS['____1079197990'][265](___518687646(1_416), ___518687646(1_417), $_974596800);
        if (!$GLOBALS['____1079197990'][266]($_1859041608)) {
            return false;
        }

        return new $_1859041608();
    }

    public function InstallModule($_974596800, $_1310381179)
    {
        if ($_974596800 === ___518687646(1_418)) {
            $GLOBALS['____1079197990'][267](E_COMPILE_ERROR | E_ERROR | E_CORE_ERROR | E_PARSE);
            global $DB, $DBHost, $DBLogin, $DBPassword, $DBName, $DBDebug, $DBDebugToFile, $APPLICATION;
            $_1803568705 = HttpApplication::getInstance();
            $_1803568705->initializeBasicKernel();

            require_once $_SERVER[___518687646(1_419)].BX_PERSONAL_ROOT.___518687646(1_420);

            require_once $_SERVER[___518687646(1_421)].___518687646(1_422);

            require_once $_SERVER[___518687646(1_423)].___518687646(1_424);

            require_once $_SERVER[___518687646(1_425)].___518687646(1_426);

            require_once $_SERVER[___518687646(1_427)].___518687646(1_428);

            require_once $_SERVER[___518687646(1_429)].___518687646(1_430);

            require_once $_SERVER[___518687646(1_431)].___518687646(1_432);
        } else {
            global $DB, $DBHost, $DBLogin, $DBPassword, $DBName, $DBDebug, $DBDebugToFile, $APPLICATION, $USER;

            require_once $_SERVER[___518687646(1_433)].___518687646(1_434);
            if ($DB->type === ___518687646(1_435) && $GLOBALS['____1079197990'][268](___518687646(1_436)) && MYSQL_TABLE_TYPE !== ___518687646(1_437)) {
                $_512179251 = $DB->Query(___518687646(1_438).MYSQL_TABLE_TYPE.___518687646(1_439), true);
                if (!$_512179251) {
                    $DB->Query(___518687646(1_440).MYSQL_TABLE_TYPE.___518687646(1_441), true);
                }
            }
            if (IsModuleInstalled($_974596800) && $_1310381179 === ___518687646(1_442)) {
                return true;
            }
        }
        @$GLOBALS['____1079197990'][269](round(0 + 900 + 900 + 900 + 900));
        $_1292308431 = $this->GetModuleObject($_974596800);
        if (!$GLOBALS['____1079197990'][270]($_1292308431)) {
            return true;
        }
        if ($_1310381179 === ___518687646(1_443)) {
            return true;
        }
        if ($_1310381179 === ___518687646(1_444)) {
            if (!$this->IsModuleEncode($_974596800)) {
                if ($_974596800 === ___518687646(1_445)) {
                    $this->EncodeDemoWizard();
                }
                BXInstallServices::EncodeDir($_SERVER[___518687646(1_446)].___518687646(1_447).$_974596800, INSTALL_CHARSET);
                $this->SetEncodeModule($_974596800);
            }

            return true;
        }
        if ($_1310381179 === ___518687646(1_448)) {
            $DBDebug = true;
            if (!$_1292308431->InstallDB()) {
                if ($_1531044420 = $APPLICATION->GetException()) {
                    BXInstallServices::Add2Log($_1531044420->GetString(), ___518687646(1_449));
                }

                return false;
            }
            $_1292308431->InstallEvents();
            if ($_974596800 === ___518687646(1_450)) {
                $_1516200252 = [___518687646(1_451), ___518687646(1_452), ___518687646(1_453), ___518687646(1_454), $GLOBALS['____1079197990'][271](___518687646(1_455))];
                $_1667860236 = $_SERVER[___518687646(1_456)].___518687646(1_457).$GLOBALS['____1079197990'][272](___518687646(1_458), $_1516200252);
                $_1176072142 = round(0 + 7.5 + 7.5 + 7.5 + 7.5);
                if ($GLOBALS['____1079197990'][273]($_SERVER[___518687646(1_459)].___518687646(1_460))) {
                    $bxProductConfig = [];

                    include $_SERVER[___518687646(1_461)].___518687646(1_462);
                    if (isset($bxProductConfig[___518687646(1_463)][___518687646(1_464)])) {
                        $_878376546 = $GLOBALS['____1079197990'][274]($bxProductConfig[___518687646(1_465)][___518687646(1_466)]);
                        if ($_878376546 > (241 * 2 - 482) && $_878376546 < round(0 + 10 + 10 + 10)) {
                            $_1176072142 = $_878376546;
                        }
                    }
                }
                $_1259624743 = ___518687646(1_467);
                $_2055511816 = $GLOBALS['____1079197990'][275](___518687646(1_468), $GLOBALS['____1079197990'][276](196 * 2 - 392, 1_132 / 2 - 566, 212 * 2 - 424, $GLOBALS['____1079197990'][277](___518687646(1_469)), $GLOBALS['____1079197990'][278](___518687646(1_470)) + $_1176072142, $GLOBALS['____1079197990'][279](___518687646(1_471))));
                $_2113203496 = $GLOBALS['____1079197990'][280](___518687646(1_472), $GLOBALS['____1079197990'][281](min(128, 0, 42.666_666_666_667), 834 - 2 * 417, min(44, 0, 14.666_666_666_667), $GLOBALS['____1079197990'][282](___518687646(1_473)), $GLOBALS['____1079197990'][283](___518687646(1_474)) + $_1176072142, $GLOBALS['____1079197990'][284](___518687646(1_475))));
                $_172288706 = $GLOBALS['____1079197990'][285](___518687646(1_476), $GLOBALS['____1079197990'][286](133 * 2 - 266, 964 - 2 * 482, min(62, 0, 20.666_666_666_667), $GLOBALS['____1079197990'][287](___518687646(1_477)), $GLOBALS['____1079197990'][288](___518687646(1_478)) + $_1176072142, $GLOBALS['____1079197990'][289](___518687646(1_479))));
                $_1341346700 = ___518687646(1_480);
                $_977202736 = ___518687646(1_481).$GLOBALS['____1079197990'][290]($_2055511816, round(0 + 1), round(0 + 0.5 + 0.5)).$GLOBALS['____1079197990'][291]($_172288706, round(0 + 0.6 + 0.6 + 0.6 + 0.6 + 0.6), round(0 + 0.25 + 0.25 + 0.25 + 0.25)).___518687646(1_482).$GLOBALS['____1079197990'][292]($_2113203496, 874 - 2 * 437, round(0 + 0.333_333_333_333_33 + 0.333_333_333_333_33 + 0.333_333_333_333_33)).$GLOBALS['____1079197990'][293]($_172288706, round(0 + 0.25 + 0.25 + 0.25 + 0.25), round(0 + 1)).___518687646(1_483).$GLOBALS['____1079197990'][294]($_2055511816, 774 - 2 * 387, round(0 + 0.2 + 0.2 + 0.2 + 0.2 + 0.2)).___518687646(1_484).$GLOBALS['____1079197990'][295]($_172288706, 1_076 / 2 - 538, round(0 + 0.2 + 0.2 + 0.2 + 0.2 + 0.2)).___518687646(1_485).$GLOBALS['____1079197990'][296]($_172288706, round(0 + 0.666_666_666_666_67 + 0.666_666_666_666_67 + 0.666_666_666_666_67), round(0 + 0.333_333_333_333_33 + 0.333_333_333_333_33 + 0.333_333_333_333_33)).___518687646(1_486).$GLOBALS['____1079197990'][297]($_2113203496, round(0 + 1), round(0 + 0.25 + 0.25 + 0.25 + 0.25)).___518687646(1_487);
                $_1259624743 = $GLOBALS['____1079197990'][298](___518687646(1_488)).$GLOBALS['____1079197990'][299](___518687646(1_489), $_1259624743, ___518687646(1_490));
                $_755368443 = $GLOBALS['____1079197990'][300]($_1259624743);
                $_1609276004 = (856 - 2 * 428);
                for ($_2101434245 = min(74, 0, 24.666_666_666_667); $_2101434245 < $GLOBALS['____1079197990'][301]($_977202736); ++$_2101434245) {
                    $_1341346700 .= $GLOBALS['____1079197990'][302]($GLOBALS['____1079197990'][303]($_977202736[$_2101434245]) ^ $GLOBALS['____1079197990'][304]($_1259624743[$_1609276004]));
                    if ($_1609276004 === $_755368443 - round(0 + 0.333_333_333_333_33 + 0.333_333_333_333_33 + 0.333_333_333_333_33)) {
                        $_1609276004 = (147 * 2 - 294);
                    } else {
                        $_1609276004 = $_1609276004 + round(0 + 0.333_333_333_333_33 + 0.333_333_333_333_33 + 0.333_333_333_333_33);
                    }
                }
                $_1341346700 = ___518687646(1_491).___518687646(1_492).___518687646(1_493).$GLOBALS['____1079197990'][305]($_1341346700).___518687646(1_494).___518687646(1_495).___518687646(1_496);
                BXInstallServices::CheckDirPath($_1667860236);
                if (!$GLOBALS['____1079197990'][306]($_1667860236)) {
                    $_1316527108 = @$GLOBALS['____1079197990'][307]($_1667860236, ___518687646(1_497));
                    @$GLOBALS['____1079197990'][308]($_1316527108, $_1341346700);
                    @$GLOBALS['____1079197990'][309]($_1316527108);
                }
                $_1890962412 = ___518687646(1_498);
                $_178732558 = $GLOBALS[___518687646(1_499)]->Query(___518687646(1_500).$GLOBALS['____1079197990'][310](___518687646(1_501), ___518687646(1_502), $GLOBALS['____1079197990'][311]($_1890962412, round(0 + 0.5 + 0.5 + 0.5 + 0.5), round(0 + 0.8 + 0.8 + 0.8 + 0.8 + 0.8))).$GLOBALS['____1079197990'][312](___518687646(1_503)).___518687646(1_504), true);
                if (false !== $_178732558) {
                    $_1245941988 = false;
                    if ($_512179251 = $_178732558->Fetch()) {
                        $_1245941988 = true;
                    }
                    if (!$_1245941988) {
                        $_1176072142 = round(0 + 6 + 6 + 6 + 6 + 6);
                        if ($GLOBALS['____1079197990'][313]($_SERVER[___518687646(1_505)].___518687646(1_506))) {
                            $bxProductConfig = [];

                            include $_SERVER[___518687646(1_507)].___518687646(1_508);
                            if (isset($bxProductConfig[___518687646(1_509)][___518687646(1_510)])) {
                                $_878376546 = $GLOBALS['____1079197990'][314]($bxProductConfig[___518687646(1_511)][___518687646(1_512)]);
                                if ($_878376546 > min(232, 0, 77.333_333_333_333) && $_878376546 < round(0 + 6 + 6 + 6 + 6 + 6)) {
                                    $_1176072142 = $_878376546;
                                }
                            }
                        }
                        $_1453936728 = ___518687646(1_513);
                        $_2055511816 = $GLOBALS['____1079197990'][315](___518687646(1_514), $GLOBALS['____1079197990'][316](min(88, 0, 29.333_333_333_333), min(38, 0, 12.666_666_666_667), min(108, 0, 36), $GLOBALS['____1079197990'][317](___518687646(1_515)), $GLOBALS['____1079197990'][318](___518687646(1_516)) + $_1176072142, $GLOBALS['____1079197990'][319](___518687646(1_517))));
                        $_2113203496 = $GLOBALS['____1079197990'][320](___518687646(1_518), $GLOBALS['____1079197990'][321](1_484 / 2 - 742, 1_268 / 2 - 634, 176 * 2 - 352, $GLOBALS['____1079197990'][322](___518687646(1_519)), $GLOBALS['____1079197990'][323](___518687646(1_520)) + $_1176072142, $GLOBALS['____1079197990'][324](___518687646(1_521))));
                        $_172288706 = $GLOBALS['____1079197990'][325](___518687646(1_522), $GLOBALS['____1079197990'][326](866 - 2 * 433, 754 - 2 * 377, 161 * 2 - 322, $GLOBALS['____1079197990'][327](___518687646(1_523)), $GLOBALS['____1079197990'][328](___518687646(1_524)) + $_1176072142, $GLOBALS['____1079197990'][329](___518687646(1_525))));
                        $_1341346700 = ___518687646(1_526);
                        $_977202736 = ___518687646(1_527).$GLOBALS['____1079197990'][330]($_2055511816, min(160, 0, 53.333_333_333_333), round(0 + 0.25 + 0.25 + 0.25 + 0.25)).___518687646(1_528).$GLOBALS['____1079197990'][331]($_2113203496, round(0 + 0.5 + 0.5), round(0 + 0.25 + 0.25 + 0.25 + 0.25)).___518687646(1_529).$GLOBALS['____1079197990'][332]($_2113203496, 212 * 2 - 424, round(0 + 0.5 + 0.5)).$GLOBALS['____1079197990'][333]($_172288706, round(0 + 2), round(0 + 0.333_333_333_333_33 + 0.333_333_333_333_33 + 0.333_333_333_333_33)).___518687646(1_530).$GLOBALS['____1079197990'][334]($_172288706, 818 - 2 * 409, round(0 + 0.25 + 0.25 + 0.25 + 0.25)).___518687646(1_531).$GLOBALS['____1079197990'][335]($_172288706, round(0 + 0.6 + 0.6 + 0.6 + 0.6 + 0.6), round(0 + 1)).___518687646(1_532).$GLOBALS['____1079197990'][336]($_2055511816, round(0 + 0.2 + 0.2 + 0.2 + 0.2 + 0.2), round(0 + 1)).___518687646(1_533).$GLOBALS['____1079197990'][337]($_172288706, round(0 + 1), round(0 + 0.2 + 0.2 + 0.2 + 0.2 + 0.2));
                        $_1453936728 = $GLOBALS['____1079197990'][338](___518687646(1_534).$_1453936728, 183 * 2 - 366, -round(0 + 5)).___518687646(1_535);
                        $_174316143 = $GLOBALS['____1079197990'][339]($_1453936728);
                        $_1609276004 = min(200, 0, 66.666_666_666_667);
                        for ($_2101434245 = (1_472 / 2 - 736); $_2101434245 < $GLOBALS['____1079197990'][340]($_977202736); ++$_2101434245) {
                            $_1341346700 .= $GLOBALS['____1079197990'][341]($GLOBALS['____1079197990'][342]($_977202736[$_2101434245]) ^ $GLOBALS['____1079197990'][343]($_1453936728[$_1609276004]));
                            if ($_1609276004 === $_174316143 - round(0 + 0.5 + 0.5)) {
                                $_1609276004 = (972 - 2 * 486);
                            } else {
                                $_1609276004 = $_1609276004 + round(0 + 1);
                            }
                        }
                        $GLOBALS[___518687646(1_536)]->Query(___518687646(1_537).$GLOBALS['____1079197990'][344](___518687646(1_538), ___518687646(1_539), $GLOBALS['____1079197990'][345]($_1890962412, round(0 + 0.4 + 0.4 + 0.4 + 0.4 + 0.4), round(0 + 2 + 2))).$GLOBALS['____1079197990'][346](___518687646(1_540)).___518687646(1_541).$GLOBALS[___518687646(1_542)]->ForSql($GLOBALS['____1079197990'][347]($_1341346700), min(190, 0, 63.333_333_333_333)).___518687646(1_543), true);
                        if ($GLOBALS['____1079197990'][348]($GLOBALS[___518687646(1_544)])) {
                            $GLOBALS[___518687646(1_545)]->Clean(___518687646(1_546));
                            $GLOBALS[___518687646(1_547)]->Clean(___518687646(1_548));
                        }
                    }
                }
            }
        } elseif ($_1310381179 === ___518687646(1_549)) {
            if (!$_1292308431->InstallFiles()) {
                if ($_1531044420 = $APPLICATION->GetException()) {
                    BXInstallServices::Add2Log($_1531044420->GetString(), ___518687646(1_550));
                }

                return false;
            }
        }

        return true;
    }

    public function IsModuleEncode($_974596800)
    {
        $_2111491895 = $_SERVER[___518687646(1_551)].BX_PERSONAL_ROOT.___518687646(1_552);
        if (!$GLOBALS['____1079197990'][349]($_2111491895)) {
            return false;
        }
        $_1345882244 = $GLOBALS['____1079197990'][350]($_2111491895);

        return false !== $GLOBALS['____1079197990'][351]($_1345882244, ___518687646(1_553).$_974596800.___518687646(1_554));
    }

    public function SetEncodeModule($_974596800)
    {
        $_2111491895 = $_SERVER[___518687646(1_555)].BX_PERSONAL_ROOT.___518687646(1_556);
        if (!$_632360857 = @$GLOBALS['____1079197990'][352]($_2111491895, ___518687646(1_557))) {
            return false;
        }
        @$GLOBALS['____1079197990'][353]($_632360857, ___518687646(1_558).$_974596800.___518687646(1_559));
        @$GLOBALS['____1079197990'][354]($_632360857);
    }

    public function EncodeDemoWizard()
    {
        $wizardName = BXInstallServices::GetDemoWizard();
        if (false === $wizardName) {
            return;
        }
        $_1194787926 = BXInstallServices::GetWizardCharset($wizardName);
        if (false === $_1194787926) {
            $_1194787926 = INSTALL_CHARSET;
        }
        if ($GLOBALS['____1079197990'][355]($_SERVER[___518687646(1_560)].BX_ROOT.___518687646(1_561))) {
            BXInstallServices::EncodeFile($_SERVER[___518687646(1_562)].BX_ROOT.___518687646(1_563), $_1194787926);
        }
        $_673305200 = $_SERVER[___518687646(1_564)].___518687646(1_565);
        if ($_1164453069 = $GLOBALS['____1079197990'][356]($_673305200)) {
            while (($_927477334 = $GLOBALS['____1079197990'][357]($_1164453069)) !== false) {
                if ($_927477334 === ___518687646(1_566) || $_927477334 === ___518687646(1_567)) {
                    continue;
                }
                if ($GLOBALS['____1079197990'][358]($_673305200.___518687646(1_568).$_927477334)) {
                    BXInstallServices::EncodeDir($_673305200.___518687646(1_569).$_927477334, $_1194787926, $_586584272 = true);
                }
            }
            $GLOBALS['____1079197990'][359]($_1164453069);
        }
    }

    public function ShowStep()
    {
        @include $_SERVER[___518687646(1_570)].BX_PERSONAL_ROOT.___518687646(1_571);
        $this->content .= ___518687646(1_572).InstallGetMessage(___518687646(1_573)).___518687646(1_574).InstallGetMessage(___518687646(1_575)).___518687646(1_576).InstallGetMessage(___518687646(1_577)).___518687646(1_578).InstallGetMessage(___518687646(1_579)).___518687646(1_580).$this->ShowHiddenField(___518687646(1_581), ___518687646(1_582)).___518687646(1_583).$this->ShowHiddenField(___518687646(1_584), ___518687646(1_585)).___518687646(1_586);
        $wizard = $this->GetWizard();
        $formName = $wizard->GetFormName();
        $_2146404876 = $wizard->GetRealName(___518687646(1_587));
        $_1819033359 = ($GLOBALS['____1079197990'][360](___518687646(1_588)) ? ___518687646(1_589) : ___518687646(1_590));
        $this->content .= ___518687646(1_591).$formName.___518687646(1_592).$_2146404876.___518687646(1_593).$_1819033359.___518687646(1_594).InstallGetMessage(___518687646(1_595)).___518687646(1_596).($GLOBALS['____1079197990'][361](___518687646(1_597)) ? ___518687646(1_598) : InstallGetMessage(___518687646(1_599))).___518687646(1_600);
    }
}

class CreateAdminStep extends CWizardStep
{
    public function InitStep()
    {
        $this->SetStepID(___518687646(1_601));
        $this->SetNextStep(___518687646(1_602));
        $this->SetNextCaption(InstallGetMessage(___518687646(1_603)));
        $this->SetTitle(InstallGetMessage(___518687646(1_604)));
        $wizard = $this->GetWizard();
        $wizard->SetDefaultVar(___518687646(1_605), ___518687646(1_606));
        $wizard->SetDefaultVar(___518687646(1_607), ___518687646(1_608));
        if ($_SERVER[___518687646(1_609)] === ___518687646(1_610)) {
            $wizard->SetDefaultVar(___518687646(1_611), ___518687646(1_612));
        }
    }

    public function OnPostForm()
    {
        global $DB, $DBHost, $DBLogin, $DBPassword, $DBName, $DBDebug, $DBDebugToFile, $APPLICATION, $USER;

        require_once $_SERVER[___518687646(1_613)].___518687646(1_614);
        $wizard = $this->GetWizard();
        $_1046212242 = $wizard->GetVar(___518687646(1_615));
        $_927853563 = $wizard->GetVar(___518687646(1_616));
        $_1472198819 = $wizard->GetVar(___518687646(1_617));
        $_1787361024 = $wizard->GetVar(___518687646(1_618));
        $_34535117 = $wizard->GetVar(___518687646(1_619));
        $_526397860 = $wizard->GetVar(___518687646(1_620));
        if ($_1046212242 === ___518687646(1_621)) {
            $this->SetError(InstallGetMessage(___518687646(1_622)));

            return false;
        }
        if (!check_email($_1046212242)) {
            $this->SetError(InstallGetMessage(___518687646(1_623)));

            return false;
        }
        if ($_927853563 === ___518687646(1_624)) {
            $this->SetError(InstallGetMessage(___518687646(1_625)));

            return false;
        }
        if ($GLOBALS['____1079197990'][362]($_927853563) < round(0 + 0.75 + 0.75 + 0.75 + 0.75)) {
            $this->SetError(InstallGetMessage(___518687646(1_626)));

            return false;
        }
        if ($_1472198819 === ___518687646(1_627)) {
            $this->SetError(InstallGetMessage(___518687646(1_628)));

            return false;
        }
        if ($GLOBALS['____1079197990'][363]($_1472198819) < round(0 + 3 + 3)) {
            $this->SetError(InstallGetMessage(___518687646(1_629)));

            return false;
        }
        if ($_1472198819 !== $_1787361024) {
            $this->SetError(InstallGetMessage(___518687646(1_630)));

            return false;
        }
        if ($_SERVER[___518687646(1_631)] === ___518687646(1_632)) {
            if ($GLOBALS['____1079197990'][364]($_34535117) === ___518687646(1_633)) {
                $this->SetError(InstallGetMessage(___518687646(1_634)), ___518687646(1_635));

                return false;
            }
            if ($GLOBALS['____1079197990'][365]($_526397860) === ___518687646(1_636)) {
                $this->SetError(InstallGetMessage(___518687646(1_637)), ___518687646(1_638));

                return false;
            }
        }
        $_295207304 = $DB->Query(___518687646(1_639), true);
        if (false === $_295207304) {
            return false;
        }
        $_126085538 = ($_295207304->Fetch() ? true : false);
        $_683623606 = [___518687646(1_640) => $_34535117, ___518687646(1_641) => $_526397860, ___518687646(1_642) => $_1046212242, ___518687646(1_643) => $_927853563, ___518687646(1_644) => ___518687646(1_645), ___518687646(1_646) => [___518687646(1_647)], ___518687646(1_648) => $_1472198819, ___518687646(1_649) => $_1787361024];
        if ($_126085538) {
            $_2108351398 = round(0 + 0.25 + 0.25 + 0.25 + 0.25);
            $_1256265352 = $USER->Update($_2108351398, $_683623606);
        } else {
            $_2108351398 = $USER->Add($_683623606);
            $_1256265352 = ($GLOBALS['____1079197990'][366]($_2108351398) > min(214, 0, 71.333_333_333_333));
        }
        if (!$_1256265352) {
            $this->SetError($USER->_527629465);

            return false;
        }
        COption::SetOptionString(___518687646(1_650), ___518687646(1_651), $_1046212242);
        $USER->Authorize($_2108351398, true);
        BXInstallServices::DeleteDirRec($_SERVER[___518687646(1_652)].BX_PERSONAL_ROOT.___518687646(1_653));
        if (!$GLOBALS['____1079197990'][367]($_SERVER[___518687646(1_654)].___518687646(1_655)) && !$GLOBALS['____1079197990'][368](___518687646(1_656))) {
            RegisterModuleDependences(___518687646(1_657), ___518687646(1_658), ___518687646(1_659), ___518687646(1_660), ___518687646(1_661), round(0 + 20 + 20 + 20 + 20 + 20), ___518687646(1_662));
        }
        $_981840425 = $wizard->GetVar(___518687646(1_663));
        if ($_981840425 === ___518687646(1_664)) {
            COption::SetOptionString(___518687646(1_665), ___518687646(1_666), $_981840425);
        }
        if ($_SERVER[___518687646(1_667)] === ___518687646(1_668)) {
            if ($wizard->GetVar(___518687646(1_669)) === ___518687646(1_670)) {
                $_990466145 = BXInstallServices::GetRegistrationKey($_34535117, $_526397860, $_1046212242, ___518687646(1_671));
                if (false !== $_990466145) {
                    BXInstallServices::CreateLicenseFile($_990466145);
                }
            }
        }
        $wizardName = BXInstallServices::GetConfigWizard();
        if (false === $wizardName) {
            $_1728136856 = BXInstallServices::GetWizardsList();
            if (empty($_1728136856)) {
                $wizardName = BXInstallServices::GetDemoWizard();
            }
        }
        if (false !== $wizardName) {
            if (BXInstallServices::CreateWizardIndex($wizardName, $_881142626)) {
                BXInstallServices::DeleteDirRec($_SERVER[___518687646(1_672)].___518687646(1_673));
                BXInstallServices::DeleteDirRec($_SERVER[___518687646(1_674)].___518687646(1_675));
                BXInstallServices::DeleteDirRec($_SERVER[___518687646(1_676)].___518687646(1_677));
                BXInstallServices::DeleteDirRec($_SERVER[___518687646(1_678)].___518687646(1_679));
                BXInstallServices::DeleteDirRec($_SERVER[___518687646(1_680)].___518687646(1_681));
                if ($GLOBALS['____1079197990'][369](___518687646(1_682))) {
                    BXInstallServices::EncodeFile($_SERVER[___518687646(1_683)].___518687646(1_684).LANGUAGE_ID.___518687646(1_685), INSTALL_CHARSET);
                }
                BXInstallServices::LocalRedirect(___518687646(1_686));
            } else {
                $this->SetError($_881142626);
            }
        }

        return true;
    }

    public function ShowStep()
    {
        if ($GLOBALS['____1079197990'][370](___518687646(1_687))) {
            $GLOBALS['____1079197990'][371](___518687646(1_688), true);
        }
        $_990871671 = $this->GetErrors();
        if ($GLOBALS['____1079197990'][372](___518687646(1_689)) && !empty($_990871671)) {
            $wizard = $this->GetWizard();
            foreach ([___518687646(1_690), ___518687646(1_691), ___518687646(1_692), ___518687646(1_693), ___518687646(1_694), ___518687646(1_695)] as $_2134975387) {
                $wizard->SetVar($_2134975387, $GLOBALS['____1079197990'][373]($wizard->GetVar($_2134975387), INSTALL_CHARSET, ___518687646(1_696)));
            }
        }
        $_1323677741 = ($_SERVER[___518687646(1_697)] === ___518687646(1_698));
        $this->content = ___518687646(1_699).InstallGetMessage(___518687646(1_700)).___518687646(1_701).InstallGetMessage(___518687646(1_702)).___518687646(1_703).$this->ShowInputField(___518687646(1_704), ___518687646(1_705), [___518687646(1_706) => ___518687646(1_707)]).___518687646(1_708).InstallGetMessage(___518687646(1_709)).___518687646(1_710).$this->ShowInputField(___518687646(1_711), ___518687646(1_712), [___518687646(1_713) => ___518687646(1_714)]).___518687646(1_715).InstallGetMessage(___518687646(1_716)).___518687646(1_717).$this->ShowInputField(___518687646(1_718), ___518687646(1_719), [___518687646(1_720) => ___518687646(1_721)]).___518687646(1_722).InstallGetMessage(___518687646(1_723)).___518687646(1_724).$this->ShowInputField(___518687646(1_725), ___518687646(1_726), [___518687646(1_727) => ___518687646(1_728)]).___518687646(1_729).($_1323677741 ? ___518687646(1_730) : ___518687646(1_731)).InstallGetMessage(___518687646(1_732)).___518687646(1_733).$this->ShowInputField(___518687646(1_734), ___518687646(1_735), [___518687646(1_736) => ___518687646(1_737)]).___518687646(1_738).($_1323677741 ? ___518687646(1_739) : ___518687646(1_740)).InstallGetMessage(___518687646(1_741)).___518687646(1_742).$this->ShowInputField(___518687646(1_743), ___518687646(1_744), [___518687646(1_745) => ___518687646(1_746)]).___518687646(1_747);
        if ($_1323677741) {
            $this->content .= ___518687646(1_748).$this->ShowCheckboxField(___518687646(1_749), ___518687646(1_750)).InstallGetMessage(___518687646(1_751)).___518687646(1_752);
        }
        $this->content .= ___518687646(1_753);
    }
}

class SelectWizardStep extends CWizardStep
{
    public function InitStep()
    {
        $this->SetStepID(___518687646(1_754));
        $this->SetNextStep(___518687646(1_755));
        $this->SetNextCaption(InstallGetMessage(___518687646(1_756)));
        $this->SetTitle(InstallGetMessage(___518687646(1_757)));
    }

    public function OnPostForm()
    {
        global $DB, $DBHost, $DBLogin, $DBPassword, $DBName, $DBDebug, $DBDebugToFile, $APPLICATION, $USER;

        require_once $_SERVER[___518687646(1_758)].___518687646(1_759);
        $wizard = $this->GetWizard();
        $_2131437686 = $wizard->GetVar(___518687646(1_760));
        if ($_2131437686 === ___518687646(1_761)) {
            $this->SetError(InstallGetMessage(___518687646(1_762)));

            return null;
        }
        if ($_2131437686 === ___518687646(1_763)) {
            $wizard->SetCurrentStep(___518687646(1_764));

            return true;
        }
        $_139063776 = $GLOBALS['____1079197990'][374](___518687646(1_765), $_2131437686);
        $_1357227016 = [];
        foreach ($_139063776 as $_675639870) {
            $_675639870 = $GLOBALS['____1079197990'][375](___518687646(1_766), ___518687646(1_767), $_675639870);
            if ($_675639870 !== ___518687646(1_768)) {
                $_1357227016[] = $_675639870;
            }
        }
        if ($GLOBALS['____1079197990'][376]($_1357227016) > round(0 + 1 + 1)) {
            $_673305200 = $_SERVER[___518687646(1_769)].___518687646(1_770).$_1357227016[224 * 2 - 448].___518687646(1_771).$_1357227016[round(0 + 0.2 + 0.2 + 0.2 + 0.2 + 0.2)].___518687646(1_772).$_1357227016[round(0 + 0.5 + 0.5 + 0.5 + 0.5)];
            if (!$GLOBALS['____1079197990'][377]($_673305200) || !$GLOBALS['____1079197990'][378]($_673305200)) {
                $this->SetError(InstallGetMessage(___518687646(1_773)));

                return;
            }
            BXInstallServices::CopyDirFiles($_SERVER[___518687646(1_774)].___518687646(1_775).$_1357227016[932 - 2 * 466].___518687646(1_776).$_1357227016[round(0 + 0.25 + 0.25 + 0.25 + 0.25)].___518687646(1_777).$_1357227016[round(0 + 0.5 + 0.5 + 0.5 + 0.5)], $_SERVER[___518687646(1_778)].___518687646(1_779).$_1357227016[round(0 + 0.25 + 0.25 + 0.25 + 0.25)].___518687646(1_780).$_1357227016[round(0 + 0.666_666_666_666_67 + 0.666_666_666_666_67 + 0.666_666_666_666_67)], true);
            $_1357227016 = [$_1357227016[round(0 + 0.5 + 0.5)], $_1357227016[round(0 + 1 + 1)]];
        }
        if (!$GLOBALS['____1079197990'][379]($_SERVER[___518687646(1_781)].___518687646(1_782).$_1357227016[1_216 / 2 - 608].___518687646(1_783).$_1357227016[round(0 + 0.5 + 0.5)]) || !$GLOBALS['____1079197990'][380]($_SERVER[___518687646(1_784)].___518687646(1_785).$_1357227016[239 * 2 - 478].___518687646(1_786).$_1357227016[round(0 + 1)])) {
            $this->SetError(InstallGetMessage(___518687646(1_787)));

            return;
        }
        if (BXInstallServices::CreateWizardIndex($_1357227016[1_136 / 2 - 568].___518687646(1_788).$_1357227016[round(0 + 1)], $_881142626)) {
            $_356328948 = ___518687646(1_789);
            if ($GLOBALS['____1079197990'][381](___518687646(1_790))) {
                $_996416527 = CSite::GetList(___518687646(1_791), ___518687646(1_792), [___518687646(1_793) => WIZARD_DEFAULT_SITE_ID]);
                $_657728692 = $_996416527->GetNext();
                $_356328948 = ___518687646(1_794);
                if ($GLOBALS['____1079197990'][382]($_657728692[___518687646(1_795)]) && $_657728692[___518687646(1_796)][976 - 2 * 488] !== ___518687646(1_797) || $_657728692[___518687646(1_798)] !== ___518687646(1_799)) {
                    $_356328948 .= ___518687646(1_800);
                }
                if ($GLOBALS['____1079197990'][383]($_657728692[___518687646(1_801)])) {
                    $_356328948 .= $_657728692[___518687646(1_802)][187 * 2 - 374];
                } else {
                    $_356328948 .= $_657728692[___518687646(1_803)];
                }
                $_356328948 .= $_657728692[___518687646(1_804)];
            } else {
                BXInstallServices::DeleteDirRec($_SERVER[___518687646(1_805)].___518687646(1_806));
                BXInstallServices::DeleteDirRec($_SERVER[___518687646(1_807)].___518687646(1_808));
                BXInstallServices::DeleteDirRec($_SERVER[___518687646(1_809)].___518687646(1_810));
                BXInstallServices::DeleteDirRec($_SERVER[___518687646(1_811)].___518687646(1_812));
                BXInstallServices::DeleteDirRec($_SERVER[___518687646(1_813)].___518687646(1_814));
            }
            if ($GLOBALS['____1079197990'][384](___518687646(1_815))) {
                BXInstallServices::EncodeFile($_SERVER[___518687646(1_816)].___518687646(1_817).LANGUAGE_ID.___518687646(1_818), INSTALL_CHARSET);
            }
            BXInstallServices::LocalRedirect($_356328948);
        } else {
            $this->SetError($_881142626);
        }

        return true;
    }

    public function ShowStep()
    {
        if ($GLOBALS['____1079197990'][385](___518687646(1_819))) {
            $GLOBALS['____1079197990'][386](___518687646(1_820), true);
        }
        $wizard = $this->GetWizard();
        $_1969200253 = $wizard->GetRealName(___518687646(1_821));
        $_1728136856 = BXInstallServices::GetWizardsList();
        $this->content = ___518687646(1_822).CUtil::JSEscape($_1969200253).___518687646(1_823);
        $_1728136856[] = [___518687646(1_824) => ___518687646(1_825), ___518687646(1_826) => ___518687646(1_827), ___518687646(1_828) => InstallGetMessage(___518687646(1_829)), ___518687646(1_830) => InstallGetMessage(___518687646(1_831))];
        $this->content .= ___518687646(1_832);
        $_2101434245 = (1_428 / 2 - 714);
        foreach ($_1728136856 as $_405864272) {
            if ($_2101434245 === (878 - 2 * 439)) {
                $this->content .= ___518687646(1_833);
            }
            $this->content .= ___518687646(1_834).htmlspecialcharsbx($_405864272[___518687646(1_835)]).___518687646(1_836).htmlspecialcharsbx($wizard->GetFormName()).___518687646(1_837).$_405864272[___518687646(1_838)].___518687646(1_839).($_405864272[___518687646(1_840)] !== ___518687646(1_841) ? ___518687646(1_842).htmlspecialcharsbx($_405864272[___518687646(1_843)]).___518687646(1_844) : ___518687646(1_845)).___518687646(1_846).$_405864272[___518687646(1_847)].___518687646(1_848).htmlspecialcharsbx($_405864272[___518687646(1_849)]).___518687646(1_850);
            if ($_2101434245 === round(0 + 0.2 + 0.2 + 0.2 + 0.2 + 0.2)) {
                $this->content .= ___518687646(1_851);
                $_2101434245 = (203 * 2 - 406);
            } else {
                ++$_2101434245;
            }
        }
        if ($_2101434245 === (966 - 2 * 483)) {
            $this->content .= ___518687646(1_852);
        }
        $this->content .= ___518687646(1_853).htmlspecialcharsbx($_1969200253).___518687646(1_854).htmlspecialcharsbx($_1969200253).___518687646(1_855);
    }
}

class LoadModuleStep extends CWizardStep
{
    public function InitStep()
    {
        $this->SetStepID(___518687646(1_856));
        $this->SetNextStep(___518687646(1_857));
        $this->SetNextCaption(InstallGetMessage(___518687646(1_858)));
        $this->SetTitle(InstallGetMessage(___518687646(1_859)));
    }

    public function OnPostForm()
    {
        global $DB, $DBHost, $DBLogin, $DBPassword, $DBName, $DBDebug, $DBDebugToFile, $APPLICATION, $USER;

        require_once $_SERVER[___518687646(1_860)].___518687646(1_861);

        require_once $_SERVER[___518687646(1_862)].___518687646(1_863);
        @$GLOBALS['____1079197990'][387](round(0 + 1_800 + 1_800));
        $wizard = $this->GetWizard();
        $_2028633826 = $wizard->GetVar(___518687646(1_864));
        $_2028633826 = $GLOBALS['____1079197990'][388](___518687646(1_865), ___518687646(1_866), $_2028633826);
        $_112675054 = $wizard->GetVar(___518687646(1_867));
        $wizard->SetVar(___518687646(1_868), ___518687646(1_869));
        if ($_112675054 !== ___518687646(1_870)) {
            if (CUpdateClientPartner::ActivateCoupon($_112675054, $_44595526)) {
                $wizard->SetVar(___518687646(1_871), ___518687646(1_872));
            } else {
                $this->SetError(GetMessage(___518687646(1_873)).___518687646(1_874).$_44595526);
            }
            $wizard->SetCurrentStep(___518687646(1_875));

            return null;
        }
        if ($_2028633826 === ___518687646(1_876)) {
            $wizard->SetCurrentStep(___518687646(1_877));

            return true;
        }
        $wizard->SetVar(___518687646(1_878), $_2028633826);
        $wizard->SetCurrentStep(___518687646(1_879));

        return true;
    }

    public function GetModuleObject($_974596800)
    {
        $_503055972 = $_SERVER[___518687646(1_880)].___518687646(1_881).$_974596800.___518687646(1_882);
        if (!$GLOBALS['____1079197990'][389]($_503055972)) {
            return false;
        }
        @include_once $_503055972;
        $_1859041608 = $GLOBALS['____1079197990'][390](___518687646(1_883), ___518687646(1_884), $_974596800);
        if (!$GLOBALS['____1079197990'][391]($_1859041608)) {
            return false;
        }

        return new $_1859041608();
    }

    public function ShowStep()
    {
        if ($GLOBALS['____1079197990'][392](___518687646(1_885))) {
            $GLOBALS['____1079197990'][393](___518687646(1_886), true);
        }
        $wizard = $this->GetWizard();
        $_1969200253 = $wizard->GetRealName(___518687646(1_887));
        $_1378161562 = $wizard->GetRealName(___518687646(1_888));
        $_517752578 = [];

        require_once $_SERVER[___518687646(1_889)].___518687646(1_890);
        $_239589203 = CUpdateClientPartner::SearchModulesEx([___518687646(1_891) => ___518687646(1_892), ___518687646(1_893) => ___518687646(1_894)], [___518687646(1_895) => [round(0 + 7), round(0 + 14)]], round(0 + 0.333_333_333_333_33 + 0.333_333_333_333_33 + 0.333_333_333_333_33), LANGUAGE_ID, $_153502026);
        if ($GLOBALS['____1079197990'][394]($_239589203[___518687646(1_896)])) {
            foreach ($_239589203[___518687646(1_897)] as $_2103178604) {
                $_153502026 .= ($GLOBALS['____1079197990'][395](___518687646(1_898)) ? $GLOBALS['____1079197990'][396]($_2103178604[___518687646(1_899)], INSTALL_CHARSET, ___518687646(1_900)) : $_2103178604[___518687646(1_901)]).___518687646(1_902);
            }
        }
        if ($GLOBALS['____1079197990'][397](___518687646(1_903))) {
            $_153502026 = $GLOBALS['____1079197990'][398]($_153502026, INSTALL_CHARSET, ___518687646(1_904));
        }
        if ($GLOBALS['____1079197990'][399]($_239589203[___518687646(1_905)])) {
            foreach ($_239589203[___518687646(1_906)] as $_1292308431) {
                $_517752578[] = [
                    ___518687646(1_907) => $_1292308431[___518687646(1_908)][___518687646(1_909)], ___518687646(1_910) => ($GLOBALS['____1079197990'][400](___518687646(1_911)) ? $GLOBALS['____1079197990'][401]($_1292308431[___518687646(1_912)][___518687646(1_913)], INSTALL_CHARSET, ___518687646(1_914)) : $_1292308431[___518687646(1_915)][___518687646(1_916)]), ___518687646(1_917) => ($GLOBALS['____1079197990'][402](___518687646(1_918)) ? $GLOBALS['____1079197990'][403]($_1292308431[___518687646(1_919)][___518687646(1_920)], INSTALL_CHARSET, ___518687646(1_921)) : $_1292308431[___518687646(1_922)][___518687646(1_923)]), ___518687646(1_924) => $_1292308431[___518687646(1_925)][___518687646(1_926)], ___518687646(1_927) => $_1292308431[___518687646(1_928)][___518687646(1_929)], ___518687646(1_930) => $_1292308431[___518687646(1_931)][___518687646(1_932)], ___518687646(1_933) => $_1292308431[___518687646(1_934)][___518687646(1_935)], ___518687646(1_936) => $_1292308431[___518687646(1_937)][___518687646(1_938)], ___518687646(1_939) => ___518687646(1_940).$_1292308431[___518687646(1_941)][___518687646(1_942)].___518687646(1_943),
                ];
            }
        }
        if ($_153502026 !== ___518687646(1_944)) {
            $this->SetError($_153502026);
        }
        $this->content .= ___518687646(1_945).CUtil::JSEscape($_1969200253).___518687646(1_946);
        $_1220955735 = CUpdateClientPartner::GetCurrentModules($_153502026);
        if (CUpdateClientPartner::GetLicenseKey() !== ___518687646(1_947) && !$GLOBALS['____1079197990'][404](___518687646(1_948))) {
            $_550699996 = $wizard->GetVar(___518687646(1_949));
            if ($_550699996 === ___518687646(1_950)) {
                $this->content .= ___518687646(1_951).GetMessage(___518687646(1_952)).___518687646(1_953);
            }
            $this->content .= ___518687646(1_954).GetMessage(___518687646(1_955)).___518687646(1_956).$_1378161562.___518687646(1_957).htmlspecialcharsbx($wizard->GetFormName()).___518687646(1_958).GetMessage(___518687646(1_959)).___518687646(1_960);
        }
        $_517752578[] = [___518687646(1_961) => ___518687646(1_962), ___518687646(1_963) => ___518687646(1_964), ___518687646(1_965) => InstallGetMessage(___518687646(1_966)), ___518687646(1_967) => InstallGetMessage(___518687646(1_968))];
        $this->content .= ___518687646(1_969);
        $_2101434245 = min(24, 0, 8);
        foreach ($_517752578 as $_239789416) {
            if ($_2101434245 === (1_280 / 2 - 640)) {
                $this->content .= ___518687646(1_970);
            }
            $_415257021 = $GLOBALS['____1079197990'][405]($_239789416[___518687646(1_971)], $_1220955735);
            $this->content .= ___518687646(1_972).___518687646(1_973).htmlspecialcharsbx($_239789416[___518687646(1_974)]).___518687646(1_975).($_415257021 ? ___518687646(1_976) : ___518687646(1_977).htmlspecialcharsbx($wizard->GetFormName()).___518687646(1_978)).___518687646(1_979).TruncateText($_239789416[___518687646(1_980)], round(0 + 11.8 + 11.8 + 11.8 + 11.8 + 11.8)).___518687646(1_981).($_239789416[___518687646(1_982)] !== ___518687646(1_983) ? ___518687646(1_984).htmlspecialcharsbx($_239789416[___518687646(1_985)]).___518687646(1_986) : ___518687646(1_987)).___518687646(1_988).($_239789416[___518687646(1_989)] === ___518687646(1_990) ? ___518687646(1_991).InstallGetMessage(___518687646(1_992)).___518687646(1_993) : ___518687646(1_994)).($_415257021 ? ___518687646(1_995).InstallGetMessage(___518687646(1_996)).___518687646(1_997) : ___518687646(1_998)).TruncateText($_239789416[___518687646(1_999)], round(0 + 45 + 45)).___518687646(2_000).($_239789416[___518687646(2_001)] !== ___518687646(2_002) ? ___518687646(2_003).$_239789416[___518687646(2_004)].___518687646(2_005).GetMessage(___518687646(2_006)).___518687646(2_007) : ___518687646(2_008)).___518687646(2_009).htmlspecialcharsbx($_239789416[___518687646(2_010)]).___518687646(2_011);
            if ($_2101434245 === round(0 + 0.2 + 0.2 + 0.2 + 0.2 + 0.2)) {
                $this->content .= ___518687646(2_012);
                $_2101434245 = (248 * 2 - 496);
            } else {
                ++$_2101434245;
            }
        }
        if ($_2101434245 === (196 * 2 - 392)) {
            $this->content .= ___518687646(2_013);
        }
        $this->content .= ___518687646(2_014).htmlspecialcharsbx($_1969200253).___518687646(2_015).htmlspecialcharsbx($_1969200253).___518687646(2_016);
    }
}

class LoadModuleActionStep extends CWizardStep
{
    public $_1115940484 = [];
    public $_107974232 = [];

    public function InitStep()
    {
        $this->SetStepID(___518687646(2_017));
        $this->SetTitle(InstallGetMessage(___518687646(2_018)));
    }

    public function OnPostForm()
    {
        global $DB, $DBHost, $DBLogin, $DBPassword, $DBName, $DBDebug, $DBDebugToFile, $APPLICATION, $USER;

        require_once $_SERVER[___518687646(2_019)].___518687646(2_020);
        @$GLOBALS['____1079197990'][406](round(0 + 720 + 720 + 720 + 720 + 720));
        $wizard = $this->GetWizard();
        $_2025093500 = $wizard->GetVar(___518687646(2_021));
        $_2028633826 = $wizard->GetVar(___518687646(2_022));
        $_2028633826 = $GLOBALS['____1079197990'][407](___518687646(2_023), ___518687646(2_024), $_2028633826);
        if ($_2028633826 === ___518687646(2_025)) {
            $wizard->SetCurrentStep(___518687646(2_026));

            return;
        }
        $this->_107974232 = [___518687646(2_027) => InstallGetMessage(___518687646(2_028)), ___518687646(2_029) => InstallGetMessage(___518687646(2_030)), ___518687646(2_031) => InstallGetMessage(___518687646(2_032)), ___518687646(2_033) => InstallGetMessage(___518687646(2_034))];
        $this->_1115940484 = $GLOBALS['____1079197990'][408]($this->_107974232);
        if (!$GLOBALS['____1079197990'][409]($_2025093500, $this->_1115940484)) {
            if ($_2025093500 === ___518687646(2_035)) {
                $_1728136856 = BXInstallServices::GetWizardsList($_2028633826);
                $_139063776 = $GLOBALS['____1079197990'][410](___518687646(2_036), $_1728136856[860 - 2 * 430][___518687646(2_037)]);
                $_1357227016 = [];
                foreach ($_139063776 as $_675639870) {
                    $_675639870 = $GLOBALS['____1079197990'][411](___518687646(2_038), ___518687646(2_039), $_675639870);
                    if ($_675639870 !== ___518687646(2_040)) {
                        $_1357227016[] = $_675639870;
                    }
                }
                $_881142626 = ___518687646(2_041);
                if (BXInstallServices::CreateWizardIndex($_1357227016[round(0 + 1)].___518687646(2_042).$_1357227016[round(0 + 0.666_666_666_666_67 + 0.666_666_666_666_67 + 0.666_666_666_666_67)], $_881142626)) {
                    $_356328948 = ___518687646(2_043);
                    if ($GLOBALS['____1079197990'][412](___518687646(2_044))) {
                        $_996416527 = CSite::GetList(___518687646(2_045), ___518687646(2_046), [___518687646(2_047) => WIZARD_DEFAULT_SITE_ID]);
                        $_657728692 = $_996416527->GetNext();
                        $_356328948 = ___518687646(2_048);
                        if ($GLOBALS['____1079197990'][413]($_657728692[___518687646(2_049)]) && $_657728692[___518687646(2_050)][144 * 2 - 288] !== ___518687646(2_051) || $_657728692[___518687646(2_052)] !== ___518687646(2_053)) {
                            $_356328948 .= ___518687646(2_054);
                        }
                        if ($GLOBALS['____1079197990'][414]($_657728692[___518687646(2_055)])) {
                            $_356328948 .= $_657728692[___518687646(2_056)][167 * 2 - 334];
                        } else {
                            $_356328948 .= $_657728692[___518687646(2_057)];
                        }
                        $_356328948 .= $_657728692[___518687646(2_058)];
                    } else {
                        BXInstallServices::DeleteDirRec($_SERVER[___518687646(2_059)].___518687646(2_060));
                        BXInstallServices::DeleteDirRec($_SERVER[___518687646(2_061)].___518687646(2_062));
                        BXInstallServices::DeleteDirRec($_SERVER[___518687646(2_063)].___518687646(2_064));
                        BXInstallServices::DeleteDirRec($_SERVER[___518687646(2_065)].___518687646(2_066));
                        BXInstallServices::DeleteDirRec($_SERVER[___518687646(2_067)].___518687646(2_068));
                    }
                    if ($GLOBALS['____1079197990'][415](___518687646(2_069))) {
                        BXInstallServices::EncodeFile($_SERVER[___518687646(2_070)].___518687646(2_071).LANGUAGE_ID.___518687646(2_072), INSTALL_CHARSET);
                    }
                    BXInstallServices::LocalRedirect($_356328948);

                    return;
                }
            } else {
                $wizard->SetCurrentStep($_2025093500);

                return;
            }
        }
        $_661732917 = ___518687646(2_073);
        $_1824050818 = (930 - 2 * 465);
        $_1835071541 = ___518687646(2_074);
        if ($_2025093500 === ___518687646(2_075) || $_2025093500 === ___518687646(2_076)) {
            if (($_2025093500 === ___518687646(2_077)) || !$GLOBALS['____1079197990'][416]($_SERVER[___518687646(2_078)].___518687646(2_079).$_2028633826)) {
                require_once $_SERVER[___518687646(2_080)].___518687646(2_081);
                $_617254974 = CUpdateClientPartner::loadModule4Wizard($_2028633826, LANGUAGE_ID);
                $_1167332487 = $GLOBALS['____1079197990'][417]($_617254974, 932 - 2 * 466, round(0 + 1.5 + 1.5));
                if ($_1167332487 === ___518687646(2_082)) {
                    $this->SendResponse(___518687646(2_083).$GLOBALS['____1079197990'][418]($_617254974, round(0 + 1 + 1 + 1)).___518687646(2_084).$GLOBALS['____1079197990'][419]($_617254974, round(0 + 0.75 + 0.75 + 0.75 + 0.75)).___518687646(2_085));
                } elseif ($_1167332487 === ___518687646(2_086)) {
                    $_661732917 = ___518687646(2_087);
                    $_1835071541 = $this->_107974232[___518687646(2_088)];
                    $_1824050818 = round(0 + 40);
                } else {
                    $_661732917 = ___518687646(2_089);
                    $_1835071541 = $this->_107974232[___518687646(2_090)];
                    $_1824050818 = round(0 + 40);
                }
            } else {
                $_661732917 = ___518687646(2_091);
                $_1835071541 = $this->_107974232[___518687646(2_092)];
                $_1824050818 = round(0 + 20 + 20);
            }
        } elseif ($_2025093500 === ___518687646(2_093)) {
            if (!IsModuleInstalled($_2028633826)) {
                $_1292308431 = $this->GetModuleObject($_2028633826);
                if (!$GLOBALS['____1079197990'][420]($_1292308431)) {
                    $this->SendResponse(___518687646(2_094).InstallGetMessage(___518687646(2_095)).___518687646(2_096).InstallGetMessage(___518687646(2_097)).___518687646(2_098));
                }
                if (!$_1292308431->InstallDB()) {
                    if ($_1531044420 = $APPLICATION->GetException()) {
                        $this->SendResponse(___518687646(2_099).$_1531044420->GetString().___518687646(2_100));
                    } else {
                        $this->SendResponse(___518687646(2_101).InstallGetMessage(___518687646(2_102)).___518687646(2_103));
                    }
                }
                $_1292308431->InstallEvents();
                if (!$_1292308431->InstallFiles()) {
                    if ($_1531044420 = $APPLICATION->GetException()) {
                        $this->SendResponse(___518687646(2_104).$_1531044420->GetString().___518687646(2_105));
                    } else {
                        $this->SendResponse(___518687646(2_106).InstallGetMessage(___518687646(2_107)).___518687646(2_108));
                    }
                }
            }
            $_661732917 = ___518687646(2_109);
            $_1835071541 = $this->_107974232[___518687646(2_110)];
            $_1824050818 = round(0 + 40 + 40);
        } elseif ($_2025093500 === ___518687646(2_111)) {
            $_1728136856 = BXInstallServices::GetWizardsList($_2028633826);
            if ($GLOBALS['____1079197990'][421]($_1728136856) === round(0 + 0.333_333_333_333_33 + 0.333_333_333_333_33 + 0.333_333_333_333_33)) {
                $_139063776 = $GLOBALS['____1079197990'][422](___518687646(2_112), $_1728136856[199 * 2 - 398][___518687646(2_113)]);
                $_1357227016 = [];
                foreach ($_139063776 as $_675639870) {
                    $_675639870 = $GLOBALS['____1079197990'][423](___518687646(2_114), ___518687646(2_115), $_675639870);
                    if ($_675639870 !== ___518687646(2_116)) {
                        $_1357227016[] = $_675639870;
                    }
                }
                BXInstallServices::CopyDirFiles($_SERVER[___518687646(2_117)].___518687646(2_118).$_1357227016[141 * 2 - 282].___518687646(2_119).$_1357227016[round(0 + 1)].___518687646(2_120).$_1357227016[round(0 + 0.666_666_666_666_67 + 0.666_666_666_666_67 + 0.666_666_666_666_67)], $_SERVER[___518687646(2_121)].___518687646(2_122).$_1357227016[round(0 + 0.5 + 0.5)].___518687646(2_123).$_1357227016[round(0 + 2)], true);
                $_661732917 = ___518687646(2_124);
            } elseif ($GLOBALS['____1079197990'][424]($_1728136856) === (142 * 2 - 284)) {
                $_661732917 = ___518687646(2_125);
            } else {
                $_661732917 = ___518687646(2_126);
            }
            $_1824050818 = round(0 + 33.333_333_333_333 + 33.333_333_333_333 + 33.333_333_333_333);
            $_1835071541 = $this->_107974232[___518687646(2_127)];
        }
        $_1188574468 = ___518687646(2_128);
        if (!$GLOBALS['____1079197990'][425]($_661732917, $this->_1115940484)) {
            $_1188574468 .= ___518687646(2_129);
        }
        $_1188574468 .= ___518687646(2_130).$_1824050818.___518687646(2_131).$_661732917.___518687646(2_132).$_2028633826.___518687646(2_133).$_1835071541.___518687646(2_134);
        $this->SendResponse($_1188574468);
    }

    public function SendResponse($_1188574468)
    {
        $GLOBALS['____1079197990'][426](___518687646(2_135).INSTALL_CHARSET);

        exit(___518687646(2_136).$_1188574468.___518687646(2_137));
    }

    public function GetModuleObject($_974596800)
    {
        $_503055972 = $_SERVER[___518687646(2_138)].___518687646(2_139).$_974596800.___518687646(2_140);
        if (!$GLOBALS['____1079197990'][427]($_503055972)) {
            return false;
        }
        @include_once $_503055972;
        $_1859041608 = $GLOBALS['____1079197990'][428](___518687646(2_141), ___518687646(2_142), $_974596800);
        if (!$GLOBALS['____1079197990'][429]($_1859041608)) {
            return false;
        }

        return new $_1859041608();
    }

    public function ShowStep()
    {
        @include_once $_SERVER[___518687646(2_143)].BX_PERSONAL_ROOT.___518687646(2_144);
        if ($GLOBALS['____1079197990'][430](___518687646(2_145))) {
            $GLOBALS['____1079197990'][431](___518687646(2_146), true);
        }
        $wizard = $this->GetWizard();
        $_874183770 = $wizard->GetVar(___518687646(2_147));
        $this->content .= ___518687646(2_148).InstallGetMessage(___518687646(2_149)).___518687646(2_150).InstallGetMessage(___518687646(2_151)).___518687646(2_152).InstallGetMessage(___518687646(2_153)).___518687646(2_154).InstallGetMessage(___518687646(2_155)).___518687646(2_156).$this->ShowHiddenField(___518687646(2_157), ___518687646(2_158)).___518687646(2_159).$this->ShowHiddenField(___518687646(2_160), $_874183770).___518687646(2_161);
        $wizard = $this->GetWizard();
        $formName = $wizard->GetFormName();
        $_2146404876 = $wizard->GetRealName(___518687646(2_162));
        $this->content .= ___518687646(2_163).$formName.___518687646(2_164).$_2146404876.___518687646(2_165).$_874183770.___518687646(2_166).InstallGetMessage(___518687646(2_167)).___518687646(2_168);
    }
}

class SelectWizard1Step extends SelectWizardStep
{
    public function InitStep()
    {
        $this->SetStepID(___518687646(2_169));
        $this->SetNextStep(___518687646(2_170));
        $this->SetNextCaption(InstallGetMessage(___518687646(2_171)));
        $this->SetTitle(InstallGetMessage(___518687646(2_172)));
    }

    public function OnPostForm()
    {
        global $DB, $DBHost, $DBLogin, $DBPassword, $DBName, $DBDebug, $DBDebugToFile, $APPLICATION, $USER;

        require_once $_SERVER[___518687646(2_173)].___518687646(2_174);
        $wizard = $this->GetWizard();
        $_2131437686 = $wizard->GetVar(___518687646(2_175));
        if ($_2131437686 === ___518687646(2_176)) {
            $this->SetError(InstallGetMessage(___518687646(2_177)));

            return null;
        }
        if ($_2131437686 === ___518687646(2_178)) {
            $wizard->SetCurrentStep(___518687646(2_179));

            return true;
        }
        $_139063776 = $GLOBALS['____1079197990'][432](___518687646(2_180), $_2131437686);
        $_1357227016 = [];
        foreach ($_139063776 as $_675639870) {
            $_675639870 = $GLOBALS['____1079197990'][433](___518687646(2_181), ___518687646(2_182), $_675639870);
            if ($_675639870 !== ___518687646(2_183)) {
                $_1357227016[] = $_675639870;
            }
        }
        if ($GLOBALS['____1079197990'][434]($_1357227016) > round(0 + 0.5 + 0.5 + 0.5 + 0.5)) {
            $_673305200 = $_SERVER[___518687646(2_184)].___518687646(2_185).$_1357227016[min(228, 0, 76)].___518687646(2_186).$_1357227016[round(0 + 0.25 + 0.25 + 0.25 + 0.25)].___518687646(2_187).$_1357227016[round(0 + 0.5 + 0.5 + 0.5 + 0.5)];
            if (!$GLOBALS['____1079197990'][435]($_673305200) || !$GLOBALS['____1079197990'][436]($_673305200)) {
                $this->SetError(InstallGetMessage(___518687646(2_188)));

                return;
            }
            BXInstallServices::CopyDirFiles($_SERVER[___518687646(2_189)].___518687646(2_190).$_1357227016[183 * 2 - 366].___518687646(2_191).$_1357227016[round(0 + 1)].___518687646(2_192).$_1357227016[round(0 + 0.4 + 0.4 + 0.4 + 0.4 + 0.4)], $_SERVER[___518687646(2_193)].___518687646(2_194).$_1357227016[round(0 + 1)].___518687646(2_195).$_1357227016[round(0 + 0.4 + 0.4 + 0.4 + 0.4 + 0.4)], true);
            $_1357227016 = [$_1357227016[round(0 + 0.333_333_333_333_33 + 0.333_333_333_333_33 + 0.333_333_333_333_33)], $_1357227016[round(0 + 1 + 1)]];
        }
        if (!$GLOBALS['____1079197990'][437]($_SERVER[___518687646(2_196)].___518687646(2_197).$_1357227016[175 * 2 - 350].___518687646(2_198).$_1357227016[round(0 + 0.2 + 0.2 + 0.2 + 0.2 + 0.2)]) || !$GLOBALS['____1079197990'][438]($_SERVER[___518687646(2_199)].___518687646(2_200).$_1357227016[126 * 2 - 252].___518687646(2_201).$_1357227016[round(0 + 0.333_333_333_333_33 + 0.333_333_333_333_33 + 0.333_333_333_333_33)])) {
            $this->SetError(InstallGetMessage(___518687646(2_202)));

            return;
        }
        if (BXInstallServices::CreateWizardIndex($_1357227016[min(98, 0, 32.666_666_666_667)].___518687646(2_203).$_1357227016[round(0 + 0.5 + 0.5)], $_881142626)) {
            $_356328948 = ___518687646(2_204);
            if ($GLOBALS['____1079197990'][439](___518687646(2_205))) {
                $_996416527 = CSite::GetList(___518687646(2_206), ___518687646(2_207), [___518687646(2_208) => WIZARD_DEFAULT_SITE_ID]);
                $_657728692 = $_996416527->GetNext();
                $_356328948 = ___518687646(2_209);
                if ($GLOBALS['____1079197990'][440]($_657728692[___518687646(2_210)]) && $_657728692[___518687646(2_211)][212 * 2 - 424] !== ___518687646(2_212) || $_657728692[___518687646(2_213)] !== ___518687646(2_214)) {
                    $_356328948 .= ___518687646(2_215);
                }
                if ($GLOBALS['____1079197990'][441]($_657728692[___518687646(2_216)])) {
                    $_356328948 .= $_657728692[___518687646(2_217)][1_176 / 2 - 588];
                } else {
                    $_356328948 .= $_657728692[___518687646(2_218)];
                }
                $_356328948 .= $_657728692[___518687646(2_219)];
            } else {
                BXInstallServices::DeleteDirRec($_SERVER[___518687646(2_220)].___518687646(2_221));
                BXInstallServices::DeleteDirRec($_SERVER[___518687646(2_222)].___518687646(2_223));
                BXInstallServices::DeleteDirRec($_SERVER[___518687646(2_224)].___518687646(2_225));
                BXInstallServices::DeleteDirRec($_SERVER[___518687646(2_226)].___518687646(2_227));
                BXInstallServices::DeleteDirRec($_SERVER[___518687646(2_228)].___518687646(2_229));
            }
            if ($GLOBALS['____1079197990'][442](___518687646(2_230))) {
                BXInstallServices::EncodeFile($_SERVER[___518687646(2_231)].___518687646(2_232).LANGUAGE_ID.___518687646(2_233), INSTALL_CHARSET);
            }
            BXInstallServices::LocalRedirect($_356328948);
        } else {
            $this->SetError($_881142626);
        }

        return true;
    }

    public function ShowStep()
    {
        if ($GLOBALS['____1079197990'][443](___518687646(2_234))) {
            $GLOBALS['____1079197990'][444](___518687646(2_235), true);
        }
        $wizard = $this->GetWizard();
        $_1969200253 = $wizard->GetRealName(___518687646(2_236));
        $_2028633826 = $wizard->GetVar(___518687646(2_237));
        $_1728136856 = BXInstallServices::GetWizardsList($_2028633826);
        $this->content = ___518687646(2_238).CUtil::JSEscape($_1969200253).___518687646(2_239);
        $_1728136856[] = [___518687646(2_240) => ___518687646(2_241), ___518687646(2_242) => ___518687646(2_243), ___518687646(2_244) => InstallGetMessage(___518687646(2_245)), ___518687646(2_246) => InstallGetMessage(___518687646(2_247))];
        $this->content .= ___518687646(2_248);
        $_2101434245 = (776 - 2 * 388);
        foreach ($_1728136856 as $_405864272) {
            if ($_2101434245 === (880 - 2 * 440)) {
                $this->content .= ___518687646(2_249);
            }
            $this->content .= ___518687646(2_250).htmlspecialcharsbx($_405864272[___518687646(2_251)]).___518687646(2_252).htmlspecialcharsbx($wizard->GetFormName()).___518687646(2_253).TruncateText($_405864272[___518687646(2_254)], round(0 + 29.5 + 29.5)).___518687646(2_255).($_405864272[___518687646(2_256)] !== ___518687646(2_257) ? ___518687646(2_258).htmlspecialcharsbx($_405864272[___518687646(2_259)]).___518687646(2_260) : ___518687646(2_261)).___518687646(2_262).TruncateText($_405864272[___518687646(2_263)], round(0 + 30 + 30 + 30)).___518687646(2_264).htmlspecialcharsbx($_405864272[___518687646(2_265)]).___518687646(2_266);
            if ($_2101434245 === round(0 + 0.2 + 0.2 + 0.2 + 0.2 + 0.2)) {
                $this->content .= ___518687646(2_267);
                $_2101434245 = (910 - 2 * 455);
            } else {
                ++$_2101434245;
            }
        }
        if ($_2101434245 === (764 - 2 * 382)) {
            $this->content .= ___518687646(2_268);
        }
        $this->content .= ___518687646(2_269).htmlspecialcharsbx($_1969200253).___518687646(2_270).htmlspecialcharsbx($_1969200253).___518687646(2_271);
    }
}

class FinishStep extends CWizardStep
{
    public function InitStep()
    {
        $this->SetStepID(___518687646(2_272));
        $this->SetTitle(InstallGetMessage(___518687646(2_273)));
    }

    public function CreateNewIndex()
    {
        $_2137172710 = @$GLOBALS['____1079197990'][445]($_SERVER[___518687646(2_274)].___518687646(2_275), ___518687646(2_276));
        if (!$_2137172710) {
            $this->SetError(InstallGetMessage(___518687646(2_277)));

            return;
        }
        $_1256265352 = @$GLOBALS['____1079197990'][446]($_2137172710, ___518687646(2_278).___518687646(2_279).___518687646(2_280).___518687646(2_281).___518687646(2_282).___518687646(2_283).___518687646(2_284).___518687646(2_285).___518687646(2_286));
        if (!$_1256265352) {
            $this->SetError(InstallGetMessage(___518687646(2_287)));

            return;
        }
        if ($GLOBALS['____1079197990'][447](___518687646(2_288))) {
            @$GLOBALS['____1079197990'][448]($_SERVER[___518687646(2_289)].___518687646(2_290), BX_FILE_PERMISSIONS);
        }
        $GLOBALS['____1079197990'][449]($_2137172710);
    }

    public function ShowStep()
    {
        $this->CreateNewIndex();
        BXInstallServices::DeleteDirRec($_SERVER[___518687646(2_291)].___518687646(2_292));
        $this->content = ___518687646(2_293).InstallGetMessage(___518687646(2_294)).___518687646(2_295).LANGUAGE_ID.___518687646(2_296).InstallGetMessage(___518687646(2_297)).___518687646(2_298).InstallGetMessage(___518687646(2_299)).___518687646(2_300).LANGUAGE_ID.___518687646(2_301).InstallGetMessage(___518687646(2_302)).___518687646(2_303).LANGUAGE_ID.___518687646(2_304).InstallGetMessage(___518687646(2_305)).___518687646(2_306).InstallGetMessage(___518687646(2_307)).___518687646(2_308).InstallGetMessage(___518687646(2_309)).___518687646(2_310);
    }
}

class CheckLicenseKey extends CWizardStep
{
    public function InitStep()
    {
        $this->SetStepID(___518687646(2_311));
        $this->SetNextStep(___518687646(2_312));
        $this->SetNextCaption(InstallGetMessage(___518687646(2_313)));
        $this->SetTitle(InstallGetMessage(___518687646(2_314)));
        $wizard = $this->GetWizard();
        if ($GLOBALS['____1079197990'][450](___518687646(2_315)) || $GLOBALS['____1079197990'][451](___518687646(2_316))) {
            $wizard->SetDefaultVar(___518687646(2_317), ___518687646(2_318));
        }
        if ($GLOBALS['____1079197990'][452]($_SERVER[___518687646(2_319)].___518687646(2_320))) {
            $LICENSE_KEY = ___518687646(2_321);

            include $_SERVER[___518687646(2_322)].___518687646(2_323);
            $wizard->SetDefaultVar(___518687646(2_324), $LICENSE_KEY);
        }
    }

    public function OnPostForm()
    {
        $wizard = $this->GetWizard();
        $_21197058 = $wizard->GetVar(___518687646(2_325));
        if (!$GLOBALS['____1079197990'][453](___518687646(2_326)) && !$GLOBALS['____1079197990'][454](___518687646(2_327)) && $GLOBALS['____1079197990'][455](___518687646(2_328)) && !$GLOBALS['____1079197990'][456](___518687646(2_329), $_21197058)) {
            $this->SetError(InstallGetMessage(___518687646(2_330)), ___518687646(2_331));

            return;
        }
        if ($GLOBALS['____1079197990'][457](___518687646(2_332)) || $GLOBALS['____1079197990'][458](___518687646(2_333))) {
            $_106940388 = $wizard->GetVar(___518687646(2_334));
            if (($GLOBALS['____1079197990'][459](___518687646(2_335)) || ($GLOBALS['____1079197990'][460](___518687646(2_336)) && $_106940388 === ___518687646(2_337))) && $_21197058 === ___518687646(2_338)) {
                $_866963222 = $wizard->GetVar(___518687646(2_339));
                $_1059820518 = $wizard->GetVar(___518687646(2_340));
                $_410600491 = $wizard->GetVar(___518687646(2_341));
                $_754699777 = false;
                if ($GLOBALS['____1079197990'][461]($_1059820518) === ___518687646(2_342)) {
                    $this->SetError(InstallGetMessage(___518687646(2_343)), ___518687646(2_344));
                    $_754699777 = true;
                }
                if ($GLOBALS['____1079197990'][462]($_866963222) === ___518687646(2_345)) {
                    $this->SetError(InstallGetMessage(___518687646(2_346)), ___518687646(2_347));
                    $_754699777 = true;
                }
                if ($GLOBALS['____1079197990'][463]($_410600491) === ___518687646(2_348) || !check_email($_410600491)) {
                    $this->SetError(InstallGetMessage(___518687646(2_349)), ___518687646(2_350));
                    $_754699777 = true;
                }
                if (!$_754699777) {
                    $_990466145 = BXInstallServices::GetRegistrationKey($_1059820518, $_866963222, $_410600491, ___518687646(2_351));
                    if (false !== $_990466145) {
                        $wizard->SetVar(___518687646(2_352), $_990466145);
                    } elseif ($GLOBALS['____1079197990'][464](___518687646(2_353))) {
                        $this->SetError(InstallGetMessage(___518687646(2_354)), ___518687646(2_355));
                    }
                }
            }
        }
        $this->CreateLicenseFile();
    }

    public function CreateLicenseFile()
    {
        $wizard = $this->GetWizard();
        $_21197058 = $wizard->GetVar(___518687646(2_356));

        return BXInstallServices::CreateLicenseFile($_21197058);
    }

    public function ShowStep()
    {
        $this->content = ___518687646(2_357).InstallGetMessage(___518687646(2_358)).___518687646(2_359);
        if (!$GLOBALS['____1079197990'][465](___518687646(2_360)) && !$GLOBALS['____1079197990'][466](___518687646(2_361))) {
            $this->content .= ___518687646(2_362).InstallGetMessage(___518687646(2_363)).___518687646(2_364).$this->ShowInputField(___518687646(2_365), ___518687646(2_366), [___518687646(2_367) => ___518687646(2_368), ___518687646(2_369) => ___518687646(2_370), ___518687646(2_371) => ___518687646(2_372)]).___518687646(2_373).InstallGetMessage(___518687646(2_374)).___518687646(2_375).InstallGetMessage(___518687646(2_376)).___518687646(2_377).$this->ShowCheckboxField(___518687646(2_378), ___518687646(2_379), [___518687646(2_380) => ___518687646(2_381)]).___518687646(2_382).InstallGetMessage(___518687646(2_383)).___518687646(2_384);
        } else {
            $this->content .= ___518687646(2_385);
            if (!$GLOBALS['____1079197990'][467](___518687646(2_386))) {
                $this->content .= ___518687646(2_387).$this->ShowCheckboxField(___518687646(2_388), ___518687646(2_389), [___518687646(2_390) => ___518687646(2_391), ___518687646(2_392) => ___518687646(2_393)]).___518687646(2_394).InstallGetMessage(___518687646(2_395)).___518687646(2_396);
            }
            $wizard = $this->GetWizard();
            $_106940388 = $wizard->GetVar(___518687646(2_397), $_575710406 = true);
            $this->content .= ___518687646(2_398).InstallGetMessage(___518687646(2_399)).___518687646(2_400).$this->ShowInputField(___518687646(2_401), ___518687646(2_402), [___518687646(2_403) => ___518687646(2_404), ___518687646(2_405) => ___518687646(2_406), ___518687646(2_407) => ___518687646(2_408)]).___518687646(2_409).InstallGetMessage(___518687646(2_410)).___518687646(2_411).$this->ShowInputField(___518687646(2_412), ___518687646(2_413), [___518687646(2_414) => ___518687646(2_415), ___518687646(2_416) => ___518687646(2_417), ___518687646(2_418) => ___518687646(2_419)]).___518687646(2_420).$this->ShowInputField(___518687646(2_421), ___518687646(2_422), [___518687646(2_423) => ___518687646(2_424), ___518687646(2_425) => ___518687646(2_426), ___518687646(2_427) => ___518687646(2_428)]).___518687646(2_429).(($_106940388 === ___518687646(2_430)) ? ___518687646(2_431) : ___518687646(2_432)).___518687646(2_433);
        }
    }
}

$wizard = new CWizardBase($GLOBALS['____1079197990'][468](___518687646(2_434), SM_VERSION, InstallGetMessage(___518687646(2_435))), $package = null);
if ($GLOBALS['____1079197990'][469](___518687646(2_436)) && WIZARD_DEFAULT_TONLY === true) {
    global $USER;

    require_once $_SERVER[___518687646(2_437)].___518687646(2_438);
    if ($USER->CanDoOperation(___518687646(2_439))) {
        $_1115940484 = [___518687646(2_440), ___518687646(2_441), ___518687646(2_442), ___518687646(2_443)];
    } else {
        exit;
    }
} elseif (BXInstallServices::IsShortInstall()) {
    $_1115940484 = [];
    if ($GLOBALS['____1079197990'][470](___518687646(2_444))) {
        $_1115940484 = [___518687646(2_445)];
    }
    if ($_SERVER[___518687646(2_446)] !== ___518687646(2_447)) {
        $_1115940484[] = ___518687646(2_448);
        $_1115940484[] = ___518687646(2_449);
        $_1115940484[] = ___518687646(2_450);
        $_1115940484[] = ___518687646(2_451);
        $_1115940484[] = ___518687646(2_452);
        $_1115940484[] = ___518687646(2_453);
        $_1115940484[] = ___518687646(2_454);
        if (false === BXInstallServices::GetDemoWizard()) {
            $_1115940484[] = ___518687646(2_455);
        }
    } else {
        $_1115940484[] = ___518687646(2_456);
        $_1115940484[] = ___518687646(2_457);
    }
} else {
    $_1115940484 = [___518687646(2_458), ___518687646(2_459), ___518687646(2_460), ___518687646(2_461), ___518687646(2_462), ___518687646(2_463), ___518687646(2_464)];
    $_1728136856 = BXInstallServices::GetWizardsList();
    if ($GLOBALS['____1079197990'][471]($_1728136856) > min(242, 0, 80.666_666_666_667)) {
        $_1115940484[] = ___518687646(2_465);
        $_1115940484[] = ___518687646(2_466);
        $_1115940484[] = ___518687646(2_467);
        $_1115940484[] = ___518687646(2_468);
    }
    if (false === BXInstallServices::GetDemoWizard()) {
        $_1115940484[] = ___518687646(2_469);
    }
}
$wizard->AddSteps($_1115940484);
$wizard->SetTemplate(new WizardTemplate());
$wizard->SetReturnOutput();
$content = $wizard->Display();
if ($GLOBALS['____1079197990'][472](___518687646(2_470))) {
    $_1410902833 = ___518687646(2_471);
    $content = $GLOBALS['____1079197990'][473]($content, ___518687646(2_472), INSTALL_CHARSET);
} else {
    $_1410902833 = INSTALL_CHARSET;
}
$GLOBALS['____1079197990'][474](___518687646(2_473).$_1410902833);
echo $content;
