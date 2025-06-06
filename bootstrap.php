<?php

declare(strict_types=1);
use Chandler\Database\DatabaseConnection;
use Chandler\Session\Session;
use openvk\Web\Util\Localizator;
use openvk\Web\Util\Bitmask;

use function PHP81_BC\strftime;

function _ovk_check_environment(): void
{
    $problems = [];
    if (file_exists(__DIR__ . "/update.pid")) {
        $problems[] = "OpenVK is updating";
    }

    if (!version_compare(PHP_VERSION, "7.3.0", ">=")) {
        $problems[] = "Incompatible PHP version: " . PHP_VERSION . " (7.3+ required, 7.4+ recommended)";
    }

    if (!is_dir(__DIR__ . "/vendor")) {
        $problems[] = "Composer dependencies missing";
    }

    $requiredExtensions = [
        "gd",
        "imagick",
        "fileinfo",
        "PDO",
        "pdo_mysql",
        "pdo_sqlite",
        "pcre",
        "hash",
        "curl",
        "Core",
        "iconv",
        "mbstring",
        "sodium",
        "openssl",
        "json",
        "tokenizer",
        "xml",
        "intl",
        "date",
        "session",
        "SPL",
    ];
    if (sizeof($missingExtensions = array_diff($requiredExtensions, get_loaded_extensions())) > 0) {
        foreach ($missingExtensions as $extension) {
            $problems[] = "Missing extension $extension";
        }
    }

    if (sizeof($problems) > 0) {
        require __DIR__ . "/misc/install_err.phtml";
        exit;
    }
}

function ovkGetQuirk(string $quirk): int
{
    static $quirks = null;
    if (!$quirks) {
        $quirks = chandler_parse_yaml(__DIR__ . "/quirks.yml");
    }

    return !is_null($v = $quirks[$quirk]) ? (int) $v : 0;
}

function ovk_proc_strtr(string $string, int $length = 0): string
{
    $newString = iconv_substr($string, 0, $length);

    return $newString . ($string !== $newString ? "…" : ""); #if cut hasn't happened, don't append "..."
}

function knuth_shuffle(iterable $arr, int $seed): array
{
    $data   = is_array($arr) ? $arr : iterator_to_array($arr);
    $retVal = [];
    $ind    = [];
    $count  = sizeof($data);

    srand($seed, MT_RAND_PHP);

    for ($i = 0; $i < $count; ++$i) {
        $ind[$i] = 0;
    }

    for ($i = 0; $i < $count; ++$i) {
        do {
            $index = rand() % $count;
        } while ($ind[$index] != 0);

        $ind[$index] = 1;
        $retVal[$i] = $data[$index];
    }

    # Reseed
    srand(hexdec(bin2hex(openssl_random_pseudo_bytes(4))));

    return $retVal;
}

function bmask(int $input, array $options = []): Bitmask
{
    return new Bitmask($input, $options["length"] ?? 1, $options["mappings"] ?? []);
}

function tr(string $stringId, ...$variables): string
{
    $localizer = Localizator::i();
    $lang      = Session::i()->get("lang", "ru");
    if ($stringId === "__lang") {
        return $lang;
    }

    $output = $localizer->_($stringId, $lang);
    if (sizeof($variables) > 0) {
        if (gettype($variables[0]) === "integer") {
            $numberedStringId = null;
            $cardinal         = $variables[0];
            switch ($cardinal) {
                case 0:
                    $numberedStringId = $stringId . "_zero";
                    break;
                case 1:
                    $numberedStringId = $stringId . "_one";
                    break;
                default:
                    $numberedStringId = $stringId . ($cardinal < 5 ? "_few" : "_other");
            }

            $newOutput = $localizer->_($numberedStringId, $lang);
            if ($newOutput === "@$numberedStringId") {
                $newOutput = $localizer->_($stringId . "_other", $lang);
                if ($newOutput === ("@" . $stringId . "_other")) {
                    $newOutput = $output;
                }
            }

            $output = $newOutput;
        }

        for ($i = 0; $i < sizeof($variables); $i++) {
            $output = preg_replace("%(?<!\\\\)(\\$)" . ($i + 1) . "%", (string) $variables[$i], $output);
        }
    }

    return $output;
}

function setLanguage($lg): void
{
    if (isLanguageAvailable($lg)) {
        Session::i()->set("lang", $lg);
    } else {
        trigger_error("The language '$lg' is not available", E_USER_NOTICE);
    }
}

function getLanguage(): string
{
    return Session::i()->get("lang", "ru");
}

function getLanguages(): array
{
    return chandler_parse_yaml(OPENVK_ROOT . "/locales/list.yml")['list'];
}

function isLanguageAvailable($lg): bool
{
    $lg_temp = false;
    foreach (getLanguages() as $lang) {
        if ($lang['code'] == $lg) {
            $lg_temp = true;
        }
    }
    return $lg_temp;
}

function getBrowsersLanguage(): array
{
    if ($_SERVER['HTTP_ACCEPT_LANGUAGE'] != null) {
        return mb_split(",", mb_split(";", $_SERVER['HTTP_ACCEPT_LANGUAGE'])[0]);
    } else {
        return [];
    }
}

function eventdb(): ?DatabaseConnection
{
    $conf = OPENVK_ROOT_CONF["openvk"]["credentials"]["eventDB"];
    if (!$conf["enable"]) {
        return null;
    }

    $db = (object) $conf["database"];
    return DatabaseConnection::connect([
        "dsn"      => $db->dsn,
        "user"     => $db->user,
        "password" => $db->password,
        "caching"  => [
            "folder" => __DIR__ . "/tmp",
        ],
    ]);
}

#NOTICE: invalid name, kept for compatability
function ovk_proc_strtrim(string $string, int $length = 0): string
{
    trigger_error("ovk_proc_strtrim is deprecated, please use fully compatible ovk_proc_strtr.", E_USER_DEPRECATED);

    return ovk_proc_strtr($string, $length);
}

function ovk_strftime_safe(string $format, ?int $timestamp = null): string
{
    $sessionOffset = intval(Session::i()->get("_timezoneOffset"));
    $str = strftime($format, $timestamp + ($sessionOffset * MINUTE) * -1 ?? time() + ($sessionOffset * MINUTE) * -1, tr("__locale") !== '@__locale' ? tr("__locale") : null);
    if (PHP_SHLIB_SUFFIX === "dll" && version_compare(PHP_VERSION, "8.1.0", "<")) {
        $enc = tr("__WinEncoding");
        if ($enc === "@__WinEncoding") {
            $enc = "Windows-1251";
        }

        $nStr = iconv($enc, "UTF-8", $str);
        if (!is_null($nStr)) {
            $str = $nStr;
        }
    }

    return $str;
}

function ovk_is_ssl(): bool
{
    if (!isset($GLOBALS["requestIsSSL"])) {
        $GLOBALS["requestIsSSL"] = false;

        if (isset($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") {
            $GLOBALS["requestIsSSL"] = true;
        } else {
            $forwardedProto = $_SERVER["HTTP_X_FORWARDED_PROTO"] ?? ($_SERVER["HTTP_X_FORWARDED_PROTOCOL"] ?? ($_SERVER["HTTP_X_URL_SCHEME"] ?? ""));
            if ($forwardedProto === "https") {
                $GLOBALS["requestIsSSL"] = true;
            } elseif (($_SERVER["HTTP_X_FORWARDED_SSL"] ?? "") === "on") {
                $GLOBALS["requestIsSSL"] = true;
            }
        }
    }

    return $GLOBALS["requestIsSSL"];
}

function parseAttachments($attachments, array $allow_types = ['photo', 'video', 'note', 'audio']): array
{
    $exploded_attachments = is_array($attachments) ? $attachments : explode(",", $attachments);
    $exploded_attachments = array_slice($exploded_attachments, 0, OPENVK_ROOT_CONF["openvk"]["preferences"]["wall"]["postSizes"]["maxAttachments"] ?? 10);
    $exploded_attachments = array_unique($exploded_attachments);
    $imploded_types = implode('|', $allow_types);
    $output_attachments = [];
    $repositories = [
        'photo' => [
            'repo'   => 'openvk\Web\Models\Repositories\Photos',
            'method' => 'getByOwnerAndVID',
        ],
        'video' => [
            'repo' => 'openvk\Web\Models\Repositories\Videos',
            'method' => 'getByOwnerAndVID',
        ],
        'audio' => [
            'repo' => 'openvk\Web\Models\Repositories\Audios',
            'method' => 'getByOwnerAndVID',
        ],
        'note'  => [
            'repo' => 'openvk\Web\Models\Repositories\Notes',
            'method' => 'getNoteById',
        ],
        'poll'  => [
            'repo' => 'openvk\Web\Models\Repositories\Polls',
            'method' => 'get',
            'onlyId' => true,
        ],
        'doc'  => [
            'repo' => 'openvk\Web\Models\Repositories\Documents',
            'method' => 'getDocumentById',
            'withKey' => true,
        ],
    ];

    foreach ($exploded_attachments as $attachment_string) {
        if (preg_match("/$imploded_types/", $attachment_string, $matches) == 1) {
            try {
                $attachment_type = $matches[0];
                if (!$repositories[$attachment_type]) {
                    continue;
                }

                $attachment_ids  = str_replace($attachment_type, '', $attachment_string);
                if ($repositories[$attachment_type]['onlyId']) {
                    [$attachment_id] = array_map('intval', explode('_', $attachment_ids));

                    $repository_class = $repositories[$attachment_type]['repo'];
                    if (!$repository_class) {
                        continue;
                    }
                    $attachment_model = (new $repository_class())->{$repositories[$attachment_type]['method']}($attachment_id);
                    $output_attachments[] = $attachment_model;
                } elseif ($repositories[$attachment_type]['withKey']) {
                    [$attachment_owner, $attachment_id, $access_key] = explode('_', $attachment_ids);

                    $repository_class = $repositories[$attachment_type]['repo'];
                    if (!$repository_class) {
                        continue;
                    }
                    $attachment_model = (new $repository_class())->{$repositories[$attachment_type]['method']}((int) $attachment_owner, (int) $attachment_id, $access_key);

                    $output_attachments[] = $attachment_model;
                } else {
                    [$attachment_owner, $attachment_id] = array_map('intval', explode('_', $attachment_ids));

                    $repository_class = $repositories[$attachment_type]['repo'];
                    if (!$repository_class) {
                        continue;
                    }
                    $attachment_model = (new $repository_class())->{$repositories[$attachment_type]['method']}($attachment_owner, $attachment_id);
                    $output_attachments[] = $attachment_model;
                }
            } catch (\Throwable) {
                continue;
            }
        }
    }

    return $output_attachments;
}

function get_entity_by_id(int $id)
{
    if ($id > 0) {
        return (new openvk\Web\Models\Repositories\Users())->get($id);
    }

    return (new openvk\Web\Models\Repositories\Clubs())->get(abs($id));
}

function get_entities(array $ids = []): array
{
    $main_result = [];
    $users = [];
    $clubs = [];
    foreach ($ids as $id) {
        $id = (int) $id;
        if ($id < 0) {
            $clubs[] = abs($id);
        }

        if ($id > 0) {
            $users[] = $id;
        }
    }

    if (sizeof($users) > 0) {
        $users_tmp = (new openvk\Web\Models\Repositories\Users())->getByIds($users);
        foreach ($users_tmp as $user) {
            $main_result[] = $user;
        }
    }

    if (sizeof($clubs) > 0) {
        $clubs_tmp = (new openvk\Web\Models\Repositories\Clubs())->getByIds($clubs);
        foreach ($clubs_tmp as $club) {
            $main_result[] = $club;
        }
    }

    return $main_result;
}

function ovk_scheme(bool $with_slashes = false): string
{
    $scheme = ovk_is_ssl() ? "https" : "http";
    if ($with_slashes) {
        $scheme .= "://";
    }

    return $scheme;
}

function check_copyright_link(string $link = ''): bool
{
    if (!str_contains($link, "https://") && !str_contains($link, "http://")) {
        $link = "https://" . $link;
    }

    # Existability
    if (is_null($link) || empty($link)) {
        throw new \InvalidArgumentException("Empty link");
    }

    # Length
    if (iconv_strlen($link) < 2 || iconv_strlen($link) > 400) {
        throw new \LengthException("Link is too long");
    }

    # Match URL regex
    # stolen from http://urlregex.com/
    if (!preg_match("%^(?:(?:https?|ftp)://)(?:\S+(?::\S*)?@|\d{1,3}(?:\.\d{1,3}){3}|(?:(?:[a-z\d\x{00a1}-\x{ffff}]+-?)*[a-z\d\x{00a1}-\x{ffff}]+|xn--[a-z\d-]+)(?:\.(?:[a-z\d\x{00a1}-\x{ffff}]+-?)*[a-z\d\x{00a1}-\x{ffff}]+)*(?:\.(?:xn--[a-z\d-]+|[a-z\x{00a1}-\x{ffff}]{2,6})))(?::\d+)?(?:[^\s]*)?$%iu", $link)) {
        throw new \InvalidArgumentException("Invalid link format");
    }

    $banEntries = (new openvk\Web\Models\Repositories\BannedLinks())->check($link);
    if (sizeof($banEntries) > 0) {
        throw new \LogicException("Suspicious link");
    }

    return true;
}

function escape_html(string $unsafe): string
{
    return htmlspecialchars($unsafe, ENT_DISALLOWED | ENT_XHTML);
}

function readable_filesize($bytes, $precision = 2): string
{
    $units = ['B', 'Kb', 'Mb', 'Gb', 'Tb', 'Pb'];

    $bytes = max($bytes, 0);
    $power = $bytes > 0 ? floor(log($bytes, 1024)) : 0;
    $power = min($power, count($units) - 1);
    $bytes /= pow(1024, $power);

    return round($bytes, $precision) . $units[$power];
}

function downloadable_name(string $text): string
{
    return preg_replace('/[\\/:*?"<>|]/', '_', str_replace(' ', '_', $text));
}

return (function () {
    if (php_sapi_name() != "cli") {
        _ovk_check_environment();
    }

    require __DIR__ . "/vendor/autoload.php";

    setlocale(LC_TIME, "POSIX");

    # TODO: Default language in config
    if (Session::i()->get("lang") == null) {
        $languages = array_reverse(getBrowsersLanguage());
        foreach ($languages as $lg) {
            if (isLanguageAvailable($lg)) {
                setLanguage($lg);
            }
        }
    }

    if (empty($_SERVER["REQUEST_SCHEME"])) {
        $_SERVER["REQUEST_SCHEME"] = empty($_SERVER["HTTPS"]) ? "HTTP" : "HTTPS";
    }

    $showCommitHash = true; # plz remove when release
    if (is_dir($gitDir = OPENVK_ROOT . "/.git") && $showCommitHash) {
        $ver = trim(`git --git-dir="$gitDir" log --pretty="%h" -n1 HEAD` ?? "Unknown version") . "-nightly";
    } else {
        $ver = "Public Technical Preview 4";
    }

    # Unix time constants
    define('MINUTE', 60);
    define('HOUR', 60 * MINUTE);
    define('DAY', 24 * HOUR);
    define('WEEK', 7 * DAY);
    define('MONTH', 30 * DAY);
    define('YEAR', 365 * DAY);

    define("nullptr", null);
    define("OPENVK_DEFAULT_INSTANCE_NAME", "OpenVK");
    define("OPENVK_VERSION", "Altair Preview ($ver)");
    define("OPENVK_DEFAULT_PER_PAGE", 10);
    define("__OPENVK_ERROR_CLOCK_IN_FUTURE", "Server clock error: FK1200-DTF");
});
