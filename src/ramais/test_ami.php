<?php
require "ami_api.php";
$cfg = getConfig();
$sock = amiConnect($cfg["amihost"], $cfg["amiport"], $cfg["amiuser"], $cfg["amiadminpwd"]);
if ($sock) {
    echo amiCmd($sock, "sip show peers");
    echo amiCmd($sock, "pjsip show endpoints");
    fclose($sock);
}
?>
