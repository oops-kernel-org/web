<?php
$baselimit = 20000;
require "../wp-load.php";
ini_set('memory_limit', '1024M');
global $wpdb;
$handle = fopen($oopscfg["safeout"]["dir"] . $oopscfg["safeout"]["rawexport"], "w");
fwrite($handle, '<?xml version="1.0"?>' . "\n");
fwrite($handle, '<rawlist>' . "\n");
$result = True;
$currentlimit = 0;
$current = 0;
while($result) {
	$sql = "SELECT id, raw, timestamp FROM raw_data WHERE status=1 OR status=0 ORDER BY id ASC LIMIT " . $currentlimit . ", " . $baselimit;
	$query = mysql_query($sql);
	$result = mysql_num_rows ( $query );
	$currentlimit += $baselimit;
	$out = "";
	while ($row = mysql_fetch_array($query)) {
		$out.= '<dump>' . "\n";
		$out.= ' <id>' . $row['id'] . '</id>' . "\n";
		$out.= ' <stamp>' . $row['timestamp'] . '</stamp>' . "\n";
		$out.= ' <raw>' . htmlspecialchars(ObfuscateRaw($row["raw"])) . '</raw>' . "\n";
		$out.= '</dump>' . "\n";
	}
	if ($out != "") fwrite($handle, $out);
}
if ($out != "") fwrite($handle, $out);
fwrite($handle, '</rawlist>');
fclose($handle);
?>