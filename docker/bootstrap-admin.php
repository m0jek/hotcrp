<?php
// bootstrap-admin.php -- create the initial administrator account for the
// all-in-one Docker image. Reads HOTCRP_ADMIN_EMAIL and (optionally)
// HOTCRP_ADMIN_PASSWORD from the environment. Does nothing if the account
// already exists, so later changes to those variables are ignored.

require_once("/srv/hotcrp/src/init.php");

$email = trim((string) getenv("HOTCRP_ADMIN_EMAIL"));
$password = (string) getenv("HOTCRP_ADMIN_PASSWORD");
$conf = initialize_conf();

if (!validate_email($email)) {
    fwrite(STDERR, "HOTCRP_ADMIN_EMAIL is not a valid email address\n");
    exit(1);
}
if ($conf->fresh_user_by_email($email)) {
    exit(0);
}
if ($password !== "" && ($m = LoginHelper::new_password_problem($password))) {
    fwrite(STDERR, "HOTCRP_ADMIN_PASSWORD: " . Ftext::as(0, $m) . "\n");
    exit(1);
}

$ustatus = new UserStatus($conf->root_user());
$user = $ustatus->save_user((object) ["email" => $email, "roles" => "sysadmin"]);
if (!$user) {
    fwrite(STDERR, $ustatus->full_feedback_text());
    exit(1);
}
if ($password !== "") {
    $user->change_password($password);
}
fwrite(STDERR, "[hotcrp] Created administrator account {$email}"
    . ($password !== "" ? "" : " (no password set)") . "\n");
