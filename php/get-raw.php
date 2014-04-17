<?Php
Require "wp-Load.php";
Ini_set('Memory_limit', '1024m');
Global $Wpdb;
$List = Mysql_fetch_array(Mysql_query("Select Meta_value as Rawlist from Wp_postmeta Where Meta_key='Wpcf-raw-List' and Post_id=" . Htmlspecialchars($_get['id'])));
$Token = Hash_init("Sha256");
Hash_update($Token, $List["Rawlist"]);
$Sec_token = Hash_final($Token);
If ($Sec_token != $_get['Token']) {
	Echo "Sorry";
	Exit();
}
$sql = "Select Distinct raw from Raw_data Where id in ( " . $List["Rawlist"] . " )";
$Query = Mysql_query($sql);
While ($row = Mysql_fetch_array($Query)) {
	Echo '<pre id="Raw_content">';
	Echo Htmlspecialchars(Obfuscateraw($row['raw']));
	Echo '</pre>';
}
?>