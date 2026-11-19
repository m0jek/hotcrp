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

/** @param string $id
 * @return array{string,?string} */
function hotcrp_docker_conference_names($id, ?mysqli $db) {
    // Names set on the Settings page are stored in the database ...
    $names = [null, null];
    if ($db) {
        try {
            $result = $db->query("select name, data from `{$id}`.Settings where name in ('opt.shortName', 'opt.longName')");
            while (($row = $result->fetch_row())) {
                $names[$row[0] === "opt.shortName" ? 0 : 1] = $row[1];
            }
        } catch (Throwable $e) {
        }
    }
    // ... and otherwise come from the conference's options file
    if ($names[0] === null || $names[1] === null) {
        $read = function ($file) {
            $Opt = [];
            @include $file;
            return $Opt;
        };
        $opt = $read("/data/conferences/{$id}.php");
        $names[0] = $names[0] ?? $opt["shortName"] ?? null;
        $names[1] = $names[1] ?? $opt["longName"] ?? null;
    }
    return [$names[0] ?? $id, $names[1]];
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
        echo "<ul>\n";
        foreach ($ids as $id) {
            [$short, $long] = hotcrp_docker_conference_names($id, $db);
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
