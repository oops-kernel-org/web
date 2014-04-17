<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1">
	<title>Linux Kernel Oops Service &#124; oops collector api</title>
	
	<style type="text/css">  
		p { font-family: courier, monospace; font-size: 10pt; }  
	</style>

	<script>
(function(i,s,o,g,r,a,m){i['GoogleAnalyticsObject']=r;i[r]=i[r]||function(){
(i[r].q=i[r].q||[]).push(arguments)},i[r].l=1*new Date();a=s.createElement(o),
m=s.getElementsByTagName(o)[0];a.async=1;a.src=g;m.parentNode.insertBefore(a,m)
})(window,document,'script','//www.google-analytics.com/analytics.js','ga');

ga('create', 'UA-32925885-1', 'kernel.org');
ga('send', 'pageview');
	</script>
</head>
<body>
	<p>
	<?php
	$mysql["host"] = getenv('OPENSHIFT_MYSQL_DB_HOST') . ':' . getenv('OPENSHIFT_MYSQL_DB_PORT');
	$mysql["name"] = getenv('OPENSHIFT_MYSQL_DB_USERNAME');
	$mysql["pass"] = getenv('OPENSHIFT_MYSQL_DB_PASSWORD');
	
	# drop if no data
	if (!isset($_POST['oopsdata']) || $_POST['oopsdata'] == "")
		echo "204 No kernel oops was reported, empty data.";
	else {
		$link = mysql_connect($mysql["host"], $mysql["name"], $mysql["pass"]);
		if (!$link)
			die('Not connected : ' . mysql_error());

		$db_selected = mysql_select_db(getenv('OPENSHIFT_APP_NAME'), $link);
		if (!$db_selected)
			die('Can\'t use database : ' . mysql_error());

		$remoteaddr = isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : $_SERVER['REMOTE_ADDR'];
		$data = addslashes($_POST['oopsdata']);
		$hash = sha1($remoteaddr);
		$result = mysql_query("INSERT INTO raw_data SET sha1='" . $hash . "', raw='" . $data . "', timestamp=NOW()");
		if (!$result)
			die('Invalid query: ' . mysql_error());

		echo "200 Thank you for submitting the kernel oops information.";
	}
	?>
	</p>
</body>
</html>

