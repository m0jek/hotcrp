<?php
// options.php -- HotCRP options for the all-in-one Docker image
//
// Installed as conf/options.php inside the image. Configuration comes from
// the container environment (see docker/README.md). For anything else, put
// PHP files that set `$Opt[...]` in `/data/conf/` on the data volume; they
// are loaded after this file. `etc/distoptions.php` lists every option.

global $Opt;

$Opt["dbName"] = "hotcrp";
$Opt["dbUser"] = "hotcrp";
$Opt["dbPassword"] = trim((string) @file_get_contents("/data/secrets/dbpassword"));
$Opt["dbHost"] = "localhost";

(function () {
    global $Opt;
    $env = function ($name) {
        $v = getenv($name);
        return $v === false || $v === "" ? null : $v;
    };
    foreach (["HOTCRP_SHORT_NAME" => "shortName",
              "HOTCRP_LONG_NAME" => "longName",
              "HOTCRP_PAPER_SITE" => "paperSite",
              "HOTCRP_CONTACT_NAME" => "contactName",
              "HOTCRP_CONTACT_EMAIL" => "contactEmail",
              "HOTCRP_EMAIL_FROM" => "emailFrom",
              "HOTCRP_EMAIL_SENDER" => "emailSender",
              "TZ" => "timezone"] as $e => $o) {
        if (($v = $env($e)) !== null) {
            $Opt[$o] = $v;
        }
    }
    foreach (["HOTCRP_DISABLE_NEW_USERS" => "disableNewUsers"] as $e => $o) {
        if (($v = $env($e)) !== null) {
            $Opt[$o] = filter_var($v, FILTER_VALIDATE_BOOLEAN);
        }
    }
    if (!isset($Opt["emailFrom"]) && isset($Opt["contactEmail"])) {
        $Opt["emailFrom"] = $Opt["contactEmail"];
    }
    // Mail is sent only if an SMTP relay is configured
    $Opt["sendEmail"] = $env("SMTP_HOST") !== null;
})();

$Opt["include"] = "/data/conf/*.php";
