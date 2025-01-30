<?php

/**
 * phpDoc по константам битрикса.
 *
 * @see http://dev.1c-bitrix.ru/api_help/main/general/constants.php
 *
 * @version 1.0
 */

/*
 * Идентификатор текущего сайта.
 *
 * <b>ТИП:</b>
 * <ul>
 *
 * <li><b>А</b> (<b>а</b>вто) - константа инициализируется системой в
 * <a href="/api_help/main/general/terms.php#prolog">прологе</a> автоматически </li>
 *
 * <li><b>Т</b> (с<b>т</b>раница) - константу можно определить на любой
 * <a href="/api_help/main/general/terms.php#public">публичной</a> странице до
 * подключения
 * <a href="/api_help/main/general/terms.php#prolog">пролога</a> </li>
 *
 * <li><b>И</b> (<b>и</b>нициализация) - константу можно определить в
 * одном из следующих файлов:
 * <ul>
 *
 * <li><b>/bitrix/php_interface/init.php</b> - дополнительные параметры
 * <a href="/api_help/main/general/terms.php#portal">портала</a> </li>
 *
 * <li><b>/bitrix/php_interface/</b><i>ID сайта</i><b>/init.php</b> - дополнительные
 * параметры
 * <a href="/api_help/main/general/terms.php#site">сайта</a> </li>     </ul>
 * </li></ul>
 * @var string|bool
 */
define('SITE_ID', '');

/*
 * Поле "Папка сайта" в настройках сайта. Как правило
 * используется в случае организации многосайтовости по
 * <a href="/api_help/main/general/site/multisite.php">способу 1</a>.
 *
 * <b>ТИП:</b>
 * <ul>
 *
 * <li><b>А</b> (<b>а</b>вто) - константа инициализируется системой в
 * <a href="/api_help/main/general/terms.php#prolog">прологе</a> автоматически
 * </li></ul>
 * @var string|bool
 */
define('SITE_DIR', '');

/*
 * Поле "URL сервера" в настройках текущего сайта.
 *
 * <b>ТИП:</b>
 * <ul>
 *
 * <li><b>А</b> (<b>а</b>вто) - константа инициализируется системой в
 * <a href="/api_help/main/general/terms.php#prolog">прологе</a> автоматически
 * </li></ul>
 * @var string|bool
 */
define('SITE_SERVER_NAME', '');

/*
 * URL от корня сайта до папки текущего шаблона.
 *
 * <b>ТИП:</b>
 * <ul>
 *
 * <li><b>А</b> (<b>а</b>вто) - константа инициализируется системой в
 * <a href="/api_help/main/general/terms.php#prolog">прологе</a> автоматически
 * </li></ul>
 * @var string|bool
 */
define('SITE_TEMPLATE_PATH', '');

/*
 * Поле "Кодировка" в настройках текущего сайта.
 *
 * <b>ТИП:</b>
 * <ul>
 *
 * <li><b>А</b> (<b>а</b>вто) - константа инициализируется системой в
 * <a href="/api_help/main/general/terms.php#prolog">прологе</a> автоматически
 * </li></ul>
 * @var string|bool
 */
define('SITE_CHARSET', '');

/*
 * Для
 * <a href="/api_help/main/general/terms.php#public">публичной части</a>, в данной
 * константе хранится формат даты из настроек текущего сайта.
 * Для
 * <a href="/api_help/main/general/terms.php#admin">административной части</a> -
 * формат даты текущего языка.
 *
 * <b>ТИП:</b>
 * <ul>
 *
 * <li><b>А</b> (<b>а</b>вто) - константа инициализируется системой в
 * <a href="/api_help/main/general/terms.php#prolog">прологе</a> автоматически
 * </li></ul>
 * @var string|bool
 */
define('FORMAT_DATE', '');

/*
 * Для публичной части, в данной константе хранится формат
 * времени из настроек текущего сайта.Для административной
 * части - формат времени текущего языка.
 *
 * <b>ТИП:</b>
 * <ul>
 *
 * <li><b>А</b> (<b>а</b>вто) - константа инициализируется системой в
 * <a href="/api_help/main/general/terms.php#prolog">прологе</a> автоматически
 * </li></ul>
 * @var string|bool
 */
define('FORMAT_DATETIME', '');

/*
 * Если это
 * <a href="/api_help/main/general/terms.php#public">публичная часть</a>, то в данной
 * константе храниться поле "Язык" из настроек текущего сайта,
 * если
 * <a href="/api_help/main/general/terms.php#admin">административная часть</a>, то в
 * данной константе храниться идентификатор текущего языка.
 *
 * <b>ТИП:</b>
 * <ul>
 *
 * <li><b>А</b> (<b>а</b>вто) - константа инициализируется системой в
 * <a href="/api_help/main/general/terms.php#prolog">прологе</a> автоматически
 * </li></ul>
 * @var string|bool
 */
define('LANGUAGE_ID', '');

/*
 * В данной константе содержится значение кодировки,
 * указанной в секции <i>Параметры</i> формы настроек текущего
 * сайта.
 *
 * <b>ТИП:</b>
 * <ul>
 *
 * <li><b>А</b> (<b>а</b>вто) - константа инициализируется системой в
 * <a href="/api_help/main/general/terms.php#prolog">прологе</a> автоматически
 * </li></ul>
 * @var string|bool
 */
define('LANG_CHARSET', '');

/*
 * Идентификатор текущего шаблона сайта.
 *
 * <b>ТИП:</b>
 * <ul>
 *
 * <li><b>А</b> (<b>а</b>вто) - константа инициализируется системой в
 * <a href="/api_help/main/general/terms.php#prolog">прологе</a> автоматически
 * </li></ul>
 * @var string|bool
 */
define('SITE_TEMPLATE_ID', '');

/*
 * Содержит время начала работы страницы в формате
 * возвращаемом функцией
 * <a href="/api_help/main/functions/date/getmicrotime.php">getmicrotime</a>.
 *
 * <b>ТИП:</b>
 * <ul>
 *
 * <li><b>А</b> (<b>а</b>вто) - константа инициализируется системой в
 * <a href="/api_help/main/general/terms.php#prolog">прологе</a> автоматически
 * </li></ul>
 * @var string|bool
 */
define('START_EXEC_TIME', '');

/*
 * Если подключена
 * <a href="/api_help/main/general/terms.php#prolog">служебная часть пролога</a>, то
 * данная константа будет инициализирована значением "true". Как
 * правило эту константу используют во включаемых файлах в
 * целях безопасности, когда необходимо убедиться, что пролог
 * подключен и все необходимые права проверены.
 *
 * <b>ТИП:</b>
 * <ul>
 *
 * <li><b>А</b> (<b>а</b>вто) - константа инициализируется системой в
 * <a href="/api_help/main/general/terms.php#prolog">прологе</a> автоматически
 * </li></ul>
 * @var string|bool
 */
define('B_PROLOG_INCLUDED', '');

/*
 * Текущая версия главного модуля.
 *
 * <b>ТИП:</b>
 * <ul>
 *
 * <li><b>А</b> (<b>а</b>вто) - константа инициализируется системой в
 * <a href="/api_help/main/general/terms.php#prolog">прологе</a> автоматически
 * </li></ul>
 * @var string|bool
 */
define('SM_VERSION', '');

/*
 * Дата выпуска текущей версии главного модуля.
 *
 * <b>ТИП:</b>
 * <ul>
 *
 * <li><b>А</b> (<b>а</b>вто) - константа инициализируется системой в
 * <a href="/api_help/main/general/terms.php#prolog">прологе</a> автоматически
 * </li></ul>
 * @var string|bool
 */
define('SM_VERSION_DATE', '');

/*
 * Если необходимо подключать
 * <a href="/api_help/main/general/terms.php#prolog">пролог</a>
 * <a href="/api_help/main/general/terms.php#admin">административной части</a>, то
 * значение данной константы - "true".
 *
 * <b>ТИП:</b>
 * <ul>
 *
 * <li><b>А</b> (<b>а</b>вто) - константа инициализируется системой в
 * <a href="/api_help/main/general/terms.php#prolog">прологе</a> автоматически </li>
 *
 * <li><b>Т</b> (с<b>т</b>раница) - константу можно определить на любой
 * <a href="/api_help/main/general/terms.php#public">публичной</a> странице до
 * подключения
 * <a href="/api_help/main/general/terms.php#prolog">пролога</a>
 * </li></ul>
 * @var string|bool
 */
define('ADMIN_SECTION', '');

/*
 * <p>Данную константу необходимо инициализировать до
 * <a href="/api_help/main/general/terms.php#prolog">пролога</a> в файлах-обработчиках
 * 404 ошибки (страница не найдена). Подобные файлы-обработчики
 * задаются в настройках веб-сервера.</p>
 * <p>Инициализация этой константы позволяет в стандартных
 * компонентах авторизации, регистрации, высылки забытого
 * пароля, смены пароля поменять страницу на которую будет
 * осуществляться
 * <a href="/api_help/main/general/terms.php#submit">сабмит</a> соответствующей формы.
 * Этой страницей по умолчанию является - текущая страница,
 * если же константа инициализирована, то это будет -
 * <b>/SITE_DIR/auth.php</b>.</p>               <p>Необходимость инициализации этой
 * константы связана с тем, что на несуществующие страницы
 * отослать данные методом POST нельзя, а именно с этим методом и
 * работают вышеперечисленные компоненты. Поэтому если файл
 * текущей страницы физически не существует на сервере, то без
 * этой константы компоненты работать не будут.</p>
 *
 * <b>ТИП:</b>
 * <ul>
 *
 * <li><b>Т</b> (с<b>т</b>раница) - константу можно определить на любой
 * <a href="/api_help/main/general/terms.php#public">публичной</a> странице до
 * подключения
 * <a href="/api_help/main/general/terms.php#prolog">пролога</a>
 * </li></ul>
 *
 * @example <pre>define("AUTH_404", "Y");</pre>
 * @var string|bool
 */
define('AUTH_404', '');

/*
 * Данная константа используется как правило в
 * <a href="/api_help/main/general/terms.php#admin">административных</a> скриптах, для
 * хранения имени файла контекстно-зависимой помощи, в случае
 * если это имя отличается от имени данного скрипта. Ссылка на
 * контекстно-зависимую помощь выводится в виде иконки на
 * административной панели.
 *
 * <b>ТИП:</b>
 * <ul>
 *
 * <li><b>Т</b> (с<b>т</b>раница) - константу можно определить на любой
 * <a href="/api_help/main/general/terms.php#public">публичной</a> странице до
 * подключения
 * <a href="/api_help/main/general/terms.php#prolog">пролога</a>
 * </li></ul>
 *
 * @example <pre>define("HELP_FILE",       "my_admin_script.php");</pre>
 * @var string|bool
 */
define('HELP_FILE', '');

/*
 * Если инициализировать данную константу значением "true" до
 * подключения
 * <a href="/api_help/main/general/terms.php#prolog">пролога</a>, то будет проведена
 * <a href="/api_help/main/reference/cuser/isauthorized.php">проверка</a> на
 * авторизованность пользователя. Если пользователь не
 * авторизован, то ему будет
 * <a href="/api_help/main/reference/cmain/authform.php">предложена форма
 * авторизации</a>.
 *
 * <b>ТИП:</b>
 * <ul>
 *
 * <li><b>Т</b> (с<b>т</b>раница) - константу можно определить на любой
 * <a href="/api_help/main/general/terms.php#public">публичной</a> странице до
 * подключения
 * <a href="/api_help/main/general/terms.php#prolog">пролога</a>
 * </li></ul>
 *
 * @example <pre>define("NEED_AUTH", true);</pre>
 * @var string|bool
 */
define('NEED_AUTH', '');

/*
 * Хранит E-Mail адрес (или группу адресов разделенных запятой),
 * используемый функцией
 * <a href="/api_help/main/functions/debug/senderror.php">SendError</a> для отправки
 * сообщений об ошибках.
 *
 * <b>ТИП:</b>
 * <ul>
 *
 * <li><b>Т</b> (с<b>т</b>раница) - константу можно определить на любой
 * <a href="/api_help/main/general/terms.php#public">публичной</a> странице до
 * подключения
 * <a href="/api_help/main/general/terms.php#prolog">пролога</a> </li>
 *
 * <li><b>И</b> (<b>и</b>нициализация) - константу можно определить в
 * одном из следующих файлов:
 * <ul>
 *
 * <li><b>/bitrix/php_interface/init.php</b> - дополнительные параметры
 * <a href="/api_help/main/general/terms.php#portal">портала</a> </li>
 *
 * <li><b>/bitrix/php_interface/</b><i>ID сайта</i><b>/init.php</b> - дополнительные
 * параметры
 * <a href="/api_help/main/general/terms.php#site">сайта</a> </li>     </ul>
 * </li></ul>
 *
 * @example <pre>define("ERROR_EMAIL",        "admin@site.ru, support@site.ru");</pre>
 * @var string|bool
 */
define('ERROR_EMAIL', '');

/*
 * Хранит
 * <a href="/api_help/main/general/terms.php#abspath">абсолютный путь</a> к log-файлу,
 * используемого функцией
 * <a href="/api_help/main/functions/debug/addmessage2log.php">AddMessage2Log</a> для записи
 * ошибок или каких-либо сообщений.
 *
 * <b>ТИП:</b>
 * <ul>
 *
 * <li><b>Т</b> (с<b>т</b>раница) - константу можно определить на любой
 * <a href="/api_help/main/general/terms.php#public">публичной</a> странице до
 * подключения
 * <a href="/api_help/main/general/terms.php#prolog">пролога</a> </li>
 *
 * <li><b>И</b> (<b>и</b>нициализация) - константу можно определить в
 * одном из следующих файлов:
 * <ul>
 *
 * <li><b>/bitrix/php_interface/init.php</b> - дополнительные параметры
 * <a href="/api_help/main/general/terms.php#portal">портала</a> </li>
 *
 * <li><b>/bitrix/php_interface/</b><i>ID сайта</i><b>/init.php</b> - дополнительные
 * параметры
 * <a href="/api_help/main/general/terms.php#site">сайта</a> </li>     </ul>   </li>
 *
 * <li><b>С</b> (<b>с</b>оединение с базой) - константу можно определить
 * только в файле хранящим параметры соединения к базе:
 * <b>/bitrix/php_interface/dbconn.php</b>
 * </li></ul>
 *
 * @example <pre>define("LOG_FILENAME",        $_SERVER["DOCUMENT_ROOT"].           "/log.txt");</pre>
 * @var string|bool
 */
define('LOG_FILENAME', '');

/*
 * Как правило данная константа используется в редакции
 * "Веб-Аналитика". Если ее не инициализировать, то в публичной
 * части будет отсылаться HTTP заголовок:                Content-Type: text/html;
 * charset=<b>SITE_CHARSET</b>
 *
 * <b>ТИП:</b>
 * <ul>
 *
 * <li><b>Т</b> (с<b>т</b>раница) - константу можно определить на любой
 * <a href="/api_help/main/general/terms.php#public">публичной</a> странице до
 * подключения
 * <a href="/api_help/main/general/terms.php#prolog">пролога</a> </li>
 *
 * <li><b>И</b> (<b>и</b>нициализация) - константу можно определить в
 * одном из следующих файлов:
 * <ul>
 *
 * <li><b>/bitrix/php_interface/init.php</b> - дополнительные параметры
 * <a href="/api_help/main/general/terms.php#portal">портала</a> </li>
 *
 * <li><b>/bitrix/php_interface/</b><i>ID сайта</i><b>/init.php</b> - дополнительные
 * параметры
 * <a href="/api_help/main/general/terms.php#site">сайта</a> </li>     </ul>   </li>
 *
 * <li><b>С</b> (<b>с</b>оединение с базой) - константу можно определить
 * только в файле хранящим параметры соединения к базе:
 * <b>/bitrix/php_interface/dbconn.php</b>
 * </li></ul>
 *
 * @example <pre>define("STATISTIC_ONLY", true);</pre>
 * @var string|bool
 */
define('STATISTIC_ONLY', '');

/*
 * Если инициализировать данную константу каким либо
 * значением, то это запретит сбор статистики на данной
 * странице.
 *
 * <b>ТИП:</b>
 * <ul>
 *
 * <li><b>Т</b> (с<b>т</b>раница) - константу можно определить на любой
 * <a href="/api_help/main/general/terms.php#public">публичной</a> странице до
 * подключения
 * <a href="/api_help/main/general/terms.php#prolog">пролога</a> </li>
 *
 * <li><b>И</b> (<b>и</b>нициализация) - константу можно определить в
 * одном из следующих файлов:
 * <ul>
 *
 * <li><b>/bitrix/php_interface/init.php</b> - дополнительные параметры
 * <a href="/api_help/main/general/terms.php#portal">портала</a> </li>
 *
 * <li><b>/bitrix/php_interface/</b><i>ID сайта</i><b>/init.php</b> - дополнительные
 * параметры
 * <a href="/api_help/main/general/terms.php#site">сайта</a> </li>     </ul>   </li>
 *
 * <li><b>С</b> (<b>с</b>оединение с базой) - константу можно определить
 * только в файле хранящим параметры соединения к базе:
 * <b>/bitrix/php_interface/dbconn.php</b>
 * </li></ul>
 *
 * @example <pre>define("NO_KEEP_STATISTIC", true);</pre>
 * @var string|bool
 */
define('NO_KEEP_STATISTIC', '');

/*
 * Константа предназначена для отключения автоматического
 * сбора статистики, реализованного как вызов функции <code>
 * <a href="/api_help/statistic/classes/cstatistics/keep.php">CStatistics::Keep</a></code> в качестве
 * обработчика события
 * <a href="/api_help/main/events/onbeforeprolog.php">OnBeforeProlog</a>. Константу необходимо
 * инициализировать до подключения
 * <a href="/api_help/main/general/terms.php#prolog">пролога</a>. Затем, при
 * необходимости, можно использовать "ручной" сбор статистики,
 * вызвав функцию <code>CStatistics::Keep</code> (с первым параметром, равным
 * true).
 *
 * <b>ТИП:</b>
 * <ul>
 *
 * <li><b>Т</b> (с<b>т</b>раница) - константу можно определить на любой
 * <a href="/api_help/main/general/terms.php#public">публичной</a> странице до
 * подключения
 * <a href="/api_help/main/general/terms.php#prolog">пролога</a> </li>
 *
 * <li><b>И</b> (<b>и</b>нициализация) - константу можно определить в
 * одном из следующих файлов:
 * <ul>
 *
 * <li><b>/bitrix/php_interface/init.php</b> - дополнительные параметры
 * <a href="/api_help/main/general/terms.php#portal">портала</a> </li>
 *
 * <li><b>/bitrix/php_interface/</b><i>ID сайта</i><b>/init.php</b> - дополнительные
 * параметры
 * <a href="/api_help/main/general/terms.php#site">сайта</a> </li>     </ul>   </li>
 *
 * <li><b>С</b> (<b>с</b>оединение с базой) - константу можно определить
 * только в файле хранящим параметры соединения к базе:
 * <b>/bitrix/php_interface/dbconn.php</b>
 * </li></ul>
 *
 * @example <pre>&lt;?// отключим автоматический// сбор
 * статистикиdefine("STOP_STATISTICS", true);require($_SERVER["DOCUMENT_ROOT"].
 * "/bitrix/header.php");// включим сбор статистикиCStatistics::Keep(true);...</pre>
 * @var string|bool
 */
define('STOP_STATISTICS', '');

/*
 * Инициализация этой константы каким-либо значением приведет
 * к запрету следующих действий модуля "Статистика",
 * выполняемых ежедневно при помощи технологии
 * <a href="/api_help/main/general/technology/agents.php">агентов</a>:
 * <ul>           <li>перевод на новый день; </li>                   <li>очистка
 * устаревших данных статистики; </li>                   <li>отсылка
 * ежедневного статистического отчета. </li>         </ul>
 *
 * <b>ТИП:</b>
 * <ul>
 *
 * <li><b>Т</b> (с<b>т</b>раница) - константу можно определить на любой
 * <a href="/api_help/main/general/terms.php#public">публичной</a> странице до
 * подключения
 * <a href="/api_help/main/general/terms.php#prolog">пролога</a> </li>
 *
 * <li><b>И</b> (<b>и</b>нициализация) - константу можно определить в
 * одном из следующих файлов:
 * <ul>
 *
 * <li><b>/bitrix/php_interface/init.php</b> - дополнительные параметры
 * <a href="/api_help/main/general/terms.php#portal">портала</a> </li>
 *
 * <li><b>/bitrix/php_interface/</b><i>ID сайта</i><b>/init.php</b> - дополнительные
 * параметры
 * <a href="/api_help/main/general/terms.php#site">сайта</a> </li>     </ul>   </li>
 *
 * <li><b>С</b> (<b>с</b>оединение с базой) - константу можно определить
 * только в файле хранящим параметры соединения к базе:
 * <b>/bitrix/php_interface/dbconn.php</b>
 * </li></ul>
 *
 * @example <pre>define("NO_AGENT_STATISTIC", true);</pre>
 * @var string|bool
 */
define('NO_AGENT_STATISTIC', '');

/*
 * При установке в <b>true</b> отключает выполнение всех агентов
 *   <p>
 *
 * <b>ТИП:</b>
 * <ul>
 *
 * <li><b>Т</b> (с<b>т</b>раница) - константу можно определить на любой
 * <a href="/api_help/main/general/terms.php#public">публичной</a> странице до
 * подключения
 * <a href="/api_help/main/general/terms.php#prolog">пролога</a> </li>
 *
 * <li><b>И</b> (<b>и</b>нициализация) - константу можно определить в
 * одном из следующих файлов:
 * <ul>
 *
 * <li><b>/bitrix/php_interface/init.php</b> - дополнительные параметры
 * <a href="/api_help/main/general/terms.php#portal">портала</a> </li>
 *
 * <li><b>/bitrix/php_interface/</b><i>ID сайта</i><b>/init.php</b> - дополнительные
 * параметры
 * <a href="/api_help/main/general/terms.php#site">сайта</a> </li>     </ul>   </li>
 *
 * <li><b>С</b> (<b>с</b>оединение с базой) - константу можно определить
 * только в файле хранящим параметры соединения к базе:
 * <b>/bitrix/php_interface/dbconn.php</b>
 * </li></ul>
 *
 * @example </p>               <pre>define("NO_AGENT_CHECK", true);</pre>
 * @var string|bool
 */
define('NO_AGENT_CHECK', '');

/*
 * Если инициализировать данную константу значением "true" до
 * подключения
 * <a href="/api_help/main/general/terms.php#prolog">пролога</a>, то это отключит
 * проверку прав
 * <a href="/api_help/main/general/permissions.php#level1">первого уровня</a>.
 *
 *
 * <b>ТИП:</b>
 * <ul>
 *
 * <li><b>Т</b> (с<b>т</b>раница) - константу можно определить на любой
 * <a href="/api_help/main/general/terms.php#public">публичной</a> странице до
 * подключения
 * <a href="/api_help/main/general/terms.php#prolog">пролога</a> </li>
 *
 * <li><b>И</b> (<b>и</b>нициализация) - константу можно определить в
 * одном из следующих файлов:
 * <ul>
 *
 * <li><b>/bitrix/php_interface/init.php</b> - дополнительные параметры
 * <a href="/api_help/main/general/terms.php#portal">портала</a> </li>
 *
 * <li><b>/bitrix/php_interface/</b><i>ID сайта</i><b>/init.php</b> - дополнительные
 * параметры
 * <a href="/api_help/main/general/terms.php#site">сайта</a> </li>     </ul>   </li>
 *
 * <li><b>С</b> (<b>с</b>оединение с базой) - константу можно определить
 * только в файле хранящим параметры соединения к базе:
 * <b>/bitrix/php_interface/dbconn.php</b>
 * </li></ul>
 *
 * @example <pre>define("NOT_CHECK_PERMISSIONS", true);</pre>
 * @var string|bool
 */
define('NOT_CHECK_PERMISSIONS', '');

/*
 * Если на странице задана константа ONLY_EMAIL и email из настроек
 * почтового шаблона с ее значением не совпадает, то письмо не
 * отсылать. То есть отсылка письма будет происходить только в
 * том случае если значение данной константы будет
 * соответствовать адресу отправителя в настройках шаблона.
 *
 *
 * <b>ТИП:</b>
 * <ul>
 *
 * <li><b>Т</b> (с<b>т</b>раница) - константу можно определить на любой
 * <a href="/api_help/main/general/terms.php#public">публичной</a> странице до
 * подключения
 * <a href="/api_help/main/general/terms.php#prolog">пролога</a> </li>
 *
 * <li><b>И</b> (<b>и</b>нициализация) - константу можно определить в
 * одном из следующих файлов:
 * <ul>
 *
 * <li><b>/bitrix/php_interface/init.php</b> - дополнительные параметры
 * <a href="/api_help/main/general/terms.php#portal">портала</a> </li>
 *
 * <li><b>/bitrix/php_interface/</b><i>ID сайта</i><b>/init.php</b> - дополнительные
 * параметры
 * <a href="/api_help/main/general/terms.php#site">сайта</a> </li>     </ul>   </li>
 *
 * <li><b>С</b> (<b>с</b>оединение с базой) - константу можно определить
 * только в файле хранящим параметры соединения к базе:
 * <b>/bitrix/php_interface/dbconn.php</b>
 * </li></ul>
 *
 * @example <pre>define("ONLY_EMAIL", "admin@site.ru");</pre>
 * @var string|bool
 */
define('ONLY_EMAIL', '');

/*
 * Если данная константа инициализирована значением "true", то
 * <a href="/api_help/main/reference/cagent/checkagents.php">функция проверки агентов на
 * запуск</a> будет отбирать только те агенты для которых не
 * критично количество их запусков (т.е. при
 * <a href="/api_help/main/reference/cagent/addagent.php">добавлении</a> этого агента
 * параметр <i>period</i>=N). Как правило данная константа
 * используется для организации запуска агентов на
 * <a href="/api_help/main/general/terms.php#cron">cron'е</a>.
 *
 * <b>ТИП:</b>
 * <ul>
 *
 * <li><b>Т</b> (с<b>т</b>раница) - константу можно определить на любой
 * <a href="/api_help/main/general/terms.php#public">публичной</a> странице до
 * подключения
 * <a href="/api_help/main/general/terms.php#prolog">пролога</a> </li>
 *
 * <li><b>И</b> (<b>и</b>нициализация) - константу можно определить в
 * одном из следующих файлов:
 * <ul>
 *
 * <li><b>/bitrix/php_interface/init.php</b> - дополнительные параметры
 * <a href="/api_help/main/general/terms.php#portal">портала</a> </li>
 *
 * <li><b>/bitrix/php_interface/</b><i>ID сайта</i><b>/init.php</b> - дополнительные
 * параметры
 * <a href="/api_help/main/general/terms.php#site">сайта</a> </li>     </ul>   </li>
 *
 * <li><b>С</b> (<b>с</b>оединение с базой) - константу можно определить
 * только в файле хранящим параметры соединения к базе:
 * <b>/bitrix/php_interface/dbconn.php</b>
 * </li></ul>
 *
 * @example <pre>define("BX_CRONTAB", true);</pre>
 * @var string|bool
 */
define('BX_CRONTAB', '');

/*
 *
 * <a href="/api_help/main/general/terms.php#unixpermissions">Unix-права</a> для вновь
 * создаваемых файлов.
 *
 * <b>ТИП:</b>
 * <ul>
 *
 * <li><b>Т</b> (с<b>т</b>раница) - константу можно определить на любой
 * <a href="/api_help/main/general/terms.php#public">публичной</a> странице до
 * подключения
 * <a href="/api_help/main/general/terms.php#prolog">пролога</a> </li>
 *
 * <li><b>И</b> (<b>и</b>нициализация) - константу можно определить в
 * одном из следующих файлов:
 * <ul>
 *
 * <li><b>/bitrix/php_interface/init.php</b> - дополнительные параметры
 * <a href="/api_help/main/general/terms.php#portal">портала</a> </li>
 *
 * <li><b>/bitrix/php_interface/</b><i>ID сайта</i><b>/init.php</b> - дополнительные
 * параметры
 * <a href="/api_help/main/general/terms.php#site">сайта</a> </li>     </ul>   </li>
 *
 * <li><b>С</b> (<b>с</b>оединение с базой) - константу можно определить
 * только в файле хранящим параметры соединения к базе:
 * <b>/bitrix/php_interface/dbconn.php</b>
 * </li></ul>
 *
 * @example <pre>define("BX_FILE_PERMISSIONS", 0755);</pre>
 * @var string|bool
 */
define('BX_FILE_PERMISSIONS', '');

/*
 *
 * <a href="/api_help/main/general/terms.php#unixpermissions">Unix-права</a> для вновь
 * создаваемых каталогов.
 *
 * <b>ТИП:</b>
 * <ul>
 *
 * <li><b>Т</b> (с<b>т</b>раница) - константу можно определить на любой
 * <a href="/api_help/main/general/terms.php#public">публичной</a> странице до
 * подключения
 * <a href="/api_help/main/general/terms.php#prolog">пролога</a> </li>
 *
 * <li><b>И</b> (<b>и</b>нициализация) - константу можно определить в
 * одном из следующих файлов:
 * <ul>
 *
 * <li><b>/bitrix/php_interface/init.php</b> - дополнительные параметры
 * <a href="/api_help/main/general/terms.php#portal">портала</a> </li>
 *
 * <li><b>/bitrix/php_interface/</b><i>ID сайта</i><b>/init.php</b> - дополнительные
 * параметры
 * <a href="/api_help/main/general/terms.php#site">сайта</a> </li>     </ul>   </li>
 *
 * <li><b>С</b> (<b>с</b>оединение с базой) - константу можно определить
 * только в файле хранящим параметры соединения к базе:
 * <b>/bitrix/php_interface/dbconn.php</b>
 * </li></ul>
 *
 * @example <pre>define("BX_DIR_PERMISSIONS", 0755);</pre>
 * @var string|bool
 */
define('BX_DIR_PERMISSIONS', '');

/*
 * Инициализация данной константы значением "true" позволит
 * отключить все модули системы за исключением главного и
 * модуля "
 * <a href="../../../../../fileman/help/ru/index.php.html">Управление структурой</a>".
 *
 *
 * <b>ТИП:</b>
 * <ul>
 *
 * <li><b>Т</b> (с<b>т</b>раница) - константу можно определить на любой
 * <a href="/api_help/main/general/terms.php#public">публичной</a> странице до
 * подключения
 * <a href="/api_help/main/general/terms.php#prolog">пролога</a> </li>
 *
 * <li><b>И</b> (<b>и</b>нициализация) - константу можно определить в
 * одном из следующих файлов:
 * <ul>
 *
 * <li><b>/bitrix/php_interface/init.php</b> - дополнительные параметры
 * <a href="/api_help/main/general/terms.php#portal">портала</a> </li>
 *
 * <li><b>/bitrix/php_interface/</b><i>ID сайта</i><b>/init.php</b> - дополнительные
 * параметры
 * <a href="/api_help/main/general/terms.php#site">сайта</a> </li>     </ul>   </li>
 *
 * <li><b>С</b> (<b>с</b>оединение с базой) - константу можно определить
 * только в файле хранящим параметры соединения к базе:
 * <b>/bitrix/php_interface/dbconn.php</b>
 * </li></ul>
 *
 * @example <pre>define("SM_SAFE_MODE", true);</pre>
 * @var string|bool
 */
define('SM_SAFE_MODE', '');

/*
 * Данная константа используется в функции
 * <a href="/api_help/main/functions/file/getdirindex.php">GetDirIndex</a> для определения
 * <a href="/api_help/main/general/terms.php#index">индексного файла</a> каталога.
 *
 *
 * <b>ТИП:</b>
 * <ul>
 *
 * <li><b>Т</b> (с<b>т</b>раница) - константу можно определить на любой
 * <a href="/api_help/main/general/terms.php#public">публичной</a> странице до
 * подключения
 * <a href="/api_help/main/general/terms.php#prolog">пролога</a> </li>
 *
 * <li><b>И</b> (<b>и</b>нициализация) - константу можно определить в
 * одном из следующих файлов:
 * <ul>
 *
 * <li><b>/bitrix/php_interface/init.php</b> - дополнительные параметры
 * <a href="/api_help/main/general/terms.php#portal">портала</a> </li>
 *
 * <li><b>/bitrix/php_interface/</b><i>ID сайта</i><b>/init.php</b> - дополнительные
 * параметры
 * <a href="/api_help/main/general/terms.php#site">сайта</a> </li>     </ul>   </li>
 *
 * <li><b>С</b> (<b>с</b>оединение с базой) - константу можно определить
 * только в файле хранящим параметры соединения к базе:
 * <b>/bitrix/php_interface/dbconn.php</b>
 * </li></ul>
 *
 * @example <pre>define("DIRECTORY_INDEX", "    index.php     index.html     index.htm     index.phtml
 * default.html     index.php3");</pre>
 * @var string|bool
 */
define('DIRECTORY_INDEX', '');

/*
 * Значение данной константы содержит тип таблиц создаваемый
 * в MySQL по умолчанию: "MyISAM" или "InnoDB".
 *
 * <b>ТИП:</b>
 * <ul>
 *
 * <li><b>С</b> (<b>с</b>оединение с базой) - константу можно определить
 * только в файле хранящим параметры соединения к базе:
 * <b>/bitrix/php_interface/dbconn.php</b>
 * </li></ul>
 *
 * @example <pre>define("MYSQL_TABLE_TYPE", "InnoDB");</pre>
 * @var string|bool
 */
define('MYSQL_TABLE_TYPE', '');

/*
 * Если данная константа инициализирована значением "true", то
 * будет создаваться
 * <a href="/api_help/main/general/terms.php#persistent">постоянное соединение</a> с
 * базой.
 *
 * <b>ТИП:</b>
 * <ul>
 *
 * <li><b>С</b> (<b>с</b>оединение с базой) - константу можно определить
 * только в файле хранящим параметры соединения к базе:
 * <b>/bitrix/php_interface/dbconn.php</b>
 * </li></ul>
 *
 * @example <pre>define("DBPersistent", true);</pre>
 * @var string|bool
 */
define('DBPersistent', '');

/*
 * Может принимать значение true/false. Константа регулирует
 * значение по умолчанию для параметра get_index_page функций
 * GetPagePath(), CMain::GetCurPage(), CMain::GetCurPageParam(). Параметр get_index_page
 * указывает, нужно ли для индексной страницы раздела
 * возвращать путь, заканчивающийся на "index.php". Если значение
 * параметра равно true, то возвращается путь с "index.php", иначе -
 * путь, заканчивающийся на "/". Параметр имеет значение,
 * <i>обратное</i> значению константы.
 *
 * <b>ТИП:</b>
 * <ul>
 *
 * <li><b>С</b> (<b>с</b>оединение с базой) - константу можно определить
 * только в файле хранящим параметры соединения к базе:
 * <b>/bitrix/php_interface/dbconn.php</b>
 * </li></ul>
 * @var string|bool
 */
define('BX_DISABLE_INDEX_PAGE', '');

/*
 * Отладка кода на vue.js прописать в /bitrix/php_interface/init.php
 * @see https://dev.1c-bitrix.ru/learning/course/index.php?COURSE_ID=43&LESSON_ID=11885
 */
define('VUEJS_DEBUG', true);

// включить механизм тегированного кеширования
define('BX_COMP_MANAGED_CACHE', true);

// some addons cache config
define('CACHED_b_iblock_type', 3_600 * 24);
define('CACHED_b_iblock', 3_600 * 24);
define('CACHED_b_iblock_bucket_size', 40);
define('CACHED_b_iblock_property_enum', 3_600 * 24);
define('CACHED_b_iblock_property_enum_bucket_size', 100);
define('CACHED_b_user_access_check', 3_600);

define('CATALOG_CACHE_TIME', 3_600 * 24);
define('CACHED_b_user_field', 3_600 * 24 * 3);
define('CACHED_b_user_field_enum', 3_600 * 24 * 3);
define('CATALOG_CACHE_DEFAULT_TIME', 3_600 * 24);
define('CACHED_b_group', 3_600 * 3);
define('CACHED_b_group_subordinate', 3_600 * 3);
define('CACHE_MODULE_PERMISSIONS', 'SESSION');
define('CACHED_b_sale_order', 3_600);
define('CURRENCY_SKIP_CACHE', false);
define('CURRENCY_CACHE_TIME', 3_600);

define('TIMELIMIT_EDITION', 'Y');
define('DEMO', 'Y');
define('ENCODE', 'Y');
define('WIZARD_DEFAULT_TONLY', 'Y');
define('INSTALL_CHARSET', 'utf8');
