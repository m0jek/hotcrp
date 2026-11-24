<?php
// multiconference.php -- multiconference support for the all-in-one Docker
// image. HotCRP calls `hotcrp_docker_multiconference_failure` (via
// `$Opt["multiconferenceFailureCallback"]`) when a request does not name an
// existing conference, for instance a request for `/`.

/** @return list<string> */
function hotcrp_docker_conferences() {
    $ids = [];
    foreach (glob("/data/conferences/*.php") ?: [] as $f) {
        $id = basename($f, ".php");
        if (preg_match('/\A[a-z0-9][a-z0-9_-]*\z/', $id)) {
            $ids[] = $id;
        }
    }
    sort($ids);
    return $ids;
}

/** Return a conference's short name, long name, and list date (a Unix
 * timestamp or null).
 * @param string $id
 * @return array{string,?string,?int} */
function hotcrp_docker_conference_info($id, ?mysqli $db) {
    // Names set on the Settings page, and the submission deadline, are
    // stored in the database ...
    $short = $long = $deadline = null;
    if ($db) {
        try {
            $result = $db->query("select name, value, data from `{$id}`.Settings where name in ('opt.shortName', 'opt.longName', 'sub_sub')");
            while (($row = $result->fetch_row())) {
                if ($row[0] === "opt.shortName") {
                    $short = $row[2];
                } else if ($row[0] === "opt.longName") {
                    $long = $row[2];
                } else if ((int) $row[1] > 0) {
                    $deadline = (int) $row[1];
                }
            }
        } catch (Throwable $e) {
        }
    }
    // ... and the conference's options file supplies default names and an
    // optional `conferenceListDate`, which overrides the deadline
    $read = function ($file) {
        $Opt = [];
        @include $file;
        return $Opt;
    };
    $opt = $read("/data/conferences/{$id}.php");
    $short = $short ?? $opt["shortName"] ?? $id;
    $long = $long ?? $opt["longName"] ?? null;
    $date = $deadline;
    $ld = $opt["conferenceListDate"] ?? null;
    if (is_int($ld)) {
        $date = $ld;
    } else if (is_string($ld) && ($t = strtotime($ld)) !== false) {
        $date = $t;
    }
    return [$short, $long, $date];
}

/** Order conferences newest first: by `conferenceListDate` or submission
 * deadline, latest first, with conferences that have neither (usually new
 * ones) at the top.
 * @param list<array{id:string,short:string,long:?string,date:?int}> $confs
 * @return list<array{id:string,short:string,long:?string,date:?int}> */
function hotcrp_docker_sort_conferences($confs) {
    usort($confs, function ($a, $b) {
        if ($a["date"] !== $b["date"]) {
            if ($a["date"] === null || $b["date"] === null) {
                return $a["date"] === null ? -1 : 1;
            }
            return $b["date"] <=> $a["date"];
        }
        return strnatcasecmp($a["short"], $b["short"]) ? : strcmp($a["id"], $b["id"]);
    });
    return $confs;
}

/** @return never */
function hotcrp_docker_print_conference_list() {
    global $Opt;
    $ids = hotcrp_docker_conferences();
    $db = null;
    try {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $db = new mysqli("localhost", $Opt["dbUser"] ?? "hotcrp", $Opt["dbPassword"] ?? "");
        $db->set_charset("utf8mb4");
    } catch (Throwable $e) {
        $db = null;
    }
    $h = function ($s) {
        return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, "UTF-8");
    };
    header("Content-Type: text/html; charset=utf-8");
    header("Cache-Control: no-cache");
    echo "<!DOCTYPE html>\n<html lang=\"en\"><head><meta charset=\"utf-8\">",
        "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">",
        "<title>Conferences</title><style>",
        ":root{color-scheme:light dark}",
        "body{font-family:system-ui,sans-serif;max-width:40rem;margin:3rem auto;padding:0 1rem;line-height:1.5}",
        "ul{list-style:none;padding:0}li{margin:.75rem 0}a{font-size:1.15rem;font-weight:600}",
        ".long{display:block;opacity:.75}",
        "</style></head><body><h1>Conferences</h1>\n";
    if (empty($ids)) {
        echo "<p>No conferences have been created yet.</p>\n";
    } else {
        $confs = [];
        foreach ($ids as $id) {
            [$short, $long, $date] = hotcrp_docker_conference_info($id, $db);
            $confs[] = ["id" => $id, "short" => $short, "long" => $long, "date" => $date];
        }
        echo "<ul>\n";
        foreach (hotcrp_docker_sort_conferences($confs) as $c) {
            [$id, $short, $long] = [$c["id"], $c["short"], $c["long"]];
            echo "<li><a href=\"/", rawurlencode($id), "/\">", $h($short), "</a>";
            if ($long !== null && $long !== "" && $long !== $short) {
                echo "<span class=\"long\">", $h($long), "</span>";
            }
            echo "</li>\n";
        }
        echo "</ul>\n";
    }
    echo "</body></html>\n";
    exit(0);
}

/** @param string $what
 * @param Qrequest $qreq */
function hotcrp_docker_multiconference_failure($what, $qreq) {
    global $Opt;
    if ($what !== "options") {
        return;
    }
    $confid = $Opt["confid"] ?? "__unspecified__";
    $default = getenv("HOTCRP_DEFAULT_CONFERENCE");
    if ($default !== false
        && $default !== ""
        && in_array($default, hotcrp_docker_conferences(), true)) {
        // Send `/`, and URLs from before the site served several conferences
        // (e.g. `/paper/1`), to the default conference
        $uri = $_SERVER["REQUEST_URI"] ?? "/";
        if (!str_starts_with($uri, "/")) {
            $uri = "/";
        }
        header("Location: /" . rawurlencode($default) . $uri, true, 302);
        exit(0);
    }
    if ($confid === "__unspecified__") {
        hotcrp_docker_print_conference_list();
    }
    // Otherwise HotCRP reports that the conference does not exist
}
