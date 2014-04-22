<?php
require "wp-load.php";
ini_set('memory_limit', '1024M');
global $wpdb;
$list = mysql_fetch_array(mysql_query("SELECT meta_value AS rawlist FROM wp_postmeta WHERE meta_key='wpcf-raw-list' AND post_id=" . htmlspecialchars($_GET['id'])));
$token = hash_init("sha256");
hash_update($token, $list["rawlist"]);
$sec_token = hash_final($token);
if ($sec_token != $_GET['token']) {
    echo "Sorry";
    exit();
}
$sql = "SELECT DISTINCT raw FROM raw_data WHERE id IN ( " . $list["rawlist"] . " )";
$query = mysql_query($sql);
while ($row = mysql_fetch_array($query)) {
    echo '<pre id="raw_content">';
    echo htmlspecialchars(ObfuscateRaw($row['raw']));
    echo '</pre>';
}
?>