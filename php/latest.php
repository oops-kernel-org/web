<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1">
	<title>Linux Kernel Oops Service &#124; oops collector api</title>
	
	<style type="text/css">
		p { font-family: courier, monospace; font-size: 10pt; }  
	</style>
	
	<script type="text/javascript">
(function(i,s,o,g,r,a,m){i['GoogleAnalyticsObject']=r;i[r]=i[r]||function(){
(i[r].q=i[r].q||[]).push(arguments)},i[r].l=1*new Date();a=s.createElement(o),
m=s.getElementsByTagName(o)[0];a.async=1;a.src=g;m.parentNode.insertBefore(a,m)
})(window,document,'script','//www.google-analytics.com/analytics.js','ga');

ga('create', 'UA-32925885-1', 'kernel.org');
ga('send', 'pageview');
	</script>
</head>
<body>
	<?php
	$mysql["host"] = getenv('OPENSHIFT_MYSQL_DB_HOST'). ':' .getenv('OPENSHIFT_MYSQL_DB_PORT');
	$mysql["name"] = getenv('OPENSHIFT_MYSQL_DB_USERNAME');
	$mysql["pass"] = getenv('OPENSHIFT_MYSQL_DB_PASSWORD');
	
	$link = mysql_connect($mysql["host"], $mysql["name"], $mysql["pass"]);
	if (!$link) {
		die('Not connected : ' . mysql_error());
	}

	$db_selected = mysql_select_db('kerneloops', $link);
	if (!$db_selected) {
		die ('Can\'t use database : ' . mysql_error());
	}

	$result = mysql_query("SELECT raw FROM raw_data ORDER BY id DESC LIMIT 1");
	if (!$result)
		die('Invalid query: ' . mysql_error());
	$row = mysql_fetch_array($result);
	echo "<pre>";
	print_r($row["raw"]);
	echo "</pre>";
	?>
</body>
</html>
