<?php
require "wp-load.php";
if (!isset($_GET["c"])) die('Bad INPUT!!!');
if ($_GET["c"] == 1) $sql = "SELECT * FROM driver";
if ($_GET["c"] == 2) $sql = "SELECT * FROM module";
if ($_GET["c"] == 3) $sql = "SELECT * FROM file";
if ($_GET["c"] == 4) $sql = "SELECT * FROM function";
$sql.= " WHERE name LIKE '%" . mysql_real_escape_string($_GET["q"]) . "%'";
$query = mysql_query($sql);
$output = "";
while ($row = mysql_fetch_array($query)) echo $row["name"] . "\n";
?>
