<?php
// options.php -- HotCRP options for the all-in-one Docker image
//
// Installed as conf/options.php inside the image. Configuration comes from
// the container environment (see docker/README.md). For anything else, put
// PHP files that set `$Opt[...]` in `/data/conf/` on the data volume; they
// are loaded after this file. In multiconference mode, the options in
// `/data/conferences/CONFID.php` are loaded last, for that conference only.
// `etc/distoptions.php` lists every option.

global $Opt;

$Opt["dbUser"] = "hotcrp";
$Opt["dbPassword"] = trim((string) @file_get_contents("/data/secrets/dbpassword"));
$Opt["dbHost"] = "localhost";

if (filter_var(getenv("HOTCRP_MULTICONFERENCE") ?: "", FILTER_VALIDATE_BOOLEAN)) {
    // Each conference is served at /CONFID/, uses database CONFID, and is
    // defined by /data/conferences/CONFID.php (see hotcrp-add-conference)
    $Opt["multiconference"] = true;
    $Opt["dbName"] = '${confid}';
    require_once("/usr/local/lib/hotcrp/multiconference.php");
    $Opt["multiconferenceFailureCallback"] = "hotcrp_docker_multiconference_failure";
} else {
    $Opt["dbName"] = "hotcrp";
}

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
    if ($Opt["multiconference"] ?? false) {
        // Names belong to each conference (its Settings page or its file in
        // /data/conferences), not to every conference
        unset($Opt["shortName"], $Opt["longName"]);
        if (isset($Opt["paperSite"])) {
            $Opt["paperSite"] = rtrim($Opt["paperSite"], "/") . '/${confid}/';
        }
    }
    if (!isset($Opt["emailFrom"]) && isset($Opt["contactEmail"])) {
        $Opt["emailFrom"] = $Opt["contactEmail"];
    }
    // Mail is sent only if an SMTP relay is configured
    $Opt["sendEmail"] = $env("SMTP_HOST") !== null;
})();

// Options for every conference, then (multiconference mode) for this one
$Opt["include"] = ["/data/conf/*.php"];
if ($Opt["multiconference"] ?? false) {
    $Opt["include"][] = '/data/conferences/${confid}.php';
}
